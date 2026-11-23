package tracepayload

import (
	"encoding/json"
	"errors"
	"strings"
	"testing"
)

func TestValidateRecordAcceptsRealPHPTraceShapes(t *testing.T) {
	t.Parallel()

	payload := encodeRecord(t,
		[]map[string]any{{
			"tid": "app-trace-1",
			"tp":  "request",
			"st":  "started",
			"dt":  map[string]any{"request": map[string]any{"method": "GET"}},
			"isP": true,
			"lat": "2026-11-18T12:00:00.123+03:00",
		}},
		[]map[string]any{{
			"tid":  "app-trace-1",
			"st":   "success",
			"dt":   map[string]any{"response": map[string]any{"status": 200}},
			"dur":  1.25,
			"plat": "2026-11-18T12:00:00.123+03:00",
		}},
	)

	if err := ValidateRecord(payload); err != nil {
		t.Fatal(err)
	}
}

func TestWalkOperationsRejectsNonObject(t *testing.T) {
	t.Parallel()

	payload := encodeRecord(t, []map[string]any{{"tid": "unused"}}, nil)
	payload = []byte(`{"c":"[1]"}`)

	if err := ValidateRecord(payload); err == nil || !strings.Contains(err.Error(), "JSON object") {
		t.Fatalf("error = %v", err)
	}
}

func TestValidateRecordStopsAtFirstUnsendableOperation(t *testing.T) {
	t.Parallel()

	payload := encodeRecord(t,
		[]map[string]any{
			{"data": strings.Repeat("a", MaxBatchSize)},
			{"tid": "must-not-be-visited"},
		},
		nil,
	)
	visited := 0

	err := WalkOperations(payload, func(operation Operation) error {
		visited++
		size, err := operation.WireSize()
		if err != nil {
			return err
		}
		if size > MaxBatchSize {
			return errTraceTooLargeForTest
		}

		return nil
	})
	if !errors.Is(err, errTraceTooLargeForTest) {
		t.Fatalf("error = %v", err)
	}
	if visited != 1 {
		t.Fatalf("visited = %d", visited)
	}
}

var errTraceTooLargeForTest = errors.New("too large")

func encodeRecord(t *testing.T, creating, updating []map[string]any) []byte {
	t.Helper()

	envelope := make(map[string]string)
	if creating != nil {
		encoded, err := json.Marshal(creating)
		if err != nil {
			t.Fatal(err)
		}
		envelope["c"] = string(encoded)
	}
	if updating != nil {
		encoded, err := json.Marshal(updating)
		if err != nil {
			t.Fatal(err)
		}
		envelope["u"] = string(encoded)
	}

	payload, err := json.Marshal(envelope)
	if err != nil {
		t.Fatal(err)
	}

	return payload
}

func TestWalkOperationsPreservesLegacyEmptyEnvelopes(t *testing.T) {
	for _, payload := range []string{"null", "{}", `{"creates":"[{}]"}`} {
		operations := 0
		err := WalkOperations([]byte(payload), func(Operation) error {
			operations++

			return nil
		})
		if err != nil || operations != 0 {
			t.Fatalf("legacy record %s: %v, %d", payload, err, operations)
		}
	}
}

func BenchmarkWalkOperations100KOperations(b *testing.B) {
	operations := `[` + strings.TrimSuffix(strings.Repeat(`{"tid":"trace"},`, 100000), `,`) + `]`
	payload, err := json.Marshal(map[string]string{"c": operations})
	if err != nil {
		b.Fatal(err)
	}

	b.ReportAllocs()
	b.ResetTimer()

	for range b.N {
		visited := 0
		if err := WalkOperations(payload, func(Operation) error {
			visited++

			return nil
		}); err != nil {
			b.Fatal(err)
		}
		if visited != 100000 {
			b.Fatalf("visited = %d", visited)
		}
	}
}
