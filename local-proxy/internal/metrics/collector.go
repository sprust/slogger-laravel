package metrics

import (
	"context"
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"runtime"
	"sort"
	"strconv"
	"strings"
	"time"

	"github.com/sprust/slogger-laravel/local-proxy/internal/logging"
	"github.com/sprust/slogger-laravel/local-proxy/internal/sender"
	"github.com/sprust/slogger-laravel/local-proxy/internal/server"
	"github.com/sprust/slogger-laravel/local-proxy/internal/storage"
)

const FileName = "local-proxy-metrics.json"

type Logger interface {
	Error(message string, arguments ...any)
}

type Collector struct {
	dir       string
	startedAt time.Time
	now       func() time.Time
	server    *server.Server
	store     *storage.Store
	sender    *sender.Sender
	logger    Logger
	interval  time.Duration

	lastWriteErrorAt time.Time
}

type Snapshot struct {
	CollectedAt   time.Time      `json:"collected_at"`
	UptimeSeconds float64        `json:"uptime_seconds"`
	Process       ProcessMetrics `json:"process"`
	Server        ServerMetrics  `json:"server"`
	Storage       StorageMetrics `json:"storage"`
	Sender        SenderMetrics  `json:"sender"`
}

type ProcessMetrics struct {
	Goroutines      int    `json:"goroutines"`
	HeapAllocBytes  uint64 `json:"heap_alloc_bytes"`
	HeapSysBytes    uint64 `json:"heap_sys_bytes"`
	StackInuseBytes uint64 `json:"stack_inuse_bytes"`
	RSSBytes        int64  `json:"rss_bytes,omitempty"`
}

type ServerMetrics struct {
	Connections      int   `json:"connections"`
	IdleConnections  int   `json:"idle_connections"`
	ConnectionSlots  int   `json:"connection_slots_used"`
	ConnectionLimit  int   `json:"connection_limit"`
	QueuedRecords    int   `json:"queued_records"`
	QueueCapacity    int   `json:"queue_capacity_records"`
	InputBudgetUsed  int64 `json:"input_budget_used_bytes"`
	InputBudgetTotal int64 `json:"input_budget_total_bytes"`
}

type StorageMetrics struct {
	HourlyFiles      int          `json:"hourly_files"`
	HourlyBytes      int64        `json:"hourly_bytes"`
	ErrorLogFiles    int          `json:"error_log_files"`
	AppendedRecords  uint64       `json:"appended_records_total"`
	AppendedBytes    uint64       `json:"appended_bytes_total"`
	OldestHourlyFile string       `json:"oldest_hourly_file,omitempty"`
	NewestHourlyFile string       `json:"newest_hourly_file,omitempty"`
	State            StateMetrics `json:"state"`
}

type StateMetrics struct {
	File      string `json:"file,omitempty"`
	Offset    int64  `json:"offset"`
	Completed bool   `json:"completed"`
}

type SenderMetrics struct {
	SentBatches        uint64 `json:"sent_batches_total"`
	SentOperations     uint64 `json:"sent_operations_total"`
	SentBytes          uint64 `json:"sent_bytes_total"`
	FailedSendAttempts uint64 `json:"failed_send_attempts_total"`
}

func New(
	dir string,
	localServer *server.Server,
	store *storage.Store,
	traceSender *sender.Sender,
	logger Logger,
) *Collector {
	return &Collector{
		dir:       dir,
		startedAt: time.Now(),
		now:       time.Now,
		server:    localServer,
		store:     store,
		sender:    traceSender,
		logger:    logger,
		interval:  time.Second,
	}
}

func (c *Collector) Run(ctx context.Context) error {
	c.writeAndLog()

	ticker := time.NewTicker(c.interval)
	defer ticker.Stop()

	for {
		select {
		case <-ctx.Done():
			return ctx.Err()
		case <-ticker.C:
			c.writeAndLog()
		}
	}
}

func (c *Collector) Snapshot() (Snapshot, error) {
	var memory runtime.MemStats
	runtime.ReadMemStats(&memory)

	serverMetrics := c.server.Metrics()
	storeMetrics := c.store.Metrics()
	senderMetrics := c.sender.Metrics()
	state, err := storage.NewStateStore(c.dir).Load()
	if err != nil {
		return Snapshot{}, fmt.Errorf("read metrics state: %w", err)
	}

	entries, err := os.ReadDir(c.dir)
	if err != nil {
		return Snapshot{}, fmt.Errorf("read metrics directory: %w", err)
	}

	hourlyFiles, hourlyBytes, errorLogFiles, err := collectFileMetrics(entries)
	if err != nil {
		return Snapshot{}, err
	}

	snapshot := Snapshot{
		CollectedAt:   c.now(),
		UptimeSeconds: c.now().Sub(c.startedAt).Seconds(),
		Process: ProcessMetrics{
			Goroutines:      runtime.NumGoroutine(),
			HeapAllocBytes:  memory.HeapAlloc,
			HeapSysBytes:    memory.HeapSys,
			StackInuseBytes: memory.StackInuse,
			RSSBytes:        readRSSBytes(),
		},
		Server: ServerMetrics{
			Connections:      serverMetrics.Connections,
			IdleConnections:  serverMetrics.IdleConnections,
			ConnectionSlots:  serverMetrics.ConnectionSlots,
			ConnectionLimit:  serverMetrics.ConnectionLimit,
			QueuedRecords:    serverMetrics.QueuedRecords,
			QueueCapacity:    serverMetrics.QueueCapacity,
			InputBudgetUsed:  serverMetrics.InputBudgetUsed,
			InputBudgetTotal: serverMetrics.InputBudgetTotal,
		},
		Storage: StorageMetrics{
			HourlyFiles:     len(hourlyFiles),
			HourlyBytes:     hourlyBytes,
			ErrorLogFiles:   errorLogFiles,
			AppendedRecords: storeMetrics.AppendedRecords,
			AppendedBytes:   storeMetrics.AppendedBytes,
			State: StateMetrics{
				File:      state.File,
				Offset:    state.Offset,
				Completed: state.Completed,
			},
		},
		Sender: SenderMetrics{
			SentBatches:        senderMetrics.SentBatches,
			SentOperations:     senderMetrics.SentOperations,
			SentBytes:          senderMetrics.SentBytes,
			FailedSendAttempts: senderMetrics.FailedSendAttempts,
		},
	}

	if len(hourlyFiles) > 0 {
		snapshot.Storage.OldestHourlyFile = hourlyFiles[0]
		snapshot.Storage.NewestHourlyFile = hourlyFiles[len(hourlyFiles)-1]
	}

	return snapshot, nil
}

func collectFileMetrics(entries []os.DirEntry) ([]string, int64, int, error) {
	hourlyFiles := make([]string, 0)
	var hourlyBytes int64
	errorLogFiles := 0

	for _, entry := range entries {
		if !entry.Type().IsRegular() {
			continue
		}

		info, err := entry.Info()
		if os.IsNotExist(err) {
			continue
		}
		if err != nil {
			return nil, 0, 0, fmt.Errorf("stat metrics file %q: %w", entry.Name(), err)
		}
		if !info.Mode().IsRegular() {
			continue
		}

		if logging.IsErrorLogFileName(entry.Name()) {
			errorLogFiles++
		}
		if storage.ValidTraceFileName(entry.Name()) {
			hourlyFiles = append(hourlyFiles, entry.Name())
			hourlyBytes += info.Size()
		}
	}

	sort.Strings(hourlyFiles)

	return hourlyFiles, hourlyBytes, errorLogFiles, nil
}

func (c *Collector) Write() error {
	snapshot, err := c.Snapshot()
	if err != nil {
		return err
	}

	data, err := json.MarshalIndent(snapshot, "", "  ")
	if err != nil {
		return err
	}
	data = append(data, '\n')

	temporary, err := os.CreateTemp(c.dir, ".local-proxy-metrics-*")
	if err != nil {
		return fmt.Errorf("create temporary metrics file: %w", err)
	}
	temporaryPath := temporary.Name()
	defer os.Remove(temporaryPath)

	if err := temporary.Chmod(0o600); err != nil {
		temporary.Close()

		return err
	}
	if _, err := temporary.Write(data); err != nil {
		temporary.Close()

		return err
	}
	if err := temporary.Close(); err != nil {
		return err
	}

	return os.Rename(temporaryPath, filepath.Join(c.dir, FileName))
}

func (c *Collector) writeAndLog() {
	if err := c.Write(); err != nil {
		now := c.now()
		if !c.lastWriteErrorAt.IsZero() && now.Sub(c.lastWriteErrorAt) < time.Minute {
			return
		}

		c.lastWriteErrorAt = now
		c.logger.Error("failed to write local proxy metrics", "error", err)

		return
	}

	c.lastWriteErrorAt = time.Time{}
}

func readRSSBytes() int64 {
	data, err := os.ReadFile("/proc/self/statm")
	if err != nil {
		return 0
	}

	fields := strings.Fields(string(data))
	if len(fields) < 2 {
		return 0
	}

	pages, err := strconv.ParseInt(fields[1], 10, 64)
	if err != nil {
		return 0
	}

	return pages * int64(os.Getpagesize())
}
