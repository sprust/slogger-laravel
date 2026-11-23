package app

import (
	"context"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"syscall"

	"github.com/sprust/slogger-laravel/local-proxy/internal/config"
	"github.com/sprust/slogger-laravel/local-proxy/internal/logging"
	"github.com/sprust/slogger-laravel/local-proxy/internal/metrics"
	"github.com/sprust/slogger-laravel/local-proxy/internal/sender"
	"github.com/sprust/slogger-laravel/local-proxy/internal/server"
	"github.com/sprust/slogger-laravel/local-proxy/internal/storage"
)

func Run(parent context.Context, configuration config.Config) error {
	if err := os.MkdirAll(configuration.TracesDir, 0o700); err != nil {
		return err
	}

	lock, err := acquireLock(filepath.Join(configuration.TracesDir, storage.LockFileName))
	if err != nil {
		return err
	}
	defer func() {
		_ = syscall.Flock(int(lock.Fd()), syscall.LOCK_UN)
		_ = lock.Close()
	}()

	logger, err := logging.New(configuration.TracesDir, nil)
	if err != nil {
		return err
	}
	defer logger.Close()

	store, err := storage.NewStore(configuration.TracesDir, nil, configuration.MaxSpoolBytes)
	if err != nil {
		return err
	}
	defer store.Close()

	traceSender, err := sender.New(
		configuration.TracesDir,
		configuration.UpstreamURL,
		configuration.Token,
		configuration.RetentionHours,
		nil,
		logger,
		store,
	)
	if err != nil {
		return err
	}

	localServer := server.New(server.SocketPath(configuration.TracesDir), store, logger)
	metricsCollector := metrics.New(configuration.TracesDir, localServer, store, traceSender, logger)

	ctx, cancel := context.WithCancel(parent)
	defer cancel()

	errorsChannel := make(chan error, 3)
	go func() {
		errorsChannel <- localServer.Run(ctx)
	}()

	go func() {
		errorsChannel <- traceSender.Run(ctx)
	}()

	go func() {
		errorsChannel <- metricsCollector.Run(ctx)
	}()

	firstError := <-errorsChannel
	cancel()

	secondError := <-errorsChannel
	thirdError := <-errorsChannel

	if err := metricsCollector.Write(); err != nil {
		logger.Error("failed to write final local proxy metrics", "error", err)
	}

	runError := errors.Join(
		nonCancellationError(firstError),
		nonCancellationError(secondError),
		nonCancellationError(thirdError),
	)

	if runError != nil {
		logger.Error("local proxy stopped after an internal error", "error", runError)
	}

	return runError
}

func acquireLock(path string) (*os.File, error) {
	file, err := os.OpenFile(path, os.O_CREATE|os.O_RDWR, 0o600)
	if err != nil {
		return nil, fmt.Errorf("open local proxy lock: %w", err)
	}

	if err := syscall.Flock(int(file.Fd()), syscall.LOCK_EX|syscall.LOCK_NB); err != nil {
		file.Close()

		if err == syscall.EWOULDBLOCK || err == syscall.EAGAIN {
			return nil, fmt.Errorf("another local proxy process is already running")
		}

		return nil, fmt.Errorf("lock local proxy: %w", err)
	}

	return file, nil
}

func nonCancellationError(err error) error {
	if errors.Is(err, context.Canceled) {
		return nil
	}

	return err
}
