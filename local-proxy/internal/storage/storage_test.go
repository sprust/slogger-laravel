package storage

import (
	"bytes"
	"encoding/binary"
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"testing"
	"time"
)

func TestStoreAppendRotateAndRead(t *testing.T) {
	t.Parallel()

	now := time.Date(2026, 11, 17, 13, 0, 0, 0, time.Local)
	store, err := NewStore(t.TempDir(), func() time.Time { return now })
	if err != nil {
		t.Fatal(err)
	}

	defer store.Close()

	if err := store.Append([]byte("first")); err != nil {
		t.Fatal(err)
	}

	now = now.Add(time.Hour)
	if err := store.Append([]byte("second")); err != nil {
		t.Fatal(err)
	}

	assertRecord(t, filepath.Join(store.dir, "2026_11_17_13"), 0, "first", 9)
	assertRecord(t, filepath.Join(store.dir, "2026_11_17_14"), 0, "second", 10)
}

func TestStoreSealsHourlyFileOnlyAfterWriterRotatesIt(t *testing.T) {
	t.Parallel()

	now := time.Date(2026, 11, 17, 13, 0, 0, 0, time.Local)
	store, err := NewStore(t.TempDir(), func() time.Time { return now })
	if err != nil {
		t.Fatal(err)
	}

	defer store.Close()

	if err := store.Append([]byte("first")); err != nil {
		t.Fatal(err)
	}

	now = now.Add(time.Hour)
	if store.IsSealed("2026_11_17_13") {
		t.Fatal("file was sealed while writer still had it open")
	}

	if err := store.Append([]byte("second")); err != nil {
		t.Fatal(err)
	}

	if !store.IsSealed("2026_11_17_13") {
		t.Fatal("file was not sealed after writer rotated it")
	}
}

func TestRecoverFileTruncatesOnlyIncompleteTail(t *testing.T) {
	t.Parallel()

	for _, test := range []struct {
		name string
		tail []byte
	}{
		{name: "prefix", tail: []byte{0, 0}},
		{name: "payload", tail: []byte{0, 0, 0, 4, 'a', 'b'}},
	} {
		t.Run(test.name, func(t *testing.T) {
			filePath := filepath.Join(t.TempDir(), "2026_11_17_13")
			record := []byte{0, 0, 0, 3, 'f', 'o', 'o'}
			contents := append(record, test.tail...)
			if err := os.WriteFile(filePath, contents, 0o600); err != nil {
				t.Fatal(err)
			}

			if err := RecoverFile(filePath); err != nil {
				t.Fatal(err)
			}

			stat, err := os.Stat(filePath)
			if err != nil {
				t.Fatal(err)
			}

			if stat.Size() != int64(len(record)) {
				t.Fatalf("size = %d, want %d", stat.Size(), len(record))
			}
		})
	}
}

func TestRecoverFileRejectsDamageInCompleteRecord(t *testing.T) {
	t.Parallel()

	filePath := filepath.Join(t.TempDir(), "2026_11_17_13")
	contents := make([]byte, 8)
	binary.BigEndian.PutUint32(contents[:4], 0)
	if err := os.WriteFile(filePath, contents, 0o600); err != nil {
		t.Fatal(err)
	}

	if err := RecoverFile(filePath); err == nil {
		t.Fatal("RecoverFile unexpectedly succeeded")
	}
}

func TestReadRecordWaitsForIncompleteActiveRecord(t *testing.T) {
	t.Parallel()

	filePath := filepath.Join(t.TempDir(), "2026_11_17_13")
	if err := os.WriteFile(filePath, []byte{0, 0, 0, 4, 'a'}, 0o600); err != nil {
		t.Fatal(err)
	}

	file, err := os.Open(filePath)
	if err != nil {
		t.Fatal(err)
	}

	defer file.Close()

	if _, complete, err := ReadRecord(file, 0); err != nil || complete {
		t.Fatalf("complete = %v, error = %v", complete, err)
	}
}

func TestStateStoreRoundTrip(t *testing.T) {
	t.Parallel()

	dir := t.TempDir()
	states := NewStateStore(dir)
	state, err := states.Load()
	if err != nil {
		t.Fatal(err)
	}

	if state.Version != StateVersion || state.File != "" {
		t.Fatalf("initial state = %+v", state)
	}

	state.File = "2026_11_17_13"
	state.Offset = 123
	state.Completed = true

	if err := states.Save(state); err != nil {
		t.Fatal(err)
	}

	loaded, err := states.Load()
	if err != nil {
		t.Fatal(err)
	}

	if loaded != state {
		t.Fatalf("loaded = %+v, want %+v", loaded, state)
	}
}

func TestStateStoreRejectsUnknownVersion(t *testing.T) {
	t.Parallel()

	dir := t.TempDir()
	data, err := json.Marshal(State{Version: 99})
	if err != nil {
		t.Fatal(err)
	}

	if err := os.WriteFile(filepath.Join(dir, StateFileName), data, 0o600); err != nil {
		t.Fatal(err)
	}

	if _, err := NewStateStore(dir).Load(); err == nil {
		t.Fatal("Load unexpectedly succeeded")
	}
}

func TestListTraceFilesIgnoresMetadata(t *testing.T) {
	t.Parallel()

	dir := t.TempDir()
	for _, name := range []string{"2026_11_17_14", "2026_11_17_13", "local-proxy.sock", "local-proxy-errors-2026_11_17.logs"} {
		if err := os.WriteFile(filepath.Join(dir, name), nil, 0o600); err != nil {
			t.Fatal(err)
		}
	}

	names, err := ListTraceFiles(dir)
	if err != nil {
		t.Fatal(err)
	}

	if len(names) != 2 || names[0] != "2026_11_17_13" || names[1] != "2026_11_17_14" {
		t.Fatalf("names = %v", names)
	}
}

func assertRecord(t *testing.T, filePath string, offset int64, payload string, nextOffset int64) {
	t.Helper()

	file, err := os.Open(filePath)
	if err != nil {
		t.Fatal(err)
	}

	defer file.Close()

	record, complete, err := ReadRecord(file, offset)
	if err != nil {
		t.Fatal(err)
	}

	if !complete || !bytes.Equal(record.Payload, []byte(payload)) || record.NextOffset != nextOffset {
		t.Fatalf("record = %+v, complete = %v", record, complete)
	}
}

func TestExpiredIdleWriterDoesNotReopenDeletedHourAfterClockRollback(t *testing.T) {
	now := time.Date(2026, 11, 20, 12, 0, 0, 0, time.UTC)
	store, err := NewStore(t.TempDir(), func() time.Time { return now })
	if err != nil {
		t.Fatal(err)
	}
	defer store.Close()
	if err := store.Append([]byte("expired")); err != nil {
		t.Fatal(err)
	}
	old := CurrentFileName(now)
	now = now.Add(2 * time.Hour)
	next := CurrentFileName(now)
	if err := store.RemoveExpired(old, next); err != nil {
		t.Fatal(err)
	}
	now = now.Add(-3 * time.Hour)
	if err := store.Append([]byte("new")); err != nil {
		t.Fatal(err)
	}
	assertRecord(t, filepath.Join(store.dir, next), 0, "new", 7)
	if _, err := os.Stat(filepath.Join(store.dir, old)); !os.IsNotExist(err) {
		t.Fatalf("expired hour still exists: %v", err)
	}
}

func TestStartupAfterCompletedHourAtDSTFallback(t *testing.T) {
	zone, err := time.LoadLocation("America/New_York")
	if err != nil {
		t.Fatal(err)
	}
	now := time.Date(2026, 11, 1, 5, 30, 0, 0, time.UTC).In(zone)
	dir := t.TempDir()
	old := CurrentFileName(now)
	if err := NewStateStore(dir).Save(State{File: old, Completed: true}); err != nil {
		t.Fatal(err)
	}
	store, err := NewStore(dir, func() time.Time { return now })
	if err != nil {
		t.Fatal(err)
	}
	defer store.Close()
	if err := store.Append([]byte("new")); err != nil {
		t.Fatal(err)
	}
	assertRecord(t, filepath.Join(dir, "2026_11_01_02"), 0, "new", 7)
}

func TestInvalidCalendarHourDoesNotCaptureWriter(t *testing.T) {
	t.Parallel()

	dir := t.TempDir()
	if err := os.WriteFile(filepath.Join(dir, "9999_99_99_99"), nil, 0o600); err != nil {
		t.Fatal(err)
	}

	now := time.Date(2026, 11, 23, 12, 0, 0, 0, time.UTC)
	store, err := NewStore(dir, func() time.Time { return now })
	if err != nil {
		t.Fatal(err)
	}
	defer store.Close()

	if err := store.Append([]byte(`{"c":"[]"}`)); err != nil {
		t.Fatal(err)
	}
	if store.hour != "2026_11_23_12" {
		t.Fatalf("writer hour = %q", store.hour)
	}

	names, err := ListTraceFiles(dir)
	if err != nil {
		t.Fatal(err)
	}
	if len(names) != 1 || names[0] != "2026_11_23_12" {
		t.Fatalf("trace files = %v", names)
	}
}

func TestSpoolLimitRejectsAppendAndRecoversAfterRemoval(t *testing.T) {
	t.Parallel()

	now := time.Date(2026, 11, 23, 12, 0, 0, 0, time.UTC)
	payload := []byte(`{"c":"[]"}`)
	recordSize := int64(len(payload) + 4)
	store, err := NewStore(t.TempDir(), func() time.Time { return now }, recordSize)
	if err != nil {
		t.Fatal(err)
	}
	defer store.Close()

	if err := store.Append(payload); err != nil {
		t.Fatal(err)
	}
	if err := store.Append(payload); !errors.Is(err, ErrSpoolFull) {
		t.Fatalf("second append error = %v", err)
	}
	if metrics := store.Metrics(); metrics.SpoolBytes != recordSize || metrics.SpoolLimitBytes != recordSize || metrics.AppendedRecords != 1 {
		t.Fatalf("metrics = %+v", metrics)
	}

	if err := store.RemoveSent(CurrentFileName(now)); err != nil {
		t.Fatal(err)
	}
	if metrics := store.Metrics(); metrics.SpoolBytes != 0 {
		t.Fatalf("spool bytes after removal = %d", metrics.SpoolBytes)
	}
	if err := store.Append(payload); err != nil {
		t.Fatalf("append after removal: %v", err)
	}
}

func TestSpoolAccountingIncludesRecoveryTruncation(t *testing.T) {
	t.Parallel()

	now := time.Date(2026, 11, 23, 12, 0, 0, 0, time.UTC)
	name := CurrentFileName(now)
	complete := []byte{0, 0, 0, 3, 'f', 'o', 'o'}
	dir := t.TempDir()
	if err := os.WriteFile(filepath.Join(dir, name), append(complete, 0, 0), 0o600); err != nil {
		t.Fatal(err)
	}

	payload := []byte(`{"c":"[]"}`)
	expected := int64(len(complete) + len(payload) + 4)
	store, err := NewStore(dir, func() time.Time { return now }, expected)
	if err != nil {
		t.Fatal(err)
	}
	defer store.Close()
	if err := store.Append(payload); err != nil {
		t.Fatal(err)
	}

	if metrics := store.Metrics(); metrics.SpoolBytes != expected {
		t.Fatalf("spool bytes = %d, want %d", metrics.SpoolBytes, expected)
	}
}
