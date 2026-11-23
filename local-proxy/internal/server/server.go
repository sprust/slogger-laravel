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
	"github.com/sprust/slogger-laravel/local-proxy/internal/tracepayload"
)

const (
	localReadTimeout     = 5 * time.Minute
	localWriteTimeout    = 20 * time.Second
	maxLocalConnections  = 32
	maxPayloadMemorySize = 256 << 20
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
	budget     *memoryBudget
	slots      chan struct{}
	mu         sync.Mutex
	conns      map[net.Conn]struct{}
	idle       map[net.Conn]bool
	handlers   sync.WaitGroup
}

type Metrics struct {
	Connections      int
	IdleConnections  int
	ConnectionSlots  int
	ConnectionLimit  int
	QueuedRecords    int
	QueueCapacity    int
	InputBudgetUsed  int64
	InputBudgetTotal int64
}

func New(socketPath string, store Store, logger Logger) *Server {
	return &Server{
		socketPath: socketPath,
		store:      store,
		logger:     logger,
		requests:   make(chan appendRequest, 128),
		done:       make(chan struct{}),
		budget:     newMemoryBudget(maxPayloadMemorySize),
		slots:      make(chan struct{}, maxLocalConnections),
		conns:      make(map[net.Conn]struct{}),
		idle:       make(map[net.Conn]bool),
	}
}

func (s *Server) Metrics() Metrics {
	s.mu.Lock()
	idleConnections := 0
	for _, idle := range s.idle {
		if idle {
			idleConnections++
		}
	}
	connections := len(s.conns)
	s.mu.Unlock()

	available := s.budget.Available()

	return Metrics{
		Connections:      connections,
		IdleConnections:  idleConnections,
		ConnectionSlots:  len(s.slots),
		ConnectionLimit:  cap(s.slots),
		QueuedRecords:    len(s.requests),
		QueueCapacity:    cap(s.requests),
		InputBudgetUsed:  s.budget.capacity - available,
		InputBudgetTotal: s.budget.capacity,
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

	runCtx, cancel := context.WithCancel(ctx)
	defer cancel()

	listenerWatchDone := make(chan struct{})
	go func() {
		select {
		case <-runCtx.Done():
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
			cancel()
			s.closeIdleConnections()
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

		select {
		case s.slots <- struct{}{}:
		case <-ctx.Done():
			_ = conn.Close()
			_ = listener.Close()

			continue
		default:
			_ = conn.Close()

			continue
		}

		s.trackConnection(conn, true)
		s.handlers.Add(1)
		go func() {
			defer s.handlers.Done()
			defer s.trackConnection(conn, false)
			defer conn.Close()
			defer func() { <-s.slots }()

			s.handle(runCtx, conn)
		}()
	}
}

func (s *Server) write(writerErrors chan<- error) {
	var terminalError error

	for request := range s.requests {
		err := s.store.Append(request.payload)
		request.result <- err
		if errors.Is(err, storage.ErrSpoolFull) {
			continue
		}
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

	readContext, cancel := context.WithTimeout(ctx, localReadTimeout)
	_, release, err := protocol.ReadFrameBudgeted(readContext, conn, protocol.MaxFrameSize, s.budget)
	cancel()

	if err != nil {
		return
	}

	release()

	if err := conn.SetWriteDeadline(time.Now().Add(localWriteTimeout)); err != nil {
		return
	}

	if err := protocol.WriteFrame(conn, []byte("ok")); err != nil {
		return
	}

	for {
		if ctx.Err() != nil {
			return
		}

		if err := s.handleFrame(ctx, conn); err != nil {
			if !errors.Is(err, io.EOF) && !errors.Is(err, net.ErrClosed) && !errors.Is(err, storage.ErrSpoolFull) && ctx.Err() == nil {
				s.logger.Error("local proxy client read failed", "error", err)
			}

			return
		}
	}
}

func (s *Server) handleFrame(ctx context.Context, conn net.Conn) error {
	s.setIdle(conn, true)
	defer s.setIdle(conn, true)

	if err := conn.SetReadDeadline(time.Now().Add(localReadTimeout)); err != nil {
		return err
	}

	readContext, cancel := context.WithTimeout(ctx, localReadTimeout)
	defer cancel()

	payload, release, err := protocol.ReadFrameBudgeted(readContext, conn, protocol.MaxFrameSize, s.budget)
	if err != nil {
		return err
	}

	s.setIdle(conn, false)
	defer release()

	if err := tracepayload.ValidateRecord(payload); err != nil {
		return err
	}

	request := appendRequest{payload: payload, result: make(chan error, 1)}
	select {
	case s.requests <- request:
	case <-ctx.Done():
		return ctx.Err()
	case <-s.done:
		return net.ErrClosed
	}

	select {
	case err = <-request.result:
		if err != nil {
			return err
		}
	case <-s.done:
		return net.ErrClosed
	}

	if err := conn.SetWriteDeadline(time.Now().Add(localWriteTimeout)); err != nil {
		return err
	}

	return protocol.WriteFrame(conn, []byte("received"))
}

func (s *Server) trackConnection(conn net.Conn, add bool) {
	s.mu.Lock()
	defer s.mu.Unlock()

	if add {
		s.conns[conn] = struct{}{}
		s.idle[conn] = true
	} else {
		delete(s.conns, conn)
		delete(s.idle, conn)
	}
}

func (s *Server) setIdle(conn net.Conn, idle bool) {
	s.mu.Lock()
	defer s.mu.Unlock()

	if _, exists := s.conns[conn]; exists {
		s.idle[conn] = idle
	}
}

func (s *Server) closeIdleConnections() {
	if s.listener != nil {
		_ = s.listener.Close()
	}

	s.mu.Lock()
	defer s.mu.Unlock()

	for conn := range s.conns {
		if s.idle[conn] {
			_ = conn.Close()
		}
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
