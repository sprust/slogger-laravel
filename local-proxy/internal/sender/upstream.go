package sender

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"net"
	"net/url"
	"time"

	"github.com/sprust/slogger-laravel/local-proxy/internal/protocol"
)

const (
	ConnectTimeout = 10 * time.Second
	WriteTimeout   = 20 * time.Second
	ReadTimeout    = 20 * time.Second
	RetryDelay     = 3 * time.Second
)

type upstream struct {
	address string
	token   string
	conn    net.Conn
}

func newUpstream(rawURL, token string) (*upstream, error) {
	parsed, err := url.Parse(rawURL)
	if err != nil || parsed.Scheme != "tcp" || parsed.Host == "" {
		return nil, errors.New("invalid upstream URL")
	}

	return &upstream{address: parsed.Host, token: token}, nil
}

func (u *upstream) send(ctx context.Context, payload []byte) error {
	newConnection := false
	if u.conn == nil {
		dialer := net.Dialer{Timeout: ConnectTimeout}
		conn, err := dialer.DialContext(ctx, "tcp", u.address)
		if err != nil {
			return fmt.Errorf("connect upstream: %w", err)
		}
		u.conn = conn
		newConnection = true
	}

	conn := u.conn
	watchDone := make(chan struct{})
	go func() {
		select {
		case <-ctx.Done():
			_ = conn.Close()
		case <-watchDone:
		}
	}()
	defer close(watchDone)

	if err := u.exchange(ctx, payload, newConnection); err != nil {
		u.close()

		return err
	}

	return nil
}

func (u *upstream) exchange(ctx context.Context, payload []byte, authenticate bool) error {
	if authenticate {
		if err := u.authenticate(ctx); err != nil {
			return err
		}
	}

	if err := u.conn.SetDeadline(time.Now().Add(WriteTimeout)); err != nil {
		return err
	}

	if err := protocol.WriteFrame(u.conn, payload); err != nil {
		return fmt.Errorf("write upstream traces: %w", err)
	}

	if err := u.conn.SetDeadline(time.Now().Add(ReadTimeout)); err != nil {
		return err
	}

	response, err := protocol.ReadFrameContext(ctx, u.conn, protocol.MaxFrameSize)
	if err != nil {
		return fmt.Errorf("read upstream response: %w", err)
	}

	if string(response) != "received" {
		return errors.New("unexpected upstream response: expected received")
	}

	return nil
}

func (u *upstream) authenticate(ctx context.Context) error {
	if err := u.conn.SetDeadline(time.Now().Add(WriteTimeout)); err != nil {
		return err
	}

	authentication, err := json.Marshal(map[string]string{"t": u.token})
	if err != nil {
		return err
	}

	if err := protocol.WriteFrame(u.conn, authentication); err != nil {
		return fmt.Errorf("write upstream auth: %w", err)
	}

	if err := u.conn.SetDeadline(time.Now().Add(ReadTimeout)); err != nil {
		return err
	}

	response, err := protocol.ReadFrameContext(ctx, u.conn, protocol.MaxFrameSize)
	if err != nil {
		return fmt.Errorf("read upstream auth: %w", err)
	}

	if string(response) != "ok" {
		return errors.New("upstream auth failed: expected ok")
	}

	return nil
}

func (u *upstream) close() {
	if u.conn != nil {
		_ = u.conn.Close()
		u.conn = nil
	}
}
