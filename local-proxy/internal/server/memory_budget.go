package server

import (
	"context"
	"fmt"
	"sync"
)

type memoryBudget struct {
	mu        sync.Mutex
	capacity  int64
	available int64
	changed   chan struct{}
}

func newMemoryBudget(capacity int64) *memoryBudget {
	return &memoryBudget{
		capacity:  capacity,
		available: capacity,
		changed:   make(chan struct{}),
	}
}

func (b *memoryBudget) Acquire(ctx context.Context, size uint32) (func(), error) {
	amount := int64(size)
	if amount > b.capacity {
		return nil, fmt.Errorf("payload size %d exceeds memory budget %d", amount, b.capacity)
	}

	for {
		b.mu.Lock()
		if b.available >= amount {
			b.available -= amount
			b.mu.Unlock()

			var once sync.Once

			return func() {
				once.Do(func() { b.release(amount) })
			}, nil
		}

		changed := b.changed
		b.mu.Unlock()

		select {
		case <-ctx.Done():
			return nil, ctx.Err()
		case <-changed:
		}
	}
}

func (b *memoryBudget) release(amount int64) {
	b.mu.Lock()
	defer b.mu.Unlock()

	b.available += amount
	close(b.changed)
	b.changed = make(chan struct{})
}
