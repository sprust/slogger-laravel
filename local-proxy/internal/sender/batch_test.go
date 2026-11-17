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
	batch.add(operation{raw: json.RawMessage(`{"tid":"update"}`)})

	if batch.canAdd(operation{creating: true, raw: json.RawMessage(`{"tid":"create"}`)}) {
		t.Fatal("update followed by create was accepted")
	}
}

func TestBatchOperationLimit(t *testing.T) {
	t.Parallel()

	batch := newBatch(0, time.Now())
	operation := operation{raw: json.RawMessage(`{"tid":"trace"}`)}

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

func TestBatchSizeLimit(t *testing.T) {
	t.Parallel()

	batch := newBatch(0, time.Now())
	operation := operation{raw: json.RawMessage(`{"data":"` + strings.Repeat("a", MaxBatchSize) + `"}`)}

	if batch.canAdd(operation) {
		t.Fatal("oversized operation was accepted")
	}
}
