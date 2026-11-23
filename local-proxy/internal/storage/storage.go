package storage

import (
	"encoding/binary"
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"sync"
	"sync/atomic"
	"time"

	"github.com/sprust/slogger-laravel/local-proxy/internal/protocol"
)

const (
	SocketFileName       = "local-proxy.sock"
	LockFileName         = "local-proxy.lock"
	DefaultMaxSpoolBytes = int64(1 << 30)
)

var ErrSpoolFull = errors.New("local proxy spool is full")

var traceFileNamePattern = regexp.MustCompile(`^\d{4}_\d{2}_\d{2}_\d{2}$`)

type Store struct {
	mu          sync.Mutex
	dir         string
	now         func() time.Time
	file        *os.File
	hour        string
	minimumHour string
	notify      chan struct{}

	maxSpoolBytes int64
	spoolBytes    atomic.Int64
	fileSizes     map[string]int64

	appendedRecords atomic.Uint64
	appendedBytes   atomic.Uint64
}

type Metrics struct {
	AppendedRecords uint64
	AppendedBytes   uint64
	SpoolBytes      int64
	SpoolLimitBytes int64
}

func NewStore(dir string, now func() time.Time, limits ...int64) (*Store, error) {
	if now == nil {
		now = time.Now
	}
	if len(limits) > 1 {
		return nil, fmt.Errorf("expected at most one spool limit")
	}

	maxSpoolBytes := DefaultMaxSpoolBytes
	if len(limits) == 1 {
		maxSpoolBytes = limits[0]
	}
	if maxSpoolBytes <= 0 {
		return nil, fmt.Errorf("spool limit must be positive")
	}

	if err := os.MkdirAll(dir, 0o700); err != nil {
		return nil, fmt.Errorf("create traces directory: %w", err)
	}

	store := &Store{
		dir:           dir,
		now:           now,
		notify:        make(chan struct{}, 1),
		maxSpoolBytes: maxSpoolBytes,
		fileSizes:     make(map[string]int64),
	}
	if err := store.loadSpoolFiles(); err != nil {
		return nil, err
	}
	if err := store.reconcileCompletedFile(); err != nil {
		return nil, err
	}

	hour, err := store.resolveStartupHour()
	if err != nil {
		return nil, err
	}
	store.minimumHour = hour
	return store, nil
}

func (s *Store) loadSpoolFiles() error {
	names, err := ListTraceFiles(s.dir)
	if err != nil {
		return err
	}

	for _, name := range names {
		info, err := os.Stat(filepath.Join(s.dir, name))
		if err != nil {
			return fmt.Errorf("stat trace file %q: %w", name, err)
		}

		s.fileSizes[name] = info.Size()
		s.spoolBytes.Add(info.Size())
	}

	return nil
}

func (s *Store) reconcileCompletedFile() error {
	states := NewStateStore(s.dir)
	state, err := states.Load()
	if err != nil {
		return fmt.Errorf("read sender state: %w", err)
	}
	if !state.Completed {
		return nil
	}

	if err := s.remove(state.File); err != nil && !os.IsNotExist(err) {
		return fmt.Errorf("delete completed trace file %q: %w", state.File, err)
	}

	return states.Save(State{Version: StateVersion})
}

func (s *Store) Append(payload []byte) error {
	s.mu.Lock()
	defer s.mu.Unlock()

	if len(payload) == 0 || len(payload) > protocol.MaxFrameSize {
		return fmt.Errorf("invalid payload size %d", len(payload))
	}

	recordSize := int64(len(payload) + 4)
	hour := s.observeHour(CurrentFileName(s.now()))
	if s.file == nil || s.hour != hour {
		if err := s.closeFile(); err != nil {
			return err
		}

		if err := s.openFile(hour); err != nil {
			return err
		}
	}

	spoolBytes := s.spoolBytes.Load()
	if recordSize > s.maxSpoolBytes-spoolBytes {
		return fmt.Errorf("%w: %d bytes used, %d bytes requested, limit %d", ErrSpoolFull, spoolBytes, recordSize, s.maxSpoolBytes)
	}

	var header [4]byte
	binary.BigEndian.PutUint32(header[:], uint32(len(payload)))

	if err := protocol.WriteAll(s.file, header[:]); err != nil {
		return fmt.Errorf("append trace record header: %w", err)
	}
	if err := protocol.WriteAll(s.file, payload); err != nil {
		return fmt.Errorf("append trace record payload: %w", err)
	}

	if err := s.file.Sync(); err != nil {
		return fmt.Errorf("sync trace file: %w", err)
	}

	s.fileSizes[hour] += recordSize
	s.spoolBytes.Add(recordSize)
	s.appendedRecords.Add(1)
	s.appendedBytes.Add(uint64(len(payload)))

	select {
	case s.notify <- struct{}{}:
	default:
	}

	return nil
}

func (s *Store) Notify() <-chan struct{} {
	return s.notify
}

func (s *Store) IsSealed(name string) bool {
	s.mu.Lock()
	defer s.mu.Unlock()

	hour := s.observeHour(CurrentFileName(s.now()))
	return name < hour && name != s.hour
}

// Only called under mu. Once the sender can seal an hour, no subsequent
// append may reopen it, even if the local clock goes backwards.
func (s *Store) observeHour(hour string) string {
	if hour > s.minimumHour {
		s.minimumHour = hour
	}
	return s.minimumHour
}

// RemoveExpired rotates an idle writer away from the expired file before
// unlinking it. Future appends stay at or past the hour observed by cleanup.
func (s *Store) RemoveExpired(name, observedHour string) error {
	s.mu.Lock()
	defer s.mu.Unlock()
	if name >= s.observeHour(observedHour) {
		return fmt.Errorf("cannot expire current or future hour %q", name)
	}
	if name == s.hour {
		if err := s.closeFile(); err != nil {
			return err
		}
	}
	return s.remove(name)
}

func (s *Store) RefreshFileSize(name string) error {
	s.mu.Lock()
	defer s.mu.Unlock()

	info, err := os.Stat(filepath.Join(s.dir, name))
	if err != nil {
		return err
	}
	s.setFileSize(name, info.Size())

	return nil
}

func (s *Store) setFileSize(name string, size int64) {
	s.spoolBytes.Add(size - s.fileSizes[name])
	s.fileSizes[name] = size
}

func (s *Store) CompleteFile(name string, offset int64) (bool, error) {
	s.mu.Lock()
	defer s.mu.Unlock()

	if offset < 0 || !ValidTraceFileName(name) {
		return false, fmt.Errorf("invalid completed file position %q:%d", name, offset)
	}

	info, err := os.Stat(filepath.Join(s.dir, name))
	if err != nil {
		return false, err
	}
	if info.Size() != offset {
		return false, nil
	}

	if name == s.hour {
		if err := s.closeFile(); err != nil {
			return false, err
		}
	}

	states := NewStateStore(s.dir)
	if err := states.Save(State{File: name, Offset: offset, Completed: true}); err != nil {
		return false, err
	}
	if err := s.remove(name); err != nil && !os.IsNotExist(err) {
		return false, err
	}
	if err := states.Save(State{Version: StateVersion}); err != nil {
		return false, err
	}

	return true, nil
}

func (s *Store) RemoveSent(name string) error {
	s.mu.Lock()
	defer s.mu.Unlock()

	if name == s.hour {
		if err := s.closeFile(); err != nil {
			return err
		}
	}

	return s.remove(name)
}

func (s *Store) remove(name string) error {
	err := os.Remove(filepath.Join(s.dir, name))
	if err != nil && !os.IsNotExist(err) {
		return err
	}

	s.spoolBytes.Add(-s.fileSizes[name])
	delete(s.fileSizes, name)

	return err
}

func (s *Store) Metrics() Metrics {
	return Metrics{
		AppendedRecords: s.appendedRecords.Load(),
		AppendedBytes:   s.appendedBytes.Load(),
		SpoolBytes:      s.spoolBytes.Load(),
		SpoolLimitBytes: s.maxSpoolBytes,
	}
}

func (s *Store) Close() error {
	s.mu.Lock()
	defer s.mu.Unlock()

	return s.closeFile()
}

func (s *Store) openFile(hour string) error {
	filePath := filepath.Join(s.dir, hour)
	_, statErr := os.Stat(filePath)
	created := os.IsNotExist(statErr)

	if statErr != nil && !created {
		return fmt.Errorf("stat trace file %q: %w", filePath, statErr)
	}

	if err := RecoverFile(filePath); err != nil {
		return fmt.Errorf("recover active trace file: %w", err)
	}

	file, err := os.OpenFile(filePath, os.O_CREATE|os.O_WRONLY|os.O_APPEND, 0o600)
	if err != nil {
		return fmt.Errorf("open trace file %q: %w", filePath, err)
	}

	info, err := file.Stat()
	if err != nil {
		file.Close()

		return fmt.Errorf("stat opened trace file %q: %w", filePath, err)
	}
	s.setFileSize(hour, info.Size())

	if created {
		if err := syncDirectory(s.dir); err != nil {
			file.Close()

			return fmt.Errorf("sync traces directory: %w", err)
		}
	}

	s.file = file
	s.hour = hour

	return nil
}

func (s *Store) closeFile() error {
	if s.file == nil {
		return nil
	}

	file := s.file
	s.file = nil
	s.hour = ""

	if err := file.Close(); err != nil {
		return fmt.Errorf("close trace file: %w", err)
	}

	return nil
}

func (s *Store) resolveStartupHour() (string, error) {
	hour := CurrentFileName(s.now())

	names, err := ListTraceFiles(s.dir)
	if err != nil {
		return "", err
	}

	if len(names) > 0 && names[len(names)-1] > hour {
		hour = names[len(names)-1]
	}

	return hour, nil
}

func CurrentFileName(now time.Time) string {
	return now.Format("2006_01_02_15")
}

func ValidTraceFileName(name string) bool {
	if !traceFileNamePattern.MatchString(name) {
		return false
	}

	parsed, err := time.Parse("2006_01_02_15", name)

	return err == nil && parsed.Format("2006_01_02_15") == name
}

func ListTraceFiles(dir string) ([]string, error) {
	entries, err := os.ReadDir(dir)
	if err != nil {
		return nil, fmt.Errorf("read traces directory: %w", err)
	}

	names := make([]string, 0)
	for _, entry := range entries {
		if entry.Type().IsRegular() && ValidTraceFileName(entry.Name()) {
			names = append(names, entry.Name())
		}
	}

	sort.Strings(names)

	return names, nil
}

func RecoverFile(filePath string) error {
	file, err := os.OpenFile(filePath, os.O_RDWR, 0o600)
	if os.IsNotExist(err) {
		return nil
	}

	if err != nil {
		return err
	}
	defer file.Close()

	stat, err := file.Stat()
	if err != nil {
		return err
	}

	size := stat.Size()

	for offset := int64(0); offset < size; {
		remaining := size - offset
		if remaining < 4 {
			return truncate(file, offset)
		}

		var header [4]byte
		if _, err := io.ReadFull(file, header[:]); err != nil {
			return err
		}

		length := int64(binary.BigEndian.Uint32(header[:]))
		if length == 0 || length > protocol.MaxFrameSize {
			return fmt.Errorf("invalid record length at offset %d", offset)
		}

		if length > size-offset-4 {
			return truncate(file, offset)
		}

		if _, err := file.Seek(length, io.SeekCurrent); err != nil {
			return err
		}

		offset += 4 + length
	}

	return nil
}

func truncate(file *os.File, size int64) error {
	if err := file.Truncate(size); err != nil {
		return fmt.Errorf("truncate incomplete record: %w", err)
	}

	return file.Sync()
}

func syncDirectory(path string) error {
	directory, err := os.Open(path)
	if err != nil {
		return err
	}
	defer directory.Close()

	return directory.Sync()
}

type Record struct {
	Payload    []byte
	NextOffset int64
}

func ReadRecord(file *os.File, offset int64) (Record, bool, error) {
	stat, err := file.Stat()
	if err != nil {
		return Record{}, false, err
	}

	if offset < 0 || offset > stat.Size() {
		return Record{}, false, fmt.Errorf("offset %d is outside file size %d", offset, stat.Size())
	}

	if offset == stat.Size() || stat.Size()-offset < 4 {
		return Record{}, false, nil
	}

	var header [4]byte
	if _, err := file.ReadAt(header[:], offset); err != nil {
		return Record{}, false, err
	}

	length := int64(binary.BigEndian.Uint32(header[:]))
	if length == 0 || length > protocol.MaxFrameSize {
		return Record{}, false, fmt.Errorf("invalid record length at offset %d", offset)
	}

	nextOffset := offset + 4 + length
	if nextOffset > stat.Size() {
		return Record{}, false, nil
	}

	payload := make([]byte, int(length))
	if _, err := file.ReadAt(payload, offset+4); err != nil {
		return Record{}, false, err
	}

	return Record{Payload: payload, NextOffset: nextOffset}, true, nil
}
