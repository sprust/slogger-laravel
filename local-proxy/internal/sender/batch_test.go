package sender

import (
	"encoding/json"
	"strings"
	"testing"
	"time"
)

func TestBatchPreservesTraceFieldsAndOperationOrder(t *testing.T) {
	t.Parallel()

	payload := []byte(`{"c":"[{\"tid\":\"create\",\"custom\":1}]","u":"[{\"tid\":\"update\"}]"}`)
	operations, err := parseRecord(payload)
	if err != nil {
		t.Fatal(err)
	}

	batch := newBatch(0, time.Now())

	for _, operation := range operations {
		if !batch.canAdd(operation) {
			t.Fatal("operation does not fit")
		}

		batch.add(operation)
	}

	encoded, err := batch.encode()
	if err != nil {
		t.Fatal(err)
	}

	var envelope map[string]string
	if err := json.Unmarshal(encoded, &envelope); err != nil {
		t.Fatal(err)
	}

	var creating []map[string]any
	if err := json.Unmarshal([]byte(envelope["c"]), &creating); err != nil {
		t.Fatal(err)
	}

	if creating[0]["tid"] != "create" || creating[0]["custom"] != float64(1) {
		t.Fatalf("creating = %+v", creating)
	}
}

func TestBatchDoesNotInvertUpdateThenCreate(t *testing.T) {
	t.Parallel()

	batch := newBatch(0, time.Now())
	batch.add(operation{Raw: json.RawMessage(`{"tid":"update"}`)})

	if batch.canAdd(operation{Creating: true, Raw: json.RawMessage(`{"tid":"create"}`)}) {
		t.Fatal("update followed by create was accepted")
	}
}

func TestBatchOperationLimit(t *testing.T) {
	t.Parallel()

	batch := newBatch(0, time.Now())
	operation := operation{Raw: json.RawMessage(`{"tid":"trace"}`)}

	for index := 0; index < MaxBatchOperations; index++ {
		if !batch.canAdd(operation) {
			t.Fatalf("operation %d does not fit", index)
		}

		batch.add(operation)
	}

	if batch.canAdd(operation) {
		t.Fatal("201st operation was accepted")
	}
}

func TestBatchSizeAccountingMatchesEscaping(t *testing.T) {
	t.Parallel()

	batch := newBatch(0, time.Now())
	operations := []operation{
		{Creating: true, Raw: json.RawMessage(`{"text":"quote\" backslash\\ <tag>& \u2028"}`)},
		{Raw: json.RawMessage(`{"text":"line\nnext"}`)},
	}

	for _, operation := range operations {
		if !batch.canAdd(operation) {
			t.Fatal("operation does not fit")
		}

		batch.add(operation)
	}

	encoded, err := batch.encode()
	if err != nil {
		t.Fatal(err)
	}

	if len(encoded) != batch.encodedSize() {
		t.Fatalf("encoded size = %d, accounted = %d", len(encoded), batch.encodedSize())
	}
}

func TestBatchShapesAndExactFourMiBBoundary(t *testing.T) {
	t.Parallel()

	for _, shape := range []string{"c-only", "u-only", "mixed"} {
		t.Run(shape, func(t *testing.T) {
			batch := newBatch(0, time.Now())

			if shape == "mixed" {
				first := operation{Creating: true, Raw: rawJSONOfSize(8)}
				batch.add(first)
			}

			remaining := MaxBatchSize - batch.encodedSize()
			operationSize := remaining - 8
			if shape == "mixed" {
				operationSize--
			}

			candidate := operation{Creating: shape == "c-only", Raw: rawJSONOfSize(operationSize)}
			if !batch.canAdd(candidate) {
				size, _ := candidate.EncodedSize()
				t.Fatalf("operation at the exact boundary was rejected: total=%d, raw=%d, operation=%d, limit=%d", batch.sizeWith(candidate.Creating, size), len(candidate.Raw), size, MaxBatchSize)
			}

			batch.add(candidate)
			encoded, err := batch.encode()
			if err != nil {
				t.Fatal(err)
			}

			if len(encoded) != MaxBatchSize {
				t.Fatalf("encoded size = %d, want %d", len(encoded), MaxBatchSize)
			}

			extra := operation{Creating: shape == "c-only", Raw: rawJSONOfSize(8)}
			if batch.canAdd(extra) {
				t.Fatal("operation above the boundary was accepted")
			}
		})
	}
}

func rawJSONOfSize(size int) json.RawMessage {
	if size < 1 {
		panic("raw JSON requires at least 1 byte")
	}

	return json.RawMessage(strings.Repeat("1", size))
}

func BenchmarkBatch200Operations(b *testing.B) {
	raw := json.RawMessage(`{"tid":"trace-123","type":"request","data":{"uri":"/api/users","status":200}}`)
	operations := make([]operation, MaxBatchOperations)

	for index := range operations {
		operations[index] = operation{Creating: true, Raw: raw}
	}

	b.ReportAllocs()
	b.ResetTimer()

	for range b.N {
		batch := newBatch(0, time.Now())

		for _, operation := range operations {
			if !batch.canAdd(operation) {
				b.Fatal("operation does not fit")
			}

			batch.add(operation)
		}

		if _, err := batch.encode(); err != nil {
			b.Fatal(err)
		}
	}
}

func TestBatchSizeLimit(t *testing.T) {
	t.Parallel()

	batch := newBatch(0, time.Now())
	operation := operation{Raw: json.RawMessage(`{"data":"` + strings.Repeat("a", MaxBatchSize) + `"}`)}

	if batch.canAdd(operation) {
		t.Fatal("oversized operation was accepted")
	}
}
