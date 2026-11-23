package protocol

import (
	"context"
	"encoding/binary"
	"errors"
	"fmt"
	"io"
	"math"
)

const MaxFrameSize = 64 << 20

var (
	ErrEmptyFrame    = errors.New("empty frame")
	ErrFrameTooLarge = errors.New("frame exceeds 64 MiB")
)

func ReadFrame(reader io.Reader, maxSize uint32) ([]byte, error) {
	return ReadFrameContext(context.Background(), reader, maxSize)
}

func ReadFrameContext(ctx context.Context, reader io.Reader, maxSize uint32) ([]byte, error) {
	payload, _, err := ReadFrameBudgeted(ctx, reader, maxSize, nil)

	return payload, err
}

type FrameBudget interface {
	Acquire(ctx context.Context, size uint32) (release func(), err error)
}

func ReadFrameBudgeted(
	ctx context.Context,
	reader io.Reader,
	maxSize uint32,
	budget FrameBudget,
) ([]byte, func(), error) {
	var header [4]byte
	if _, err := io.ReadFull(reader, header[:]); err != nil {
		return nil, nil, err
	}

	length := binary.BigEndian.Uint32(header[:])
	if length == 0 {
		return nil, nil, ErrEmptyFrame
	}

	if length > maxSize {
		return nil, nil, fmt.Errorf("%w: %d bytes", ErrFrameTooLarge, length)
	}

	release := func() {}
	if budget != nil {
		var err error
		release, err = budget.Acquire(ctx, length)

		if err != nil {
			return nil, nil, err
		}
	}

	payload := make([]byte, int(length))
	if _, err := io.ReadFull(reader, payload); err != nil {
		release()

		return nil, nil, err
	}

	return payload, release, nil
}

func Uint32Length(length int) (uint32, error) {
	if length < 1 {
		return 0, ErrEmptyFrame
	}
	if length > math.MaxUint32 {
		return 0, ErrFrameTooLarge
	}

	return uint32(length), nil
}

func WriteFrame(writer io.Writer, payload []byte) error {
	if len(payload) == 0 {
		return ErrEmptyFrame
	}

	if len(payload) > MaxFrameSize {
		return ErrFrameTooLarge
	}

	length, err := Uint32Length(len(payload))
	if err != nil {
		return err
	}

	var header [4]byte
	binary.BigEndian.PutUint32(header[:], length)

	if err := WriteAll(writer, header[:]); err != nil {
		return err
	}

	return WriteAll(writer, payload)
}

func WriteAll(writer io.Writer, data []byte) error {
	for len(data) > 0 {
		written, err := writer.Write(data)
		if err != nil {
			return err
		}

		if written == 0 {
			return io.ErrShortWrite
		}

		data = data[written:]
	}

	return nil
}
