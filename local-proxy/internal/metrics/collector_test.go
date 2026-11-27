package metrics

import (
	"context"
	"encoding/json"
	"errors"
	"io/fs"
	"os"
	"path/filepath"
	"testing"
	"time"

	"github.com/sprust/slogger-laravel/local-proxy/internal/sender"
	"github.com/sprust/slogger-laravel/local-proxy/internal/server"
	"github.com/sprust/slogger-laravel/local-proxy/internal/storage"
)

func TestCollectorWritesAtomicJSONSnapshot(t *testing.T) {
	t.Parallel()

	dir := t.TempDir()
	logger := &testLogger{}
	store, err := storage.NewStore(dir, nil)
	if err != nil {
		t.Fatal(err)
	}
	defer func() { _ = store.Close() }()

	if err := store.Append([]byte(`{"c":"[{}]"}`)); err != nil {
		t.Fatal(err)
	}

	localServer := server.New(filepath.Join(dir, storage.SocketFileName), store, logger)
	traceSender, err := sender.New(dir, "tcp://127.0.0.1:9002", "token", 24, nil, logger, store)
	if err != nil {
		t.Fatal(err)
	}
	collector := New(dir, localServer, store, traceSender, logger)

	if err := collector.Write(); err != nil {
		t.Fatal(err)
	}
	if err := collector.Write(); err != nil {
		t.Fatal(err)
	}

	data, err := os.ReadFile(filepath.Join(dir, FileName))
	if err != nil {
		t.Fatal(err)
	}
	var snapshot Snapshot
	if err := json.Unmarshal(data, &snapshot); err != nil {
		t.Fatal(err)
	}

	if snapshot.Process.HeapAllocBytes <= 0 || snapshot.Process.Goroutines <= 0 {
		t.Fatalf("process = %+v", snapshot.Process)
	}
	if snapshot.StartedAt.IsZero() {
		t.Fatal("started_at is missing")
	}
	if snapshot.Storage.SpoolLimitBytes != storage.DefaultMaxSpoolBytes {
		t.Fatalf("storage = %+v", snapshot.Storage)
	}
	if snapshot.Storage.HourlyFiles != 1 || snapshot.Storage.AppendedRecords != 1 {
		t.Fatalf("storage = %+v", snapshot.Storage)
	}
	if snapshot.Storage.HourlyBytes <= 0 {
		t.Fatalf("storage = %+v", snapshot.Storage)
	}
	if snapshot.Server.ConnectionLimit != 32 || snapshot.Server.InputBudgetTotal != 256<<20 {
		t.Fatalf("server = %+v", snapshot.Server)
	}

	info, err := os.Stat(filepath.Join(dir, FileName))
	if err != nil {
		t.Fatal(err)
	}
	if info.Mode().Perm() != 0o600 {
		t.Fatalf("mode = %o", info.Mode().Perm())
	}
}

func TestRunDoesNotPublishFinalSnapshotBeforeWorkersDrain(t *testing.T) {
	t.Parallel()

	collector, store, _, dir := newTestCollector(t)
	collector.interval = time.Hour
	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan error, 1)
	go func() { done <- collector.Run(ctx) }()

	waitForMetricsFile(t, dir)
	if err := store.Append([]byte(`{"c":"[{}]"}`)); err != nil {
		t.Fatal(err)
	}
	cancel()
	if err := <-done; !errors.Is(err, context.Canceled) {
		t.Fatal(err)
	}

	snapshot := readSnapshot(t, dir)
	if snapshot.Storage.AppendedRecords != 0 {
		t.Fatalf("collector wrote a final snapshot itself: %+v", snapshot.Storage)
	}
}

func TestCollectFileMetricsSkipsRemovedAndUsesLoggerNameRule(t *testing.T) {
	t.Parallel()

	entries := []os.DirEntry{
		fakeDirEntry{name: "2026_11_18_13", info: fakeFileInfo{size: 123}},
		fakeDirEntry{name: "2026_11_18_14", err: os.ErrNotExist},
		fakeDirEntry{name: "local-proxy-errors-2026_11_18.logs", info: fakeFileInfo{size: 50}},
		fakeDirEntry{name: "local-proxy-errors-2026_11_18.txt", info: fakeFileInfo{size: 50}},
		fakeDirEntry{name: "directory", mode: fs.ModeDir},
	}

	hourly, bytes, errorLogs, err := collectFileMetrics(entries)
	if err != nil {
		t.Fatal(err)
	}
	if len(hourly) != 1 || hourly[0] != "2026_11_18_13" || bytes != 123 || errorLogs != 1 {
		t.Fatalf("hourly = %v, bytes = %d, error logs = %d", hourly, bytes, errorLogs)
	}
}

func TestContinuousRenameErrorsAreRateLimited(t *testing.T) {
	t.Parallel()

	collector, _, logger, dir := newTestCollector(t)
	if err := os.Mkdir(filepath.Join(dir, FileName), 0o700); err != nil {
		t.Fatal(err)
	}
	now := time.Date(2026, 11, 18, 13, 0, 0, 0, time.UTC)
	collector.now = func() time.Time { return now }

	for range 10 {
		collector.writeAndLog()
		now = now.Add(time.Second)
	}
	if logger.errors != 1 {
		t.Fatalf("errors after repeated rename failures = %d", logger.errors)
	}

	now = now.Add(time.Minute)
	collector.writeAndLog()
	if logger.errors != 2 {
		t.Fatalf("errors after interval = %d", logger.errors)
	}
}

type testLogger struct {
	errors int
}

func (*testLogger) Info(string, ...any) {}
func (l *testLogger) Error(string, ...any) {
	l.errors++
}
func (*testLogger) Cleanup() error { return nil }

type fakeDirEntry struct {
	name string
	info fakeFileInfo
	err  error
	mode fs.FileMode
}

func (e fakeDirEntry) Name() string { return e.name }
func (e fakeDirEntry) IsDir() bool  { return e.mode.IsDir() }
func (e fakeDirEntry) Type() fs.FileMode {
	return e.mode
}
func (e fakeDirEntry) Info() (fs.FileInfo, error) {
	return e.info, e.err
}

type fakeFileInfo struct {
	size int64
}

func (fakeFileInfo) Name() string       { return "fake" }
func (i fakeFileInfo) Size() int64      { return i.size }
func (fakeFileInfo) Mode() fs.FileMode  { return 0 }
func (fakeFileInfo) ModTime() time.Time { return time.Time{} }
func (fakeFileInfo) IsDir() bool        { return false }
func (fakeFileInfo) Sys() any           { return nil }

func newTestCollector(t *testing.T) (*Collector, *storage.Store, *testLogger, string) {
	t.Helper()

	dir := t.TempDir()
	logger := &testLogger{}
	store, err := storage.NewStore(dir, nil)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { _ = store.Close() })

	localServer := server.New(filepath.Join(dir, storage.SocketFileName), store, logger)
	traceSender, err := sender.New(dir, "tcp://127.0.0.1:9002", "token", 24, nil, logger, store)
	if err != nil {
		t.Fatal(err)
	}
	collector := New(dir, localServer, store, traceSender, logger)

	return collector, store, logger, dir
}

func waitForMetricsFile(t *testing.T, dir string) {
	t.Helper()

	deadline := time.Now().Add(time.Second)
	for time.Now().Before(deadline) {
		if _, err := os.Stat(filepath.Join(dir, FileName)); err == nil {
			return
		}
		time.Sleep(time.Millisecond)
	}
	t.Fatal("metrics file was not written")
}

func readSnapshot(t *testing.T, dir string) Snapshot {
	t.Helper()

	data, err := os.ReadFile(filepath.Join(dir, FileName))
	if err != nil {
		t.Fatal(err)
	}
	var snapshot Snapshot
	if err := json.Unmarshal(data, &snapshot); err != nil {
		t.Fatal(err)
	}

	return snapshot
}
