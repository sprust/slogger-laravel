package server

import (
	"context"
	"errors"
	"testing"
	"time"
)

func TestMemoryBudgetWaitsAndReleasesOnce(t *testing.T) {
	t.Parallel()

	budget := newMemoryBudget(100)
	release90, err := budget.Acquire(context.Background(), 90)
	if err != nil {
		t.Fatal(err)
	}

	release10, err := budget.Acquire(context.Background(), 10)
	if err != nil {
		t.Fatal(err)
	}

	acquired := make(chan func(), 1)
	go func() {
		release, err := budget.Acquire(context.Background(), 1)
		if err != nil {
			return
		}

		acquired <- release
	}()

	select {
	case <-acquired:
		t.Fatal("budget was acquired while full")
	case <-time.After(10 * time.Millisecond):
	}

	release10()
	release10()

	var release1 func()
	select {
	case release1 = <-acquired:
	case <-time.After(time.Second):
		t.Fatal("budget was not released to the waiter")
	}

	release1()
	release90()
	if budget.available != budget.capacity {
		t.Fatalf("available = %d, capacity = %d", budget.available, budget.capacity)
	}
}

func TestMemoryBudgetCancellation(t *testing.T) {
	t.Parallel()

	budget := newMemoryBudget(10)
	release, err := budget.Acquire(context.Background(), 10)
	if err != nil {
		t.Fatal(err)
	}

	defer release()
	ctx, cancel := context.WithCancel(context.Background())
	cancel()

	if _, err := budget.Acquire(ctx, 1); !errors.Is(err, context.Canceled) {
		t.Fatalf("error = %v", err)
	}
}

func TestMemoryBudgetRejectsPayloadAboveCapacity(t *testing.T) {
	t.Parallel()

	budget := newMemoryBudget(10)
	if _, err := budget.Acquire(context.Background(), 11); err == nil {
		t.Fatal("oversized payload was accepted")
	}
}
