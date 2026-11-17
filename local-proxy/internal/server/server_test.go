package server

import (
	"context"
	"errors"
	"net"
	"os"
	"testing"
	"time"

	"github.com/sprust/slogger-laravel/local-proxy/internal/protocol"
	"github.com/sprust/slogger-laravel/local-proxy/internal/storage"
)

func TestUnixServerAcceptsPersistentConnection(t *testing.T) {
	dir := t.TempDir()
	store, err := storage.NewStore(dir, nil)
	if err != nil {
		t.Fatal(err)
	}

	defer store.Close()

	socketPath := SocketPath(dir)
	server := New(socketPath, store, &serverLogger{})
	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan error, 1)
	go func() { done <- server.Run(ctx) }()

	waitForSocket(t, socketPath)

	conn, err := net.DialTimeout("unix", socketPath, time.Second)
	if err != nil {
		t.Fatal(err)
	}

	defer conn.Close()

	if err := protocol.WriteFrame(conn, []byte(`{"t":"anything"}`)); err != nil {
		t.Fatal(err)
	}

	response, err := protocol.ReadFrame(conn, 10)
	if err != nil || string(response) != "ok" {
		t.Fatalf("response = %q, error = %v", response, err)
	}

	for _, payload := range []string{`{"c":"[]"}`, `{"u":"[]"}`} {
		if err := protocol.WriteFrame(conn, []byte(payload)); err != nil {
			t.Fatal(err)
		}

		response, err = protocol.ReadFrame(conn, 20)
		if err != nil || string(response) != "received" {
			t.Fatalf("response = %q, error = %v", response, err)
		}
	}

	cancel()

	if err := <-done; !errors.Is(err, context.Canceled) {
		t.Fatalf("server error = %v", err)
	}

	if _, err := os.Stat(socketPath); !os.IsNotExist(err) {
		t.Fatalf("socket still exists: %v", err)
	}
}

func TestRemoveStaleSocketRefusesRegularFile(t *testing.T) {
	path := SocketPath(t.TempDir())
	if err := os.WriteFile(path, []byte("do not remove"), 0o600); err != nil {
		t.Fatal(err)
	}

	if err := removeStaleSocket(path); err == nil {
		t.Fatal("removeStaleSocket unexpectedly succeeded")
	}

	contents, err := os.ReadFile(path)
	if err != nil || string(contents) != "do not remove" {
		t.Fatalf("contents = %q, error = %v", contents, err)
	}
}

type serverLogger struct{}

func (*serverLogger) Info(string, ...any)  {}
func (*serverLogger) Error(string, ...any) {}

func waitForSocket(t *testing.T, path string) {
	t.Helper()

	deadline := time.Now().Add(5 * time.Second)

	for time.Now().Before(deadline) {
		if info, err := os.Stat(path); err == nil && info.Mode()&os.ModeSocket != 0 {
			return
		}

		time.Sleep(5 * time.Millisecond)
	}

	t.Fatal("Unix socket was not created")
}
