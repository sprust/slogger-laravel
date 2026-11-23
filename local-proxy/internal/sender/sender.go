package sender

import (
	"context"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"slices"
	"sync/atomic"
	"time"

	"github.com/sprust/slogger-laravel/local-proxy/internal/storage"
	"github.com/sprust/slogger-laravel/local-proxy/internal/tracepayload"
)

type Logger interface {
	Info(message string, arguments ...any)
	Error(message string, arguments ...any)
	Cleanup() error
}

type traceUpstream interface {
	send(ctx context.Context, payload []byte) error
	close()
}

type Writer interface {
	Notify() <-chan struct{}
	IsSealed(name string) bool
	CompleteFile(name string, offset int64) (bool, error)
	RefreshFileSize(name string) error
	RemoveSent(name string) error
	RemoveExpired(name, observedHour string) error
}

var errRetryFileExpired = errors.New("retrying trace file expired")

type Metrics struct {
	SentBatches        uint64
	SentOperations     uint64
	SentBytes          uint64
	FailedSendAttempts uint64
}

type Sender struct {
	dir             string
	retentionHours  int
	now             func() time.Time
	logger          Logger
	states          *storage.StateStore
	writer          Writer
	notify          <-chan struct{}
	upstream        traceUpstream
	batchDelay      time.Duration
	retryDelay      time.Duration
	cleanupInterval time.Duration

	state         storage.State
	file          *os.File
	readerOffset  int64
	durableOffset int64
	currentBatch  *batch
	recoveredFile string

	sentBatches        atomic.Uint64
	sentOperations     atomic.Uint64
	sentBytes          atomic.Uint64
	failedSendAttempts atomic.Uint64
}

func New(
	dir,
	upstreamURL,
	token string,
	retentionHours int,
	now func() time.Time,
	logger Logger,
	writer Writer,
) (*Sender, error) {
	upstreamClient, err := newUpstream(upstreamURL, token)
	if err != nil {
		return nil, err
	}

	if now == nil {
		now = time.Now
	}

	return &Sender{
		dir:             dir,
		retentionHours:  retentionHours,
		now:             now,
		logger:          logger,
		states:          storage.NewStateStore(dir),
		writer:          writer,
		notify:          writer.Notify(),
		upstream:        upstreamClient,
		batchDelay:      time.Second,
		retryDelay:      RetryDelay,
		cleanupInterval: 10 * time.Minute,
	}, nil
}

func (s *Sender) Metrics() Metrics {
	return Metrics{
		SentBatches:        s.sentBatches.Load(),
		SentOperations:     s.sentOperations.Load(),
		SentBytes:          s.sentBytes.Load(),
		FailedSendAttempts: s.failedSendAttempts.Load(),
	}
}

func (s *Sender) Run(ctx context.Context) error {
	state, err := s.states.Load()
	if err != nil {
		return err
	}

	s.state = state
	s.durableOffset = state.Offset

	_ = s.cleanupAndLog()

	cleanupTicker := time.NewTicker(s.cleanupInterval)
	cleanup := cleanupTicker.C
	defer cleanupTicker.Stop()
	defer func() { _ = s.closeFile() }()
	defer s.upstream.close()

	for {
		if err := ctx.Err(); err != nil {
			return err
		}

		if s.state.Completed {
			if err := s.finishCompletedFile(); err != nil {
				return err
			}

			continue
		}

		opened, err := s.ensureFile()
		if err != nil {
			return err
		}

		if opened {
			if err := s.readAvailable(ctx, cleanup); err != nil {
				if errors.Is(err, errRetryFileExpired) {
					continue
				}

				return err
			}

			if err := ctx.Err(); err != nil {
				return err
			}
		}

		if s.file != nil && s.currentBatch == nil {
			finished, err := s.finishIfComplete()
			if err != nil {
				return err
			}

			if finished {
				continue
			}
		}

		flushed, err := s.flushDue(ctx, cleanup)
		if err != nil {
			if errors.Is(err, errRetryFileExpired) {
				continue
			}

			return err
		}
		if flushed {
			continue
		}

		if err := s.wait(ctx, cleanup); err != nil {
			return err
		}
	}
}

func (s *Sender) flushDue(ctx context.Context, cleanup <-chan time.Time) (bool, error) {
	if s.currentBatch == nil || time.Since(s.currentBatch.startedAt) < s.batchDelay {
		return false, nil
	}

	return true, s.flush(ctx, cleanup)
}

func (s *Sender) batchWait() time.Duration {
	if s.currentBatch == nil {
		return s.batchDelay
	}

	return max(0, s.batchDelay-time.Since(s.currentBatch.startedAt))
}

func (s *Sender) wait(ctx context.Context, cleanup <-chan time.Time) error {
	timer := time.NewTimer(s.batchWait())
	defer timer.Stop()

	select {
	case <-ctx.Done():
		return ctx.Err()
	case <-s.notify:
		return nil
	case <-cleanup:
		_ = s.cleanupAndLog()

		return nil
	case <-timer.C:
		return nil
	}
}

func (s *Sender) readAvailable(ctx context.Context, cleanup <-chan time.Time) error {
	if err := s.cleanupCheckpoint(cleanup); err != nil {
		return err
	}

	if err := s.recoverOnSeal(); err != nil {
		return err
	}

	for {
		if err := s.cleanupCheckpoint(cleanup); err != nil {
			return err
		}

		if s.file == nil {
			return nil
		}

		record, complete, err := storage.ReadRecord(s.file, s.readerOffset)
		if err != nil {
			return fmt.Errorf("read trace file %q: %w", s.state.File, err)
		}

		if !complete {
			return nil
		}

		err = tracepayload.WalkOperations(record.Payload, func(operation operation) error {
			if s.currentBatch == nil {
				s.currentBatch = newBatch(s.durableOffset, time.Now())
			}

			if !s.currentBatch.canAdd(operation) {
				if s.currentBatch.operations == 0 {
					return fmt.Errorf("single trace operation exceeds 4 MiB at offset %d", s.readerOffset)
				}

				if err := s.flush(ctx, cleanup); err != nil {
					return err
				}

				s.currentBatch = newBatch(s.durableOffset, time.Now())

				if !s.currentBatch.canAdd(operation) {
					return fmt.Errorf("single trace operation exceeds 4 MiB at offset %d", s.readerOffset)
				}
			}

			s.currentBatch.add(operation)

			if s.currentBatch.operations == MaxBatchOperations {
				return s.flush(ctx, cleanup)
			}

			return nil
		})
		if err != nil {
			return fmt.Errorf("process trace record %q at offset %d: %w", s.state.File, s.readerOffset, err)
		}

		s.readerOffset = record.NextOffset

		if s.currentBatch != nil {
			s.currentBatch.offset = s.readerOffset

			continue
		}

		if err := s.saveOffset(s.readerOffset); err != nil {
			return err
		}
	}
}

func (s *Sender) flush(ctx context.Context, cleanup <-chan time.Time) error {
	if s.currentBatch == nil {
		return nil
	}

	payload, err := s.currentBatch.encode()
	if err != nil {
		return err
	}

	for {
		if err := ctx.Err(); err != nil {
			return err
		}

		err = s.upstream.send(ctx, payload)
		if err == nil {
			break
		}

		s.failedSendAttempts.Add(1)

		s.logger.Error(
			"failed to send traces to SLogger",
			"error", err,
			"file", s.state.File,
			"offset", s.currentBatch.offset,
			"operations", s.currentBatch.operations,
			"bytes", len(payload),
			"retry_in", s.retryDelay.String(),
		)

		if err := s.waitRetry(ctx, cleanup); err != nil {
			return err
		}
	}

	s.sentBatches.Add(1)
	s.sentOperations.Add(uint64(s.currentBatch.operations))
	s.sentBytes.Add(uint64(len(payload)))

	if err := s.saveOffset(s.currentBatch.offset); err != nil {
		return err
	}

	s.logger.Info(
		"sent traces to SLogger",
		"file", s.state.File,
		"offset", s.durableOffset,
		"operations", s.currentBatch.operations,
		"bytes", len(payload),
	)

	s.currentBatch = nil

	return s.cleanupCheckpoint(cleanup)
}

func (s *Sender) cleanupCheckpoint(cleanup <-chan time.Time) error {
	select {
	case <-cleanup:
		return s.cleanupAndLog()
	default:
	}

	return nil
}

func (s *Sender) waitRetry(ctx context.Context, cleanup <-chan time.Time) error {
	timer := time.NewTimer(s.retryDelay)
	defer timer.Stop()

	for {
		select {
		case <-ctx.Done():
			return ctx.Err()
		case <-cleanup:
			if err := s.cleanupAndLog(); err != nil {
				return err
			}
		case <-timer.C:
			return nil
		}
	}
}

func (s *Sender) recoverOnSeal() error {
	if !s.writer.IsSealed(s.state.File) {
		s.recoveredFile = ""

		return nil
	}

	if s.file == nil || s.recoveredFile == s.state.File {
		return nil
	}

	if err := s.closeFile(); err != nil {
		return err
	}

	filePath := filepath.Join(s.dir, s.state.File)
	if err := storage.RecoverFile(filePath); err != nil {
		return fmt.Errorf("recover newly sealed trace file %q: %w", s.state.File, err)
	}
	if err := s.writer.RefreshFileSize(s.state.File); err != nil {
		return fmt.Errorf("refresh recovered trace file size %q: %w", s.state.File, err)
	}

	file, err := os.Open(filePath)
	if err != nil {
		return fmt.Errorf("reopen sealed trace file %q: %w", s.state.File, err)
	}

	stat, err := file.Stat()
	if err != nil {
		_ = file.Close()

		return err
	}

	if s.readerOffset > stat.Size() {
		_ = file.Close()

		return fmt.Errorf("sender offset %d exceeds recovered size of %q (%d)", s.readerOffset, s.state.File, stat.Size())
	}

	s.file = file
	s.recoveredFile = s.state.File

	return nil
}

func (s *Sender) ensureFile() (bool, error) {
	if s.file != nil {
		return true, nil
	}

	names, err := storage.ListTraceFiles(s.dir)
	if err != nil {
		return false, err
	}

	if len(names) == 0 {
		return false, nil
	}

	name := s.state.File
	if name == "" || names[0] < name || !contains(names, name) {
		name = names[0]
		s.state = storage.State{Version: storage.StateVersion, File: name}
		s.durableOffset = 0
		s.readerOffset = 0

		if err := s.states.Save(s.state); err != nil {
			return false, err
		}
	} else {
		s.readerOffset = s.state.Offset
		s.durableOffset = s.state.Offset
	}

	if s.writer.IsSealed(name) {
		if err := storage.RecoverFile(filepath.Join(s.dir, name)); err != nil {
			return false, fmt.Errorf("recover sealed trace file %q: %w", name, err)
		}
		if err := s.writer.RefreshFileSize(name); err != nil {
			return false, fmt.Errorf("refresh recovered trace file size %q: %w", name, err)
		}

		s.recoveredFile = name
	} else {
		s.recoveredFile = ""
	}

	file, err := os.Open(filepath.Join(s.dir, name))
	if err != nil {
		return false, fmt.Errorf("open trace file %q: %w", name, err)
	}

	stat, err := file.Stat()
	if err != nil {
		_ = file.Close()

		return false, err
	}

	if s.readerOffset > stat.Size() {
		_ = file.Close()

		return false, fmt.Errorf("sender offset %d exceeds size of %q (%d)", s.readerOffset, name, stat.Size())
	}

	s.file = file

	return true, nil
}

func (s *Sender) finishIfComplete() (bool, error) {
	stat, err := s.file.Stat()
	if err != nil {
		return false, err
	}
	if s.readerOffset != stat.Size() {
		return false, nil
	}

	completed, err := s.writer.CompleteFile(s.state.File, s.readerOffset)
	if err != nil || !completed {
		return false, err
	}
	if err := s.closeFile(); err != nil {
		return false, err
	}

	s.state = storage.State{Version: storage.StateVersion}
	s.readerOffset = 0
	s.durableOffset = 0
	s.recoveredFile = ""

	return true, nil
}

func (s *Sender) finishCompletedFile() error {
	if err := s.closeFile(); err != nil {
		return err
	}

	if err := s.writer.RemoveSent(s.state.File); err != nil && !os.IsNotExist(err) {
		return fmt.Errorf("delete sent trace file %q: %w", s.state.File, err)
	}

	s.state = storage.State{Version: storage.StateVersion}
	s.readerOffset = 0
	s.durableOffset = 0
	s.currentBatch = nil
	s.recoveredFile = ""

	return s.states.Save(s.state)
}

func (s *Sender) saveOffset(offset int64) error {
	s.state.Offset = offset
	if err := s.states.Save(s.state); err != nil {
		return fmt.Errorf("save sender offset: %w", err)
	}

	s.durableOffset = offset

	return nil
}

// Only invalidation interrupts the current send. Other cleanup errors are
// reported even when joined with invalidation.
func (s *Sender) cleanupAndLog() error {
	err := s.cleanup()
	if err != nil && err.Error() != errRetryFileExpired.Error() {
		s.logger.Error("local proxy cleanup failed", "error", err)
	}
	if errors.Is(err, errRetryFileExpired) {
		return errRetryFileExpired
	}

	return nil
}

func (s *Sender) cleanup() error {
	// the error-log cleanup and the trace retention are independent: one
	// undeletable old log must not block the trace backlog cleanup forever
	var cleanupErrors []error

	if err := s.logger.Cleanup(); err != nil {
		cleanupErrors = append(cleanupErrors, fmt.Errorf("cleanup error logs: %w", err))
	}

	names, err := storage.ListTraceFiles(s.dir)
	if err != nil {
		return errors.Join(append(cleanupErrors, err)...)
	}

	now := s.now()
	currentName := storage.CurrentFileName(now)
	retryFileExpired := false

	for _, name := range names {
		if name >= currentName {
			continue
		}

		hour, err := time.ParseInLocation("2006_01_02_15", name, now.Location())
		if err != nil {
			continue
		}

		if now.Before(hour.Add(time.Duration(s.retentionHours) * time.Hour)) {
			continue
		}

		if err := s.writer.RemoveExpired(name, currentName); err != nil && !os.IsNotExist(err) {
			cleanupErrors = append(cleanupErrors, fmt.Errorf("delete expired trace file %q: %w", name, err))

			continue
		}

		s.logger.Info("deleted expired trace file", "file", name)

		if name == s.state.File {
			retryFileExpired = true

			if err := s.closeFile(); err != nil {
				cleanupErrors = append(cleanupErrors, err)
			}

			s.state = storage.State{Version: storage.StateVersion}
			s.readerOffset = 0
			s.durableOffset = 0
			s.currentBatch = nil
			s.recoveredFile = ""

			if err := s.states.Save(s.state); err != nil {
				cleanupErrors = append(cleanupErrors, err)
			}
		}
	}

	if retryFileExpired {
		if len(cleanupErrors) > 0 {
			return errors.Join(append([]error{errRetryFileExpired}, cleanupErrors...)...)
		}

		return errRetryFileExpired
	}

	return errors.Join(cleanupErrors...)
}

func (s *Sender) closeFile() error {
	if s.file == nil {
		return nil
	}

	file := s.file
	s.file = nil

	return file.Close()
}

func contains(values []string, wanted string) bool {
	return slices.Contains(values, wanted)
}
