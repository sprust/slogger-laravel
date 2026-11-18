package storage

import (
	"encoding/binary"
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
	SocketFileName = "local-proxy.sock"
	LockFileName   = "local-proxy.lock"
)

var traceFileNamePattern = regexp.MustCompile(`^\d{4}_\d{2}_\d{2}_\d{2}$`)

type Store struct {
	mu     sync.Mutex
	dir    string
	now    func() time.Time
	file   *os.File
	hour   string
	notify chan struct{}

	appendedRecords atomic.Uint64
	appendedBytes   atomic.Uint64
}

type Metrics struct {
	AppendedRecords uint64
	AppendedBytes   uint64
}

func NewStore(dir string, now func() time.Time) (*Store, error) {
	if now == nil {
		now = time.Now
	}

	if err := os.MkdirAll(dir, 0o700); err != nil {
		return nil, fmt.Errorf("create traces directory: %w", err)
	}

	return &Store{dir: dir, now: now, notify: make(chan struct{}, 1)}, nil
}

func (s *Store) Append(payload []byte) error {
	s.mu.Lock()
	defer s.mu.Unlock()

	if len(payload) == 0 || len(payload) > protocol.MaxFrameSize {
		return fmt.Errorf("invalid payload size %d", len(payload))
	}

	hour := CurrentFileName(s.now())
	if s.file == nil || s.hour != hour {
		if err := s.closeFile(); err != nil {
			return err
		}

		if err := s.openFile(hour); err != nil {
			return err
		}
	}

	record := make([]byte, 4+len(payload))
	binary.BigEndian.PutUint32(record[:4], uint32(len(payload)))
	copy(record[4:], payload)

	if err := protocol.WriteAll(s.file, record); err != nil {
		return fmt.Errorf("append trace record: %w", err)
	}

	if err := s.file.Sync(); err != nil {
		return fmt.Errorf("sync trace file: %w", err)
	}

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

	if s.file != nil && s.hour == name {
		return false
	}

	return name < CurrentFileName(s.now())
}

func (s *Store) Metrics() Metrics {
	return Metrics{
		AppendedRecords: s.appendedRecords.Load(),
		AppendedBytes:   s.appendedBytes.Load(),
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

func CurrentFileName(now time.Time) string {
	return now.Format("2006_01_02_15")
}

func ValidTraceFileName(name string) bool {
	return traceFileNamePattern.MatchString(name)
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
