package sender

import (
	"encoding/json"
	"errors"
	"fmt"
	"time"

	"github.com/sprust/slogger-laravel/local-proxy/internal/tracepayload"
)

const (
	MaxBatchOperations = 200
	MaxBatchSize       = tracepayload.MaxBatchSize
)

type operation = tracepayload.Operation

type batch struct {
	creating      []json.RawMessage
	updating      []json.RawMessage
	operations    uint
	creatingBytes int
	updatingBytes int
	offset        int64
	startedAt     time.Time
}

func newBatch(offset int64, now time.Time) *batch {
	return &batch{offset: offset, startedAt: now}
}

func (b *batch) canAdd(operation operation) bool {
	if b.operations >= MaxBatchOperations {
		return false
	}

	if operation.Creating && len(b.updating) > 0 {
		return false
	}

	size, err := operation.EncodedSize()
	if err != nil {
		return false
	}

	return b.sizeWith(operation.Creating, size) <= MaxBatchSize
}

func (b *batch) add(operation operation) {
	size, _ := operation.EncodedSize()

	if operation.Creating {
		if len(b.creating) > 0 {
			b.creatingBytes++
		}
		b.creatingBytes += size
		b.creating = append(b.creating, operation.Raw)
	} else {
		if len(b.updating) > 0 {
			b.updatingBytes++
		}
		b.updatingBytes += size
		b.updating = append(b.updating, operation.Raw)
	}

	b.operations++
}

func (b *batch) encode() ([]byte, error) {
	payload := wirePayload{}

	if len(b.creating) > 0 {
		creating, err := json.Marshal(b.creating)
		if err != nil {
			return nil, err
		}

		payload.Creating = string(creating)
	}

	if len(b.updating) > 0 {
		updating, err := json.Marshal(b.updating)
		if err != nil {
			return nil, err
		}

		payload.Updating = string(updating)
	}

	encoded, err := json.Marshal(payload)
	if err != nil {
		return nil, err
	}

	if len(encoded) > MaxBatchSize {
		return nil, errors.New("encoded batch exceeds 4 MiB")
	}

	if len(encoded) != b.encodedSize() {
		return nil, fmt.Errorf("batch size accounting mismatch: got %d, expected %d", len(encoded), b.encodedSize())
	}

	return encoded, nil
}

func (b *batch) encodedSize() int {
	size := 2

	if len(b.creating) > 0 {
		size += 8 + b.creatingBytes
	}
	if len(b.updating) > 0 {
		size += 8 + b.updatingBytes
	}
	if len(b.creating) > 0 && len(b.updating) > 0 {
		size++
	}

	return size
}

func (b *batch) sizeWith(creating bool, operationSize int) int {
	size := b.encodedSize()

	if creating && len(b.creating) == 0 {
		size += 8

		if len(b.updating) > 0 {
			size++
		}
	}
	if !creating && len(b.updating) == 0 {
		size += 8

		if len(b.creating) > 0 {
			size++
		}
	}
	if (creating && len(b.creating) > 0) || (!creating && len(b.updating) > 0) {
		size++
	}

	return size + operationSize
}

type wirePayload struct {
	Creating string `json:"c,omitempty"`
	Updating string `json:"u,omitempty"`
}
