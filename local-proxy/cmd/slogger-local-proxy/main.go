package main

import (
	"context"
	"errors"
	"flag"
	"fmt"
	"os"
	"os/signal"
	"syscall"
	"time"

	"github.com/sprust/slogger-laravel/local-proxy/internal/app"
	"github.com/sprust/slogger-laravel/local-proxy/internal/config"
)

var version = "dev"

func main() {
	os.Exit(run())
}

func run() int {
	var envPath string
	var showVersion bool
	flag.StringVar(&envPath, "env", ".env", "path to the Laravel .env file")
	flag.BoolVar(&showVersion, "version", false, "print the binary version")
	flag.Parse()

	if showVersion {
		_, _ = fmt.Fprintln(os.Stdout, version)

		return 0
	}

	rootDir, err := os.Getwd()
	if err != nil {
		fmt.Fprintf(os.Stderr, "slogger local proxy: resolve working directory: %v\n", err)

		return 1
	}

	configuration, err := config.Load(envPath, rootDir)
	if err != nil {
		fmt.Fprintf(os.Stderr, "slogger local proxy: %v\n", err)

		return 1
	}

	signalContext, stop := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer stop()

	done := make(chan error, 1)
	go func() {
		done <- app.Run(signalContext, configuration)
	}()

	select {
	case err := <-done:
		if err != nil {
			fmt.Fprintf(os.Stderr, "slogger local proxy: %v\n", err)

			return 1
		}

		return 0
	case <-signalContext.Done():
	}

	timer := time.NewTimer(5 * time.Second)
	defer timer.Stop()

	select {
	case err := <-done:
		if err != nil && !errors.Is(err, context.Canceled) {
			fmt.Fprintf(os.Stderr, "slogger local proxy: %v\n", err)

			return 1
		}

		return 0
	case <-timer.C:
		fmt.Fprintln(os.Stderr, "slogger local proxy: graceful shutdown timed out after 5 seconds")

		return 1
	}
}
