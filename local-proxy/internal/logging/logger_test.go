package logging

import (
	"os"
	"path/filepath"
	"testing"
	"time"
)

func TestErrorRotationAndCleanup(t *testing.T) {
	now := time.Date(2026, 11, 17, 10, 0, 0, 0, time.UTC)
	dir := t.TempDir()
	logger, err := New(dir, func() time.Time { return now })
	if err != nil {
		t.Fatal(err)
	}

	logger.Error("first error", "error", "one")

	now = now.AddDate(0, 0, 1)
	logger.Error("second error", "error", "two")

	for _, name := range []string{
		"local-proxy-errors-2026_11_17.logs",
		"local-proxy-errors-2026_11_18.logs",
	} {
		if _, err := os.Stat(filepath.Join(dir, name)); err != nil {
			t.Fatal(err)
		}
	}

	if err := os.WriteFile(filepath.Join(dir, "local-proxy-errors-2026_11_15.logs"), []byte("old"), 0o600); err != nil {
		t.Fatal(err)
	}

	if err := logger.Cleanup(); err != nil {
		t.Fatal(err)
	}

	if _, err := os.Stat(filepath.Join(dir, "local-proxy-errors-2026_11_15.logs")); !os.IsNotExist(err) {
		t.Fatalf("old log still exists: %v", err)
	}

	if _, err := os.Stat(filepath.Join(dir, "local-proxy-errors-2026_11_17.logs")); err != nil {
		t.Fatalf("recent log was removed: %v", err)
	}

	if err := logger.Close(); err != nil {
		t.Fatal(err)
	}
}
