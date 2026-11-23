package tracepayload

import (
	"bytes"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"strings"
)

const MaxBatchSize = 4 << 20

type Operation struct {
	Creating  bool
	Raw       json.RawMessage
	SizeBytes int
}

func (o Operation) EncodedSize() (int, error) {
	if o.SizeBytes > 0 {
		return o.SizeBytes, nil
	}

	raw, err := json.Marshal(o.Raw)
	if err != nil {
		return 0, err
	}

	escaped, err := json.Marshal(string(raw))
	if err != nil {
		return 0, err
	}

	return len(escaped) - 2, nil
}

func (o Operation) WireSize() (int, error) {
	size, err := o.EncodedSize()

	return 10 + size, err
}

func ValidateRecord(payload []byte) error {
	return walkOperations(payload, true, func(operation Operation) error {
		size, err := operation.WireSize()
		if err != nil {
			return fmt.Errorf("measure trace operation: %w", err)
		}
		if size > MaxBatchSize {
			return fmt.Errorf("trace operation wire size %d exceeds 4 MiB", size)
		}

		return nil
	})
}

func WalkOperations(payload []byte, visit func(Operation) error) error {
	return walkOperations(payload, false, visit)
}

func walkOperations(payload []byte, requireKnownKey bool, visit func(Operation) error) error {
	var envelope map[string]json.RawMessage
	if err := json.Unmarshal(payload, &envelope); err != nil {
		return fmt.Errorf("decode trace envelope: %w", err)
	}

	if requireKnownKey && envelope["c"] == nil && envelope["u"] == nil {
		return errors.New("trace envelope must contain c or u")
	}

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
			return fmt.Errorf("decode %q payload: %w", part.key, err)
		}

		if err := walkOperationArray(encodedMessages, part.creating, visit); err != nil {
			return fmt.Errorf("decode %q operations: %w", part.key, err)
		}
	}

	return nil
}

func walkOperationArray(encoded string, creating bool, visit func(Operation) error) error {
	decoder := json.NewDecoder(strings.NewReader(encoded))
	opening, err := decoder.Token()
	if err != nil {
		return err
	}
	if opening != json.Delim('[') {
		return errors.New("trace operations must be an array")
	}

	for decoder.More() {
		var message json.RawMessage
		if err := decoder.Decode(&message); err != nil {
			return err
		}

		trimmed := bytes.TrimSpace(message)
		if len(trimmed) < 2 || trimmed[0] != '{' || trimmed[len(trimmed)-1] != '}' {
			return errors.New("trace operation must be a JSON object")
		}

		raw, err := json.Marshal(message)
		if err != nil {
			return err
		}

		escaped, err := json.Marshal(string(raw))
		if err != nil {
			return err
		}

		if err := visit(Operation{
			Creating:  creating,
			Raw:       raw,
			SizeBytes: len(escaped) - 2,
		}); err != nil {
			return err
		}
	}

	closing, err := decoder.Token()
	if err != nil {
		return err
	}
	if closing != json.Delim(']') {
		return errors.New("trace operations array is not closed")
	}

	var extra any
	if err := decoder.Decode(&extra); !errors.Is(err, io.EOF) {
		return errors.New("unexpected data after trace operations")
	}

	return nil
}
