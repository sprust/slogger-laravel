package sender

import (
	"encoding/json"
	"fmt"
	"time"
)

const (
	MaxBatchOperations = 200
	MaxBatchSize       = 4 << 20
)

type operation struct {
	creating bool
	raw      json.RawMessage
}

type batch struct {
	creating   []json.RawMessage
	updating   []json.RawMessage
	operations int
	offset     int64
	startedAt  time.Time
}

func newBatch(offset int64, now time.Time) *batch {
	return &batch{offset: offset, startedAt: now}
}

func (b *batch) canAdd(operation operation) bool {
	if b.operations >= MaxBatchOperations {
		return false
	}

	if operation.creating && len(b.updating) > 0 {
		return false
	}

	candidate := *b
	if operation.creating {
		candidate.creating = appendCopy(b.creating, operation.raw)
	} else {
		candidate.updating = appendCopy(b.updating, operation.raw)
	}

	_, err := candidate.encode()

	return err == nil
}

func (b *batch) add(operation operation) {
	if operation.creating {
		b.creating = append(b.creating, operation.raw)
	} else {
		b.updating = append(b.updating, operation.raw)
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
		return nil, fmt.Errorf("encoded batch exceeds 4 MiB")
	}

	return encoded, nil
}

func appendCopy(messages []json.RawMessage, message json.RawMessage) []json.RawMessage {
	result := make([]json.RawMessage, len(messages), len(messages)+1)
	copy(result, messages)

	return append(result, message)
}

type wirePayload struct {
	Creating string `json:"c,omitempty"`
	Updating string `json:"u,omitempty"`
}

func parseRecord(payload []byte) ([]operation, error) {
	var envelope map[string]json.RawMessage
	if err := json.Unmarshal(payload, &envelope); err != nil {
		return nil, fmt.Errorf("decode trace envelope: %w", err)
	}

	operations := make([]operation, 0)

	for _, part := range []struct {
		key      string
		creating bool
	}{{key: "c", creating: true}, {key: "u", creating: false}} {
		encoded, exists := envelope[part.key]
		if !exists {
			continue
		}

		var encodedMessages string
		if err := json.Unmarshal(encoded, &encodedMessages); err != nil {
			return nil, fmt.Errorf("decode %q payload: %w", part.key, err)
		}

		var messages []json.RawMessage
		if err := json.Unmarshal([]byte(encodedMessages), &messages); err != nil {
			return nil, fmt.Errorf("decode %q operations: %w", part.key, err)
		}

		for _, message := range messages {
			operations = append(operations, operation{creating: part.creating, raw: message})
		}
	}

	return operations, nil
}
