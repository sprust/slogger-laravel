package sender

import (
	"context"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"time"

	"github.com/sprust/slogger-laravel/local-proxy/internal/storage"
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
}

var errRetryFileExpired = errors.New("retrying trace file expired")

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

func (s *Sender) Run(ctx context.Context) error {
	state, err := s.states.Load()
	if err != nil {
		return err
	}

	s.state = state
	s.durableOffset = state.Offset

	if err := s.cleanup(); err != nil {
		s.logger.Error("local proxy cleanup failed", "error", err)
	}

	cleanupTicker := time.NewTicker(s.cleanupInterval)
	cleanup := cleanupTicker.C
	defer cleanupTicker.Stop()
	defer s.closeFile()
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
			sealed, err := s.finishIfSealed()
			if err != nil {
				return err
			}

			if sealed {
				continue
			}
		}

		wait := s.batchDelay
		if s.currentBatch != nil {
			elapsed := time.Since(s.currentBatch.startedAt)
			if elapsed >= s.batchDelay {
				if err := s.flush(ctx, cleanup); err != nil {
					if errors.Is(err, errRetryFileExpired) {
						continue
					}

					return err
				}

				continue
			}
			wait = s.batchDelay - elapsed
		}

		timer := time.NewTimer(wait)
		select {
		case <-ctx.Done():
			timer.Stop()
			return ctx.Err()
		case <-s.notify:
			timer.Stop()
		case <-cleanupTicker.C:
			timer.Stop()

			if err := s.cleanup(); err != nil && !errors.Is(err, errRetryFileExpired) {
				s.logger.Error("local proxy cleanup failed", "error", err)
			}
		case <-timer.C:
		}
	}
}

func (s *Sender) readAvailable(ctx context.Context, cleanup <-chan time.Time) error {
	if err := s.recoverOnSeal(); err != nil {
		return err
	}

	for {
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

		operations, err := parseRecord(record.Payload)
		if err != nil {
			return fmt.Errorf("parse trace record %q at offset %d: %w", s.state.File, s.readerOffset, err)
		}

		for _, operation := range operations {
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
				if err := s.flush(ctx, cleanup); err != nil {
					return err
				}
			}
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
			if err := s.cleanup(); err != nil {
				if errors.Is(err, errRetryFileExpired) {
					if err != errRetryFileExpired {
						s.logger.Error("local proxy cleanup failed", "error", err)
					}

					return err
				}

				s.logger.Error("local proxy cleanup failed", "error", err)
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

	file, err := os.Open(filePath)
	if err != nil {
		return fmt.Errorf("reopen sealed trace file %q: %w", s.state.File, err)
	}

	stat, err := file.Stat()
	if err != nil {
		file.Close()

		return err
	}

	if s.readerOffset > stat.Size() {
		file.Close()

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
		file.Close()

		return false, err
	}

	if s.readerOffset > stat.Size() {
		file.Close()

		return false, fmt.Errorf("sender offset %d exceeds size of %q (%d)", s.readerOffset, name, stat.Size())
	}

	s.file = file

	return true, nil
}

func (s *Sender) finishIfSealed() (bool, error) {
	if !s.writer.IsSealed(s.state.File) {
		return false, nil
	}

	stat, err := s.file.Stat()
	if err != nil {
		return false, err
	}

	if s.readerOffset != stat.Size() {
		return false, nil
	}

	s.state.Offset = s.readerOffset
	s.state.Completed = true

	if err := s.states.Save(s.state); err != nil {
		return false, err
	}

	return true, nil
}

func (s *Sender) finishCompletedFile() error {
	if err := s.closeFile(); err != nil {
		return err
	}

	if err := os.Remove(filepath.Join(s.dir, s.state.File)); err != nil && !os.IsNotExist(err) {
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

func (s *Sender) cleanup() error {
	if err := s.logger.Cleanup(); err != nil {
		return err
	}

	names, err := storage.ListTraceFiles(s.dir)
	if err != nil {
		return err
	}

	currentName := storage.CurrentFileName(s.now())
	var cleanupErrors []error
	retryFileExpired := false

	for _, name := range names {
		if name == currentName {
			continue
		}

		hour, err := time.ParseInLocation("2006_01_02_15", name, s.now().Location())
		if err != nil {
			continue
		}

		if s.now().Before(hour.Add(time.Duration(s.retentionHours) * time.Hour)) {
			continue
		}

		if err := os.Remove(filepath.Join(s.dir, name)); err != nil && !os.IsNotExist(err) {
			cleanupErrors = append(cleanupErrors, fmt.Errorf("delete expired trace file %q: %w", name, err))
			continue
		}

		s.logger.Info("deleted expired trace file", "file", name)

		if name == s.state.File {
			retryFileExpired = s.currentBatch != nil

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
	for _, value := range values {
		if value == wanted {
			return true
		}
	}

	return false
}
