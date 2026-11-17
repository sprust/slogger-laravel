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

	logger := &recordingLogger{}
	upstream := &recordingUpstream{failures: 1}
	sender, err := New(dir, "tcp://127.0.0.1:9002", "token", 24, func() time.Time { return now.Add(time.Hour) }, logger, store.Notify())
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
	sender, err := New(dir, "tcp://127.0.0.1:9002", "token", 24, func() time.Time { return now }, &recordingLogger{}, store.Notify())
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
