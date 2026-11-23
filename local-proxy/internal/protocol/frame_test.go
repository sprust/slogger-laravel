package protocol

import (
	"bytes"
	"context"
	"errors"
	"io"
	"testing"
)

func TestFrameRoundTripWithShortReadsAndWrites(t *testing.T) {
	t.Parallel()

	var buffer bytes.Buffer
	writer := &shortWriter{writer: &buffer, maximum: 2}
	payload := []byte("payload")

	if err := WriteFrame(writer, payload); err != nil {
		t.Fatal(err)
	}

	decoded, err := ReadFrame(&oneByteReader{reader: &buffer}, MaxFrameSize)
	if err != nil {
		t.Fatal(err)
	}

	if !bytes.Equal(decoded, payload) {
		t.Fatalf("payload = %q", decoded)
	}
}

func TestReadFrameRejectsInvalidSizes(t *testing.T) {
	t.Parallel()

	for _, test := range []struct {
		name    string
		header  []byte
		wantErr error
	}{
		{name: "empty", header: []byte{0, 0, 0, 0}, wantErr: ErrEmptyFrame},
		{name: "too large", header: []byte{2, 0, 0, 0}, wantErr: ErrFrameTooLarge},
	} {
		t.Run(test.name, func(t *testing.T) {
			t.Parallel()

			_, err := ReadFrame(bytes.NewReader(test.header), 10)
			if !errors.Is(err, test.wantErr) {
				t.Fatalf("error = %v", err)
			}
		})
	}
}

func TestReadFrameReturnsUnexpectedEOF(t *testing.T) {
	t.Parallel()

	_, err := ReadFrame(bytes.NewReader([]byte{0, 0, 0, 4, 'a'}), 10)
	if !errors.Is(err, io.ErrUnexpectedEOF) {
		t.Fatalf("error = %v", err)
	}
}

func TestReadFrameBudgetedReleasesAllocationAfterTruncatedPayload(t *testing.T) {
	t.Parallel()

	budget := &recordingBudget{}
	_, _, err := ReadFrameBudgeted(context.Background(), bytes.NewReader([]byte{0, 0, 0, 4, 'a'}), 10, budget)
	if !errors.Is(err, io.ErrUnexpectedEOF) {
		t.Fatalf("error = %v", err)
	}

	if budget.size != 4 || !budget.released {
		t.Fatalf("budget = %+v", budget)
	}
}

func TestReadFrameBudgetedLeavesReleaseToCaller(t *testing.T) {
	t.Parallel()

	budget := &recordingBudget{}
	payload, release, err := ReadFrameBudgeted(context.Background(), bytes.NewReader([]byte{0, 0, 0, 1, 'a'}), 10, budget)
	if err != nil {
		t.Fatal(err)
	}

	if string(payload) != "a" || budget.released {
		t.Fatalf("payload = %q, budget = %+v", payload, budget)
	}

	release()
	if !budget.released {
		t.Fatal("budget was not released")
	}
}

type recordingBudget struct {
	size     uint32
	released bool
}

func (b *recordingBudget) Acquire(_ context.Context, size uint32) (func(), error) {
	b.size = size

	return func() { b.released = true }, nil
}

type oneByteReader struct {
	reader io.Reader
}

func (r *oneByteReader) Read(data []byte) (int, error) {
	if len(data) > 1 {
		data = data[:1]
	}

	return r.reader.Read(data)
}

type shortWriter struct {
	writer  io.Writer
	maximum int
}

func (w *shortWriter) Write(data []byte) (int, error) {
	if len(data) > w.maximum {
		data = data[:w.maximum]
	}

	return w.writer.Write(data)
}
