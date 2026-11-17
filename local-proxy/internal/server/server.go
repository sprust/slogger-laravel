package server

import (
	"context"
	"errors"
	"fmt"
	"io"
	"net"
	"os"
	"path/filepath"
	"sync"
	"time"

	"github.com/sprust/slogger-laravel/local-proxy/internal/protocol"
	"github.com/sprust/slogger-laravel/local-proxy/internal/storage"
)

const (
	localReadTimeout  = 5 * time.Minute
	localWriteTimeout = 20 * time.Second
)

type Logger interface {
	Info(message string, arguments ...any)
	Error(message string, arguments ...any)
}

type Store interface {
	Append(payload []byte) error
}

type appendRequest struct {
	payload []byte
	result  chan error
}

type Server struct {
	socketPath string
	store      Store
	logger     Logger
	requests   chan appendRequest
	done       chan struct{}
	listener   net.Listener
	mu         sync.Mutex
	conns      map[net.Conn]struct{}
	handlers   sync.WaitGroup
}

func New(socketPath string, store Store, logger Logger) *Server {
	return &Server{
		socketPath: socketPath,
		store:      store,
		logger:     logger,
		requests:   make(chan appendRequest, 128),
		done:       make(chan struct{}),
		conns:      make(map[net.Conn]struct{}),
	}
}

func (s *Server) Run(ctx context.Context) error {
	if err := removeStaleSocket(s.socketPath); err != nil {
		return err
	}

	listener, err := net.Listen("unix", s.socketPath)
	if err != nil {
		return fmt.Errorf("listen on Unix socket: %w", err)
	}

	s.listener = listener
	defer os.Remove(s.socketPath)

	if err := os.Chmod(s.socketPath, 0o600); err != nil {
		listener.Close()
		return fmt.Errorf("chmod Unix socket: %w", err)
	}

	s.logger.Info("local proxy listening", "socket", s.socketPath)

	listenerWatchDone := make(chan struct{})
	go func() {
		select {
		case <-ctx.Done():
			_ = listener.Close()
		case <-listenerWatchDone:
		}
	}()
	defer close(listenerWatchDone)

	writerErrors := make(chan error, 1)
	go s.write(writerErrors)

	for {
		conn, err := listener.Accept()
		if err != nil {
			s.closeConnections()
			s.handlers.Wait()
			close(s.requests)

			writerErr := <-writerErrors
			if writerErr != nil {
				return writerErr
			}

			if ctx.Err() != nil {
				return ctx.Err()
			}

			return fmt.Errorf("accept Unix connection: %w", err)
		}

		s.trackConnection(conn, true)
		s.handlers.Add(1)
		go func() {
			defer s.handlers.Done()
			defer s.trackConnection(conn, false)
			defer conn.Close()

			s.handle(ctx, conn)
		}()
	}
}

func (s *Server) write(writerErrors chan<- error) {
	var terminalError error

	for request := range s.requests {
		err := s.store.Append(request.payload)
		request.result <- err
		if err != nil {
			terminalError = fmt.Errorf("durable trace append failed: %w", err)
			s.logger.Error("durable trace append failed", "error", err)

			if s.listener != nil {
				_ = s.listener.Close()
			}

			break
		}
	}

	for request := range s.requests {
		request.result <- terminalError
	}

	close(s.done)

	writerErrors <- terminalError
}

func (s *Server) handle(ctx context.Context, conn net.Conn) {
	if err := conn.SetReadDeadline(time.Now().Add(localReadTimeout)); err != nil {
		return
	}

	if _, err := protocol.ReadFrame(conn, protocol.MaxFrameSize); err != nil {
		return
	}

	if err := conn.SetWriteDeadline(time.Now().Add(localWriteTimeout)); err != nil {
		return
	}

	if err := protocol.WriteFrame(conn, []byte("ok")); err != nil {
		return
	}

	for {
		if err := conn.SetReadDeadline(time.Now().Add(localReadTimeout)); err != nil {
			return
		}

		payload, err := protocol.ReadFrame(conn, protocol.MaxFrameSize)
		if err != nil {
			if !errors.Is(err, io.EOF) && !errors.Is(err, net.ErrClosed) && ctx.Err() == nil {
				s.logger.Error("local proxy client read failed", "error", err)
			}
			return
		}

		request := appendRequest{payload: payload, result: make(chan error, 1)}
		select {
		case s.requests <- request:
		case <-ctx.Done():
			return
		case <-s.done:
			return
		}

		select {
		case err = <-request.result:
			if err != nil {
				return
			}
		case <-ctx.Done():
			return
		case <-s.done:
			return
		}

		if err := conn.SetWriteDeadline(time.Now().Add(localWriteTimeout)); err != nil {
			return
		}

		if err := protocol.WriteFrame(conn, []byte("received")); err != nil {
			return
		}
	}
}

func (s *Server) trackConnection(conn net.Conn, add bool) {
	s.mu.Lock()
	defer s.mu.Unlock()

	if add {
		s.conns[conn] = struct{}{}
	} else {
		delete(s.conns, conn)
	}
}

func (s *Server) closeConnections() {
	if s.listener != nil {
		_ = s.listener.Close()
	}

	s.mu.Lock()
	defer s.mu.Unlock()

	for conn := range s.conns {
		_ = conn.Close()
	}
}

func removeStaleSocket(socketPath string) error {
	info, err := os.Lstat(socketPath)
	if os.IsNotExist(err) {
		return nil
	}

	if err != nil {
		return err
	}

	if info.Mode()&os.ModeSocket == 0 {
		return fmt.Errorf("refusing to remove non-socket file %q", socketPath)
	}

	if err := os.Remove(socketPath); err != nil {
		return fmt.Errorf("remove stale Unix socket: %w", err)
	}

	return nil
}

func SocketPath(tracesDir string) string {
	return filepath.Join(tracesDir, storage.SocketFileName)
}
