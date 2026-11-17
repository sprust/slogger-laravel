package sender

import (
	"context"
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"sync"
	"testing"
	"time"

	"github.com/sprust/slogger-laravel/local-proxy/internal/storage"
)

func TestSenderRetriesAndDeletesSealedFile(t *testing.T) {
	now := time.Date(2026, 11, 17, 13, 0, 0, 0, time.Local)
	dir := t.TempDir()
	store, err := storage.NewStore(dir, func() time.Time { return now })
	if err != nil {
		t.Fatal(err)
	}

	if err := store.Append([]byte(`{"c":"[{\"tid\":\"trace-1\"}]"}`)); err != nil {
		t.Fatal(err)
	}

	if err := store.Close(); err != nil {
		t.Fatal(err)
	}

	now = now.Add(time.Hour)

	logger := &recordingLogger{}
	upstream := &recordingUpstream{failures: 1}
	sender, err := New(dir, "tcp://127.0.0.1:9002", "token", 24, func() time.Time { return now }, logger, store)
	if err != nil {
		t.Fatal(err)
	}

	sender.upstream = upstream
	sender.batchDelay = time.Millisecond
	sender.retryDelay = 5 * time.Millisecond

	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan error, 1)
	go func() { done <- sender.Run(ctx) }()

	waitFor(t, func() bool {
		_, err := os.Stat(filepath.Join(dir, "2026_11_17_13"))
		return os.IsNotExist(err)
	})

	cancel()
	if err := <-done; !errors.Is(err, context.Canceled) {
		t.Fatalf("sender error = %v", err)
	}

	if len(upstream.payloads) != 1 {
		t.Fatalf("payload count = %d", len(upstream.payloads))
	}

	if len(logger.errors) != 1 {
		t.Fatalf("error count = %d", len(logger.errors))
	}

	state, err := storage.NewStateStore(dir).Load()
	if err != nil {
		t.Fatal(err)
	}

	if state.File != "" || state.Offset != 0 || state.Completed {
		t.Fatalf("state = %+v", state)
	}
}

func TestSenderSendsAtMost200Operations(t *testing.T) {
	now := time.Date(2026, 11, 17, 13, 0, 0, 0, time.Local)
	dir := t.TempDir()
	store, err := storage.NewStore(dir, func() time.Time { return now })
	if err != nil {
		t.Fatal(err)
	}

	operations := make([]map[string]string, 201)
	for index := range operations {
		operations[index] = map[string]string{"tid": "trace"}
	}

	creating, err := json.Marshal(operations)
	if err != nil {
		t.Fatal(err)
	}

	payload, err := json.Marshal(map[string]string{"c": string(creating)})
	if err != nil {
		t.Fatal(err)
	}

	if err := store.Append(payload); err != nil {
		t.Fatal(err)
	}

	defer store.Close()

	upstream := &recordingUpstream{}
	sender, err := New(dir, "tcp://127.0.0.1:9002", "token", 24, func() time.Time { return now }, &recordingLogger{}, store)
	if err != nil {
		t.Fatal(err)
	}

	sender.upstream = upstream
	sender.batchDelay = time.Millisecond

	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan error, 1)
	go func() { done <- sender.Run(ctx) }()

	waitFor(t, func() bool {
		state, err := storage.NewStateStore(dir).Load()
		return err == nil && state.Offset > 0 && upstream.count() == 2
	})

	cancel()
	<-done

	var first map[string]string
	if err := json.Unmarshal(upstream.payloads[0], &first); err != nil {
		t.Fatal(err)
	}

	var traces []json.RawMessage
	if err := json.Unmarshal([]byte(first["c"]), &traces); err != nil {
		t.Fatal(err)
	}

	if len(traces) != 200 {
		t.Fatalf("first batch operations = %d", len(traces))
	}
}

func TestCleanupDeletesExpiredStateFileAndKeepsActive(t *testing.T) {
	now := time.Date(2026, 11, 17, 14, 0, 0, 0, time.Local)
	dir := t.TempDir()
	for _, name := range []string{"2026_11_17_13", "2026_11_17_14"} {
		if err := os.WriteFile(filepath.Join(dir, name), nil, 0o600); err != nil {
			t.Fatal(err)
		}
	}

	states := storage.NewStateStore(dir)
	if err := states.Save(storage.State{File: "2026_11_17_13"}); err != nil {
		t.Fatal(err)
	}

	sender := &Sender{
		dir:            dir,
		retentionHours: 1,
		now:            func() time.Time { return now },
		logger:         &recordingLogger{},
		states:         states,
		state:          storage.State{Version: storage.StateVersion, File: "2026_11_17_13"},
	}

	if err := sender.cleanup(); err != nil {
		t.Fatal(err)
	}

	if _, err := os.Stat(filepath.Join(dir, "2026_11_17_13")); !os.IsNotExist(err) {
		t.Fatalf("expired file still exists: %v", err)
	}

	if _, err := os.Stat(filepath.Join(dir, "2026_11_17_14")); err != nil {
		t.Fatalf("active file was removed: %v", err)
	}

	state, err := states.Load()
	if err != nil {
		t.Fatal(err)
	}

	if state.File != "" {
		t.Fatalf("state = %+v", state)
	}
}

func TestSenderRecoversPartialTailWhenWriterSealsFile(t *testing.T) {
	clock := &testClock{now: time.Date(2026, 11, 17, 13, 0, 0, 0, time.Local)}
	dir := t.TempDir()
	store, err := storage.NewStore(dir, clock.Now)
	if err != nil {
		t.Fatal(err)
	}

	defer store.Close()

	if err := store.Append([]byte(`{"c":"[{\"tid\":\"trace-1\"}]"}`)); err != nil {
		t.Fatal(err)
	}

	file, err := os.OpenFile(filepath.Join(dir, "2026_11_17_13"), os.O_WRONLY|os.O_APPEND, 0o600)
	if err != nil {
		t.Fatal(err)
	}
	if _, err := file.Write([]byte{0, 0}); err != nil {
		t.Fatal(err)
	}
	if err := file.Close(); err != nil {
		t.Fatal(err)
	}

	upstream := &recordingUpstream{}
	sender, err := New(dir, "tcp://127.0.0.1:9002", "token", 24, clock.Now, &recordingLogger{}, store)
	if err != nil {
		t.Fatal(err)
	}

	sender.upstream = upstream
	sender.batchDelay = time.Millisecond

	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan error, 1)
	go func() { done <- sender.Run(ctx) }()

	waitFor(t, func() bool { return upstream.count() == 1 })
	clock.Advance(time.Hour)

	if err := store.Append([]byte(`{"c":"[{\"tid\":\"trace-2\"}]"}`)); err != nil {
		t.Fatal(err)
	}

	waitFor(t, func() bool {
		_, err := os.Stat(filepath.Join(dir, "2026_11_17_13"))

		return os.IsNotExist(err) && upstream.count() == 2
	})
	cancel()

	if err := <-done; !errors.Is(err, context.Canceled) {
		t.Fatalf("sender error = %v", err)
	}
}

func TestCleanupContinuesDuringUpstreamRetry(t *testing.T) {
	now := time.Date(2026, 11, 17, 13, 0, 0, 0, time.Local)
	dir := t.TempDir()
	store, err := storage.NewStore(dir, func() time.Time { return now })
	if err != nil {
		t.Fatal(err)
	}

	defer store.Close()

	if err := store.Append([]byte(`{"c":"[{\"tid\":\"trace-1\"}]"}`)); err != nil {
		t.Fatal(err)
	}

	logger := &recordingLogger{}
	upstream := &recordingUpstream{failures: 100}
	sender, err := New(dir, "tcp://127.0.0.1:9002", "token", 1, func() time.Time { return now }, logger, store)
	if err != nil {
		t.Fatal(err)
	}

	sender.upstream = upstream
	sender.batchDelay = time.Millisecond
	sender.retryDelay = 20 * time.Millisecond
	sender.cleanupInterval = 5 * time.Millisecond

	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan error, 1)
	go func() { done <- sender.Run(ctx) }()

	waitFor(t, func() bool { return logger.count() > 0 })

	if err := os.WriteFile(filepath.Join(dir, "2026_11_17_12"), nil, 0o600); err != nil {
		t.Fatal(err)
	}

	waitFor(t, func() bool {
		_, err := os.Stat(filepath.Join(dir, "2026_11_17_12"))

		return os.IsNotExist(err)
	})
	upstream.allow()
	waitFor(t, func() bool { return upstream.count() == 1 })
	cancel()

	if err := <-done; !errors.Is(err, context.Canceled) {
		t.Fatalf("sender error = %v", err)
	}
}

func TestRetentionExpiresRetryingFileDuringSplitRecord(t *testing.T) {
	clock := &testClock{now: time.Date(2026, 11, 17, 13, 0, 0, 0, time.Local)}
	dir := t.TempDir()
	store, err := storage.NewStore(dir, clock.Now)
	if err != nil {
		t.Fatal(err)
	}

	defer store.Close()

	operations := make([]map[string]string, 201)
	for index := range operations {
		operations[index] = map[string]string{"tid": "trace"}
	}

	creating, err := json.Marshal(operations)
	if err != nil {
		t.Fatal(err)
	}

	payload, err := json.Marshal(map[string]string{"c": string(creating)})
	if err != nil {
		t.Fatal(err)
	}

	if err := store.Append(payload); err != nil {
		t.Fatal(err)
	}

	logger := &recordingLogger{}
	upstream := &recordingUpstream{failures: 100}
	sender, err := New(dir, "tcp://127.0.0.1:9002", "token", 1, clock.Now, logger, store)
	if err != nil {
		t.Fatal(err)
	}

	sender.upstream = upstream
	sender.batchDelay = time.Millisecond
	sender.retryDelay = 20 * time.Millisecond
	sender.cleanupInterval = 5 * time.Millisecond

	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan error, 1)
	go func() { done <- sender.Run(ctx) }()

	waitFor(t, func() bool { return logger.count() > 0 })
	clock.Advance(2 * time.Hour)

	if err := store.Append([]byte(`{"c":"[{\"tid\":\"trace-next\"}]"}`)); err != nil {
		t.Fatal(err)
	}

	waitFor(t, func() bool {
		_, err := os.Stat(filepath.Join(dir, "2026_11_17_13"))

		return os.IsNotExist(err)
	})
	upstream.allow()
	waitFor(t, func() bool { return upstream.count() == 1 })
	cancel()

	if err := <-done; !errors.Is(err, context.Canceled) {
		t.Fatalf("sender error = %v", err)
	}

	var envelope map[string]string
	if err := json.Unmarshal(upstream.payloads[0], &envelope); err != nil {
		t.Fatal(err)
	}

	var traces []map[string]string
	if err := json.Unmarshal([]byte(envelope["c"]), &traces); err != nil {
		t.Fatal(err)
	}

	if len(traces) != 1 || traces[0]["tid"] != "trace-next" {
		t.Fatalf("unexpected traces after expiry: %+v", traces)
	}
}

func TestExpiryWithStateSaveFailureStopsRetry(t *testing.T) {
	old := time.Date(2026, 11, 17, 13, 0, 0, 0, time.Local)
	dir := t.TempDir()
	store, err := storage.NewStore(dir, func() time.Time { return old })
	if err != nil {
		t.Fatal(err)
	}

	defer store.Close()

	if err := store.Append([]byte(`{"c":"[{}]"}`)); err != nil {
		t.Fatal(err)
	}
	if err := store.Close(); err != nil {
		t.Fatal(err)
	}

	now := old.Add(2 * time.Hour)
	logger := &recordingLogger{}
	upstream := &recordingUpstream{failures: 1}
	sender, err := New(dir, "tcp://127.0.0.1:9002", "token", 1, func() time.Time { return now }, logger, store)
	if err != nil {
		t.Fatal(err)
	}
	if _, err := sender.ensureFile(); err != nil {
		t.Fatal(err)
	}
	defer sender.closeFile()

	sender.currentBatch = newBatch(0, time.Now())
	sender.currentBatch.add(operation{creating: true, raw: []byte(`{}`)})
	sender.upstream = upstream
	sender.retryDelay = 5 * time.Millisecond

	statePath := filepath.Join(dir, storage.StateFileName)
	if err := os.Remove(statePath); err != nil {
		t.Fatal(err)
	}
	if err := os.Mkdir(statePath, 0o700); err != nil {
		t.Fatal(err)
	}

	cleanup := make(chan time.Time, 1)
	cleanup <- time.Now()

	err = sender.flush(context.Background(), cleanup)
	if !errors.Is(err, errRetryFileExpired) {
		t.Fatalf("expected retry expiry with storage error, got %v", err)
	}
	if upstream.count() != 0 {
		t.Fatal("expired payload was sent again after state save failure")
	}
	if _, err := os.Stat(filepath.Join(dir, "2026_11_17_13")); !os.IsNotExist(err) {
		t.Fatalf("expired file still exists: %v", err)
	}
	if logger.count() != 2 {
		t.Fatalf("expected upstream and cleanup errors, got %d", logger.count())
	}
}

type testClock struct {
	mu  sync.Mutex
	now time.Time
}

func (c *testClock) Now() time.Time {
	c.mu.Lock()
	defer c.mu.Unlock()

	return c.now
}

func (c *testClock) Advance(duration time.Duration) {
	c.mu.Lock()
	defer c.mu.Unlock()

	c.now = c.now.Add(duration)
}

type recordingUpstream struct {
	mu       sync.Mutex
	failures int
	payloads [][]byte
}

func (u *recordingUpstream) send(ctx context.Context, payload []byte) error {
	u.mu.Lock()
	defer u.mu.Unlock()

	if u.failures > 0 {
		u.failures--

		return errors.New("upstream unavailable")
	}

	u.payloads = append(u.payloads, append([]byte(nil), payload...))

	return nil
}

func (u *recordingUpstream) close() {}

func (u *recordingUpstream) count() int {
	u.mu.Lock()
	defer u.mu.Unlock()

	return len(u.payloads)
}

func (u *recordingUpstream) allow() {
	u.mu.Lock()
	defer u.mu.Unlock()

	u.failures = 0
}

type recordingLogger struct {
	mu     sync.Mutex
	errors []string
}

func (l *recordingLogger) Info(string, ...any) {}

func (l *recordingLogger) Error(message string, _ ...any) {
	l.mu.Lock()
	defer l.mu.Unlock()

	l.errors = append(l.errors, message)
}

func (l *recordingLogger) Cleanup() error { return nil }

func (l *recordingLogger) count() int {
	l.mu.Lock()
	defer l.mu.Unlock()

	return len(l.errors)
}

func waitFor(t *testing.T, condition func() bool) {
	t.Helper()

	deadline := time.Now().Add(5 * time.Second)

	for time.Now().Before(deadline) {
		if condition() {
			return
		}

		time.Sleep(5 * time.Millisecond)
	}

	t.Fatal("condition was not met before timeout")
}
