package config

import (
	"errors"
	"fmt"
	"net"
	"net/url"
	"os"
	"path"
	"path/filepath"
	"regexp"
	"slices"
	"strconv"
	"strings"

	"github.com/joho/godotenv"
)

const (
	DefaultTracesPath     = "storage/slogger/traces"
	DefaultRetentionHours = 24
	MaxRetentionHours     = 2562047
	DefaultMaxSpoolBytes  = int64(1 << 30)
)

type Config struct {
	RootDir        string
	Token          string
	UpstreamURL    string
	TracesPath     string
	TracesDir      string
	RetentionHours int
	MaxSpoolBytes  int64
}

func Load(envPath, rootDir string) (Config, error) {
	values, err := readEnv(envPath)
	if err != nil {
		return Config{}, err
	}

	rootDir, err = filepath.Abs(rootDir)
	if err != nil {
		return Config{}, fmt.Errorf("resolve project root: %w", err)
	}

	token := value(values, "SLOGGER_TOKEN")
	if token == "" {
		return Config{}, errors.New("SLOGGER_TOKEN is required")
	}

	upstreamURL := value(values, "SLOGGER_DISPATCHER_QUEUE_SOCKET_CLIENT_URL")
	if err := validateUpstreamURL(upstreamURL); err != nil {
		return Config{}, err
	}

	tracesPath := DefaultTracesPath
	if configuredPath, exists := valueIfExists(values, "SLOGGER_LOCAL_PROXY_TRACES_PATH"); exists {
		tracesPath = configuredPath
	}

	tracesPath, err = NormalizeTracesPath(tracesPath)
	if err != nil {
		return Config{}, err
	}

	retentionHours := DefaultRetentionHours
	if raw := value(values, "SLOGGER_LOCAL_PROXY_RETENTION_HOURS"); raw != "" {
		retentionHours, err = strconv.Atoi(raw)
		if err != nil || retentionHours < 1 || retentionHours > MaxRetentionHours {
			return Config{}, fmt.Errorf("SLOGGER_LOCAL_PROXY_RETENTION_HOURS must be between 1 and %d hours", MaxRetentionHours)
		}
	}

	maxSpoolBytes := DefaultMaxSpoolBytes
	if raw := value(values, "SLOGGER_LOCAL_PROXY_MAX_SPOOL_BYTES"); raw != "" {
		maxSpoolBytes, err = strconv.ParseInt(raw, 10, 64)
		if err != nil || maxSpoolBytes <= 0 {
			return Config{}, errors.New("SLOGGER_LOCAL_PROXY_MAX_SPOOL_BYTES must be a positive integer")
		}
	}

	tracesDir := filepath.Join(rootDir, filepath.FromSlash(tracesPath))
	rel, err := filepath.Rel(rootDir, tracesDir)
	if err != nil || rel == ".." || strings.HasPrefix(rel, ".."+string(filepath.Separator)) {
		return Config{}, errors.New("traces path must stay inside project root")
	}

	return Config{
		RootDir:        rootDir,
		Token:          token,
		UpstreamURL:    upstreamURL,
		TracesPath:     tracesPath,
		TracesDir:      tracesDir,
		RetentionHours: retentionHours,
		MaxSpoolBytes:  maxSpoolBytes,
	}, nil
}

var envReferencePattern = regexp.MustCompile(`\$\{[A-Za-z_][A-Za-z0-9_]*\}`)

func readEnv(envPath string) (map[string]string, error) {
	contents, err := os.ReadFile(envPath)
	if err != nil {
		return nil, fmt.Errorf("read env file %q", envPath)
	}

	placeholderPrefix := "__SLOGGER_PROCESS_ENV_"
	for strings.Contains(string(contents), placeholderPrefix) {
		placeholderPrefix += "_"
	}

	replacementCount := 0
	replacerArgs := make([]string, 0)
	expanded := envReferencePattern.ReplaceAllStringFunc(string(contents), func(reference string) string {
		key := reference[2 : len(reference)-1]
		value, exists := os.LookupEnv(key)
		if !exists {
			return reference
		}

		placeholder := fmt.Sprintf("%s%d__", placeholderPrefix, replacementCount)
		replacementCount++
		replacerArgs = append(replacerArgs, placeholder, value)

		return placeholder
	})

	values, err := godotenv.Unmarshal(expanded)
	if err != nil {
		return nil, fmt.Errorf("read env file %q", envPath)
	}

	replacer := strings.NewReplacer(replacerArgs...)
	for key, value := range values {
		values[key] = replacer.Replace(value)
	}

	return values, nil
}

func NormalizeTracesPath(value string) (string, error) {
	value = strings.TrimSpace(value)
	value = strings.Trim(value, "/")
	if value == "" {
		return "", errors.New("SLOGGER_LOCAL_PROXY_TRACES_PATH must not be empty")
	}

	if slices.Contains(strings.Split(value, "/"), "..") {
		return "", errors.New("SLOGGER_LOCAL_PROXY_TRACES_PATH must not contain '..'")
	}

	value = path.Clean(value)
	if value == "." || value == "" || path.IsAbs(value) {
		return "", errors.New("SLOGGER_LOCAL_PROXY_TRACES_PATH must be a relative directory")
	}

	return value, nil
}

func valueIfExists(values map[string]string, key string) (string, bool) {
	if value, ok := os.LookupEnv(key); ok {
		return strings.TrimSpace(value), true
	}

	value, ok := values[key]

	return strings.TrimSpace(value), ok
}

func value(values map[string]string, key string) string {
	if value, ok := os.LookupEnv(key); ok {
		return strings.TrimSpace(value)
	}

	return strings.TrimSpace(values[key])
}

func validateUpstreamURL(raw string) error {
	if raw == "" {
		return errors.New("SLOGGER_DISPATCHER_QUEUE_SOCKET_CLIENT_URL is required")
	}

	parsed, err := url.Parse(raw)
	if err != nil || parsed.Scheme != "tcp" || parsed.Hostname() == "" || parsed.Path != "" || parsed.RawQuery != "" {
		return errors.New("SLOGGER_DISPATCHER_QUEUE_SOCKET_CLIENT_URL must be a valid tcp://host:port URL")
	}

	port, err := strconv.Atoi(parsed.Port())
	if err != nil || port < 1 || port > 65535 {
		return errors.New("SLOGGER_DISPATCHER_QUEUE_SOCKET_CLIENT_URL must contain a valid port")
	}

	if _, _, err := net.SplitHostPort(parsed.Host); err != nil {
		return fmt.Errorf("SLOGGER_DISPATCHER_QUEUE_SOCKET_CLIENT_URL must be a valid tcp://host:port URL: %w", err)
	}

	return nil
}
