package logging

import (
	"errors"
	"fmt"
	"log/slog"
	"os"
	"path/filepath"
	"regexp"
	"sync"
	"time"
)

var errorFileNamePattern = regexp.MustCompile(`^local-proxy-errors-(\d{4}_\d{2}_\d{2})\.logs$`)

func IsErrorLogFileName(name string) bool {
	return errorFileNamePattern.MatchString(name)
}

type Logger struct {
	dir   string
	now   func() time.Time
	info  *slog.Logger
	error *slog.Logger
	mu    sync.Mutex
	file  *os.File
	day   string
}

func New(dir string, now func() time.Time) (*Logger, error) {
	if now == nil {
		now = time.Now
	}

	if err := os.MkdirAll(dir, 0o700); err != nil {
		return nil, fmt.Errorf("create log directory: %w", err)
	}

	return &Logger{
		dir:   dir,
		now:   now,
		info:  slog.New(slog.NewTextHandler(os.Stdout, &slog.HandlerOptions{Level: slog.LevelInfo})),
		error: slog.New(slog.NewTextHandler(os.Stderr, &slog.HandlerOptions{Level: slog.LevelError})),
	}, nil
}

func (l *Logger) Info(message string, arguments ...any) {
	l.info.Info(message, arguments...)
}

func (l *Logger) Error(message string, arguments ...any) {
	l.error.Error(message, arguments...)

	l.mu.Lock()
	defer l.mu.Unlock()

	day := l.now().Format("2006_01_02")
	if l.file == nil || l.day != day {
		if err := l.openFile(day); err != nil {
			fmt.Fprintf(os.Stderr, "slogger local proxy: open error log: %v\n", err)
			return
		}
	}

	logger := slog.New(slog.NewJSONHandler(l.file, &slog.HandlerOptions{Level: slog.LevelError}))

	logger.Error(message, arguments...)
}

func (l *Logger) Cleanup() error {
	entries, err := os.ReadDir(l.dir)
	if err != nil {
		return fmt.Errorf("read error log directory: %w", err)
	}

	var cleanupErrors []error
	now := l.now()
	currentDay := now.Format("2006_01_02")

	for _, entry := range entries {
		matches := errorFileNamePattern.FindStringSubmatch(entry.Name())
		if matches == nil || entry.IsDir() {
			continue
		}

		day, err := time.ParseInLocation("2006_01_02", matches[1], now.Location())
		if err != nil {
			continue
		}

		if matches[1] == currentDay || now.Before(day.AddDate(0, 0, 3)) {
			continue
		}

		if err := os.Remove(filepath.Join(l.dir, entry.Name())); err != nil && !os.IsNotExist(err) {
			cleanupErrors = append(cleanupErrors, fmt.Errorf("delete error log %q: %w", entry.Name(), err))
		}
	}

	return errors.Join(cleanupErrors...)
}

func (l *Logger) Close() error {
	l.mu.Lock()
	defer l.mu.Unlock()

	if l.file == nil {
		return nil
	}

	file := l.file
	l.file = nil
	l.day = ""

	return file.Close()
}

func (l *Logger) openFile(day string) error {
	if l.file != nil {
		if err := l.file.Close(); err != nil {
			fmt.Fprintf(os.Stderr, "slogger local proxy: close error log: %v\n", err)
		}

		l.file = nil
		l.day = ""
	}

	filePath := filepath.Join(l.dir, "local-proxy-errors-"+day+".logs")
	file, err := os.OpenFile(filePath, os.O_CREATE|os.O_WRONLY|os.O_APPEND, 0o600)
	if err != nil {
		return err
	}

	l.file = file
	l.day = day

	return nil
}
