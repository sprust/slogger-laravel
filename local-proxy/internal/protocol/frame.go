package protocol

import (
	"encoding/binary"
	"errors"
	"fmt"
	"io"
)

const MaxFrameSize = 64 << 20

var (
	ErrEmptyFrame    = errors.New("empty frame")
	ErrFrameTooLarge = errors.New("frame exceeds 64 MiB")
)

func ReadFrame(reader io.Reader, maxSize uint32) ([]byte, error) {
	var header [4]byte
	if _, err := io.ReadFull(reader, header[:]); err != nil {
		return nil, err
	}

	length := binary.BigEndian.Uint32(header[:])
	if length == 0 {
		return nil, ErrEmptyFrame
	}

	if length > maxSize {
		return nil, fmt.Errorf("%w: %d bytes", ErrFrameTooLarge, length)
	}

	payload := make([]byte, int(length))
	if _, err := io.ReadFull(reader, payload); err != nil {
		return nil, err
	}

	return payload, nil
}

func WriteFrame(writer io.Writer, payload []byte) error {
	if len(payload) == 0 {
		return ErrEmptyFrame
	}

	if len(payload) > MaxFrameSize {
		return ErrFrameTooLarge
	}

	var header [4]byte
	binary.BigEndian.PutUint32(header[:], uint32(len(payload)))

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
