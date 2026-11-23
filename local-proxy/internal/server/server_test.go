package server

import (
	"context"
	"encoding/json"
	"errors"
	"net"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/sprust/slogger-laravel/local-proxy/internal/protocol"
	"github.com/sprust/slogger-laravel/local-proxy/internal/storage"
	"github.com/sprust/slogger-laravel/local-proxy/internal/tracepayload"
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

func TestRejectsUnsendableOperationBeforeAppendAndAcceptsNextClient(t *testing.T) {
	dir := t.TempDir()
	store, err := storage.NewStore(dir, nil)
	if err != nil {
		t.Fatal(err)
	}
	defer store.Close()

	path := SocketPath(dir)
	server := New(path, store, &serverLogger{})
	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan error, 1)
	go func() { done <- server.Run(ctx) }()
	defer func() {
		cancel()
		<-done
	}()
	waitForSocket(t, path)

	handshake := func(conn net.Conn) error {
		if err := protocol.WriteFrame(conn, []byte(`{"t":"token"}`)); err != nil {
			return err
		}

		response, err := protocol.ReadFrame(conn, 10)
		if err != nil {
			return err
		}
		if string(response) != "ok" {
			return errors.New("unexpected handshake response")
		}

		return nil
	}

	operation, err := json.Marshal([]map[string]string{{"data": strings.Repeat("a", tracepayload.MaxBatchSize)}})
	if err != nil {
		t.Fatal(err)
	}
	invalid, err := json.Marshal(map[string]string{"c": string(operation)})
	if err != nil {
		t.Fatal(err)
	}
	if len(invalid) >= protocol.MaxFrameSize {
		t.Fatal("test frame exceeds ingestion limit")
	}

	for _, frame := range [][]byte{invalid, []byte("null"), []byte(`{"creates":"[{}]"}`)} {
		first, err := net.Dial("unix", path)
		if err != nil {
			t.Fatal(err)
		}
		defer first.Close()
		if err := handshake(first); err != nil {
			t.Fatal(err)
		}
		if err := protocol.WriteFrame(first, frame); err != nil {
			t.Fatal(err)
		}
		if _, err := protocol.ReadFrame(first, 32); err == nil {
			t.Fatal("unsendable operation received ACK")
		}

	}
	files, err := storage.ListTraceFiles(dir)
	if err != nil {
		t.Fatal(err)
	}
	if len(files) != 0 {
		t.Fatalf("invalid payload was appended: %v", files)
	}

	second, err := net.Dial("unix", path)
	if err != nil {
		t.Fatal(err)
	}
	defer second.Close()
	if err := handshake(second); err != nil {
		t.Fatal(err)
	}

	valid := []byte(`{"c":"[{\"tid\":\"valid\"}]"}`)
	if err := protocol.WriteFrame(second, valid); err != nil {
		t.Fatal(err)
	}
	response, err := protocol.ReadFrame(second, 32)
	if err != nil || string(response) != "received" {
		t.Fatalf("valid response = %q, error = %v", response, err)
	}

	files, err = storage.ListTraceFiles(dir)
	if err != nil || len(files) != 1 {
		t.Fatalf("files = %v, error = %v", files, err)
	}
	file, err := os.Open(filepath.Join(dir, files[0]))
	if err != nil {
		t.Fatal(err)
	}
	defer file.Close()

	record, complete, err := storage.ReadRecord(file, 0)
	if err != nil || !complete || string(record.Payload) != string(valid) {
		t.Fatalf("record = %+v, complete = %v, error = %v", record, complete, err)
	}
}

func TestShutdownDrainsQueuedAppendAndSendsAck(t *testing.T) {
	dir := t.TempDir()
	store := &blockingStore{
		entered: make(chan struct{}),
		release: make(chan struct{}),
	}
	path := SocketPath(dir)
	server := New(path, store, &serverLogger{})
	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan error, 1)
	go func() { done <- server.Run(ctx) }()
	waitForSocket(t, path)

	conn, err := net.Dial("unix", path)
	if err != nil {
		t.Fatal(err)
	}
	defer conn.Close()

	if err := protocol.WriteFrame(conn, []byte(`{"t":"token"}`)); err != nil {
		t.Fatal(err)
	}
	response, err := protocol.ReadFrame(conn, 10)
	if err != nil || string(response) != "ok" {
		t.Fatalf("handshake response = %q, error = %v", response, err)
	}

	if err := protocol.WriteFrame(conn, []byte(`{"c":"[]"}`)); err != nil {
		t.Fatal(err)
	}

	select {
	case <-store.entered:
	case <-time.After(time.Second):
		t.Fatal("Append was not reached")
	}
	cancel()

	_ = conn.SetReadDeadline(time.Now().Add(50 * time.Millisecond))
	if _, err := protocol.ReadFrame(conn, 32); err == nil {
		t.Fatal("ACK arrived before Append was released")
	}

	close(store.release)
	_ = conn.SetReadDeadline(time.Now().Add(time.Second))
	response, err = protocol.ReadFrame(conn, 32)
	if err != nil || string(response) != "received" {
		t.Fatalf("drain response = %q, error = %v", response, err)
	}

	select {
	case err := <-done:
		if !errors.Is(err, context.Canceled) {
			t.Fatalf("server error = %v", err)
		}
	case <-time.After(time.Second):
		t.Fatal("server did not finish after draining the queued append")
	}
}

func TestAcceptExitAfterQueuedAckCancelsBeforeNextRead(t *testing.T) {
	dir := t.TempDir()
	store := &blockingStore{
		entered: make(chan struct{}),
		release: make(chan struct{}),
	}
	path := SocketPath(dir)
	server := New(path, store, &serverLogger{})
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	done := make(chan error, 1)
	go func() { done <- server.Run(ctx) }()
	waitForSocket(t, path)

	conn, err := net.Dial("unix", path)
	if err != nil {
		t.Fatal(err)
	}
	defer conn.Close()

	if err := protocol.WriteFrame(conn, []byte(`{"t":"token"}`)); err != nil {
		t.Fatal(err)
	}
	response, err := protocol.ReadFrame(conn, 10)
	if err != nil || string(response) != "ok" {
		t.Fatalf("handshake response = %q, error = %v", response, err)
	}

	if err := protocol.WriteFrame(conn, []byte(`{"c":"[]"}`)); err != nil {
		t.Fatal(err)
	}
	select {
	case <-store.entered:
	case <-time.After(time.Second):
		t.Fatal("Append was not reached")
	}

	if err := server.listener.Close(); err != nil {
		t.Fatal(err)
	}
	close(store.release)

	_ = conn.SetReadDeadline(time.Now().Add(time.Second))
	response, err = protocol.ReadFrame(conn, 32)
	if err != nil || string(response) != "received" {
		t.Fatalf("drain response = %q, error = %v", response, err)
	}

	select {
	case <-done:
	case <-time.After(time.Second):
		t.Fatal("server waited for another frame after Accept exited")
	}
}

func TestSaturatedServerRejectsHandshakeAndRecoversAfterSlotRelease(t *testing.T) {
	dir := t.TempDir()
	store, err := storage.NewStore(dir, nil)
	if err != nil {
		t.Fatal(err)
	}
	defer store.Close()

	path := SocketPath(dir)
	server := New(path, store, &serverLogger{})
	server.slots = make(chan struct{}, 1)
	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan error, 1)
	go func() { done <- server.Run(ctx) }()
	defer func() {
		cancel()
		<-done
	}()
	waitForSocket(t, path)

	handshake := func(conn net.Conn) error {
		_ = conn.SetDeadline(time.Now().Add(150 * time.Millisecond))
		if err := protocol.WriteFrame(conn, []byte(`{"t":"token"}`)); err != nil {
			return err
		}

		_, err := protocol.ReadFrame(conn, 32)

		return err
	}

	first, err := net.Dial("unix", path)
	if err != nil {
		t.Fatal(err)
	}
	defer first.Close()
	if err := handshake(first); err != nil {
		t.Fatal(err)
	}

	second, err := net.Dial("unix", path)
	if err != nil {
		t.Fatal(err)
	}
	defer second.Close()
	if err := handshake(second); err == nil {
		t.Fatal("saturated server completed a handshake instead of rejecting it")
	}

	if err := first.Close(); err != nil {
		t.Fatal(err)
	}

	deadline := time.Now().Add(2 * time.Second)
	for {
		third, err := net.Dial("unix", path)
		if err == nil {
			err = handshake(third)
		}
		if err == nil {
			third.Close()
			break
		}
		if third != nil {
			third.Close()
		}
		if time.Now().After(deadline) {
			t.Fatalf("handshake did not recover after slot release: %v", err)
		}

		time.Sleep(10 * time.Millisecond)
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

type blockingStore struct {
	entered chan struct{}
	release chan struct{}
}

func (s *blockingStore) Append([]byte) error {
	close(s.entered)
	<-s.release

	return nil
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

func TestWriterContinuesAfterSpoolFull(t *testing.T) {
	dir := t.TempDir()
	payload := []byte(`{"c":"[]"}`)
	store, err := storage.NewStore(dir, nil, int64(len(payload)+4))
	if err != nil {
		t.Fatal(err)
	}
	defer store.Close()

	server := New(SocketPath(dir), store, &serverLogger{})
	writerErrors := make(chan error, 1)
	go server.write(writerErrors)

	appendPayload := func() error {
		request := appendRequest{payload: payload, result: make(chan error, 1)}
		server.requests <- request

		return <-request.result
	}
	if err := appendPayload(); err != nil {
		t.Fatal(err)
	}
	if err := appendPayload(); !errors.Is(err, storage.ErrSpoolFull) {
		t.Fatalf("full append error = %v", err)
	}

	files, err := storage.ListTraceFiles(dir)
	if err != nil || len(files) != 1 {
		t.Fatalf("files = %v, error = %v", files, err)
	}
	if err := store.RemoveSent(files[0]); err != nil {
		t.Fatal(err)
	}
	if err := appendPayload(); err != nil {
		t.Fatalf("writer did not continue after space was freed: %v", err)
	}

	close(server.requests)
	if err := <-writerErrors; err != nil {
		t.Fatal(err)
	}
}
