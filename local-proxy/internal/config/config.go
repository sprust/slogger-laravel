package config

import (
	"encoding/hex"
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

var envReferencePattern = regexp.MustCompile(`^\$\{[A-Za-z_][A-Za-z0-9_]*\}`)
var envKeyPattern = regexp.MustCompile(`^[A-Za-z_][A-Za-z0-9_]*$`)

func envKeyName(raw string) string {
	key := strings.TrimSpace(raw)
	key = strings.TrimSpace(strings.TrimPrefix(key, "export "))
	if !envKeyPattern.MatchString(key) {
		return ""
	}

	return key
}

func readEnv(envPath string) (map[string]string, error) {
	contents, err := os.ReadFile(envPath)
	if err != nil {
		return nil, fmt.Errorf("read env file %q", envPath)
	}

	placeholderPrefix := "__SLOGGER_PROCESS_ENV_"
	for strings.Contains(string(contents), placeholderPrefix) {
		placeholderPrefix += "_"
	}

	expanded, replacerArgs, keyNames := expandProcessEnvironment(string(contents), placeholderPrefix)
	parsed, err := godotenv.Unmarshal(expanded)
	if err != nil {
		return nil, fmt.Errorf("read env file %q", envPath)
	}

	replacer := strings.NewReplacer(replacerArgs...)
	values := make(map[string]string, len(parsed))
	for key, value := range parsed {
		if original, exists := keyNames[key]; exists {
			key = original
		}
		values[key] = replacer.Replace(value)
	}

	return values, nil
}

type envAssignmentState struct {
	definedKeys       map[string]struct{}
	keyNames          map[string]string
	placeholderPrefix string
	keyBuffer         strings.Builder
	collectingKey     bool
	currentKey        string
	inValue           bool
	atValueStart      bool
	inComment         bool
}

func expandProcessEnvironment(contents, placeholderPrefix string) (string, []string, map[string]string) {
	var expanded strings.Builder
	expanded.Grow(len(contents))

	replacerArgs := make([]string, 0)
	assignment := envAssignmentState{
		definedKeys:       make(map[string]struct{}),
		keyNames:          make(map[string]string),
		placeholderPrefix: placeholderPrefix,
		collectingKey:     true,
	}
	singleQuoted := false
	doubleQuoted := false

	for index := 0; index < len(contents); {
		char := contents[index]

		if envLineEnded(char, singleQuoted, doubleQuoted) {
			assignment.finishLine(&expanded)
			expanded.WriteByte(char)
			index++

			continue
		}

		next, single, double, copied := copyLiteralEnvToken(
			contents, index, assignment.inComment, singleQuoted, doubleQuoted, &expanded,
		)
		if copied {
			index = next
			singleQuoted = single
			doubleQuoted = double

			continue
		}

		if !doubleQuoted {
			next, quote, copied := assignment.copyUnquotedToken(char, index, &expanded)
			if copied {
				index = next
				singleQuoted = quote == '\''
				doubleQuoted = quote == '"'

				continue
			}
		}

		replacement, size, args := expandProcessReference(
			contents, index, placeholderPrefix, replacerArgs, assignment.definedKeys,
		)
		replacerArgs = args
		if size > 0 {
			expanded.WriteString(replacement)
			index += size

			continue
		}

		expanded.WriteByte(char)
		index++
	}

	assignment.flushKey(&expanded)

	return expanded.String(), replacerArgs, assignment.keyNames
}

func envLineEnded(char byte, singleQuoted, doubleQuoted bool) bool {
	return char == '\n' && !singleQuoted && !doubleQuoted
}

func (s *envAssignmentState) finishLine(expanded *strings.Builder) {
	if s.collectingKey {
		expanded.WriteString(s.keyBuffer.String())
	} else if s.inValue && s.currentKey != "" {
		s.definedKeys[s.currentKey] = struct{}{}
	}
	s.inValue = false
	s.atValueStart = false
	s.inComment = false
	s.collectingKey = true
	s.currentKey = ""
	s.keyBuffer.Reset()
}

func (s *envAssignmentState) copyUnquotedToken(
	char byte,
	index int,
	expanded *strings.Builder,
) (int, byte, bool) {
	switch {
	case char == '#':
		s.flushKey(expanded)
		s.inComment = true
	case char == '=' && !s.inValue:
		if s.collectingKey {
			s.currentKey = envKeyName(s.keyBuffer.String())
			s.writeKey(expanded)
			s.collectingKey = false
		}
		s.inValue = true
		s.atValueStart = true
	case s.atValueStart && (char == ' ' || char == '\t'):
	case s.atValueStart && (char == '\'' || char == '"'):
		s.atValueStart = false
		expanded.WriteByte(char)

		return index + 1, char, true
	default:
		s.atValueStart = false
		if s.collectingKey {
			s.keyBuffer.WriteByte(char)

			return index + 1, 0, true
		}

		return index, 0, false
	}

	expanded.WriteByte(char)

	return index + 1, 0, true
}

func (s *envAssignmentState) writeKey(expanded *strings.Builder) {
	if s.currentKey == "" {
		expanded.WriteString(s.keyBuffer.String())

		return
	}

	internal := internalEnvKey(s.currentKey, s.placeholderPrefix)
	s.keyNames[internal] = s.currentKey
	expanded.WriteString(internal)
}

func (s *envAssignmentState) flushKey(expanded *strings.Builder) {
	if !s.collectingKey {
		return
	}

	expanded.WriteString(s.keyBuffer.String())
	s.collectingKey = false
}

func internalEnvKey(key, placeholderPrefix string) string {
	return placeholderPrefix + "KEY_" + strings.ToUpper(hex.EncodeToString([]byte(key))) + "__"
}

func copyLiteralEnvToken(
	contents string,
	index int,
	inComment, singleQuoted, doubleQuoted bool,
	expanded *strings.Builder,
) (int, bool, bool, bool) {
	char := contents[index]
	if inComment || singleQuoted {
		expanded.WriteByte(char)

		return index + 1, singleQuoted && char != '\'', doubleQuoted, true
	}
	if !doubleQuoted {
		return index, false, false, false
	}

	next, closed, copied := copyDoubleQuotedToken(contents, index, expanded)

	return next, false, !closed, copied
}

func copyDoubleQuotedToken(contents string, index int, expanded *strings.Builder) (int, bool, bool) {
	if next, copied := copyDoubleQuotedEscape(contents, index, expanded); copied {
		return next, false, true
	}
	if contents[index] != '"' {
		return index, false, false
	}

	expanded.WriteByte('"')

	return index + 1, true, true
}

func copyDoubleQuotedEscape(contents string, index int, expanded *strings.Builder) (int, bool) {
	if contents[index] != '\\' || index+1 >= len(contents) {
		return index, false
	}

	expanded.WriteString(contents[index : index+2])

	return index + 2, true
}

func expandProcessReference(
	contents string,
	index int,
	placeholderPrefix string,
	replacerArgs []string,
	definedKeys map[string]struct{},
) (string, int, []string) {
	if contents[index] != '$' {
		return "", 0, replacerArgs
	}

	reference := envReferencePattern.FindString(contents[index:])
	if reference == "" {
		placeholder, args := newEnvPlaceholder("$", placeholderPrefix, replacerArgs)

		return placeholder, 1, args
	}

	key := reference[2 : len(reference)-1]
	value, exists := os.LookupEnv(key)
	if exists {
		placeholder, args := newEnvPlaceholder(value, placeholderPrefix, replacerArgs)

		return placeholder, len(reference), args
	}
	if _, exists := definedKeys[key]; exists {
		internal := "${" + internalEnvKey(key, placeholderPrefix) + "}"

		return internal, len(reference), replacerArgs
	}

	placeholder, args := newEnvPlaceholder(reference, placeholderPrefix, replacerArgs)

	return placeholder, len(reference), args
}

func newEnvPlaceholder(value, placeholderPrefix string, replacerArgs []string) (string, []string) {
	placeholder := fmt.Sprintf("%s%d__", placeholderPrefix, len(replacerArgs)/2)

	return placeholder, append(replacerArgs, placeholder, value)
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
