package config

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestNormalizeTracesPath(t *testing.T) {
	t.Parallel()

	for _, value := range []string{
		DefaultTracesPath,
		"/storage/slogger/traces/",
		"/storage/slogger/traces",
		"storage/slogger/traces/",
		"//storage//slogger/traces//",
	} {
		normalized, err := NormalizeTracesPath(value)
		if err != nil {
			t.Fatalf("NormalizeTracesPath(%q): %v", value, err)
		}

		if normalized != DefaultTracesPath {
			t.Fatalf("NormalizeTracesPath(%q) = %q", value, normalized)
		}
	}
}

func TestNormalizeTracesPathRejectsEscapes(t *testing.T) {
	t.Parallel()

	for _, value := range []string{"", "/", ".", "././", "../traces", "storage/../traces"} {
		if _, err := NormalizeTracesPath(value); err == nil {
			t.Fatalf("NormalizeTracesPath(%q) unexpectedly succeeded", value)
		}
	}
}

func TestLoad(t *testing.T) {
	clearEnvironment(t, "SLOGGER_TOKEN")
	clearEnvironment(t, "SLOGGER_DISPATCHER_QUEUE_SOCKET_CLIENT_URL")
	clearEnvironment(t, "SLOGGER_LOCAL_PROXY_TRACES_PATH")
	clearEnvironment(t, "SLOGGER_LOCAL_PROXY_RETENTION_HOURS")
	clearEnvironment(t, "SLOGGER_LOCAL_PROXY_MAX_SPOOL_BYTES")

	root := t.TempDir()
	envPath := filepath.Join(root, ".env")
	contents := "SLOGGER_TOKEN=file-token\n" +
		"SLOGGER_DISPATCHER_QUEUE_SOCKET_CLIENT_URL=tcp://slogger:9002\n" +
		"SLOGGER_LOCAL_PROXY_TRACES_PATH=/storage/slogger/traces/\n" +
		"SLOGGER_LOCAL_PROXY_RETENTION_HOURS=36\n" +
		"SLOGGER_LOCAL_PROXY_MAX_SPOOL_BYTES=2048\n"
	if err := os.WriteFile(envPath, []byte(contents), 0o600); err != nil {
		t.Fatal(err)
	}

	t.Setenv("SLOGGER_TOKEN", "process-token")

	configuration, err := Load(envPath, root)
	if err != nil {
		t.Fatal(err)
	}

	if configuration.Token != "process-token" {
		t.Fatalf("token = %q", configuration.Token)
	}

	if configuration.TracesPath != DefaultTracesPath {
		t.Fatalf("traces path = %q", configuration.TracesPath)
	}

	if configuration.TracesDir != filepath.Join(root, DefaultTracesPath) {
		t.Fatalf("traces dir = %q", configuration.TracesDir)
	}

	if configuration.RetentionHours != 36 {
		t.Fatalf("retention = %d", configuration.RetentionHours)
	}

	if configuration.MaxSpoolBytes != 2048 {
		t.Fatalf("max spool bytes = %d", configuration.MaxSpoolBytes)
	}
}

func TestLoadRequiresUpstreamURL(t *testing.T) {
	clearEnvironment(t, "SLOGGER_DISPATCHER_QUEUE_SOCKET_CLIENT_URL")
	t.Setenv("SLOGGER_TOKEN", "token")

	envPath := filepath.Join(t.TempDir(), ".env")
	if err := os.WriteFile(envPath, nil, 0o600); err != nil {
		t.Fatal(err)
	}

	if _, err := Load(envPath, t.TempDir()); err == nil {
		t.Fatal("Load unexpectedly succeeded without upstream URL")
	}
}

func TestLoadRetentionDurationBounds(t *testing.T) {
	clearEnvironment(t, "SLOGGER_LOCAL_PROXY_RETENTION_HOURS")
	clearEnvironment(t, "SLOGGER_LOCAL_PROXY_TRACES_PATH")
	t.Setenv("SLOGGER_TOKEN", "token")
	t.Setenv("SLOGGER_DISPATCHER_QUEUE_SOCKET_CLIENT_URL", "tcp://slogger:9002")

	root := t.TempDir()
	envPath := filepath.Join(root, ".env")

	for _, test := range []struct {
		value string
		valid bool
	}{
		{value: "2562047", valid: true},
		{value: "2562048", valid: false},
	} {
		contents := "SLOGGER_LOCAL_PROXY_RETENTION_HOURS=" + test.value + "\n"
		if err := os.WriteFile(envPath, []byte(contents), 0o600); err != nil {
			t.Fatal(err)
		}

		configuration, err := Load(envPath, root)
		if test.valid && err != nil {
			t.Fatal(err)
		}
		if !test.valid && err == nil {
			t.Fatalf("retention %s unexpectedly accepted", test.value)
		}
		if test.valid && configuration.RetentionHours != MaxRetentionHours {
			t.Fatalf("retention = %d", configuration.RetentionHours)
		}
	}
}

func TestLoadMaxSpoolBytes(t *testing.T) {
	clearEnvironment(t, "SLOGGER_LOCAL_PROXY_MAX_SPOOL_BYTES")
	clearEnvironment(t, "SLOGGER_LOCAL_PROXY_TRACES_PATH")
	t.Setenv("SLOGGER_TOKEN", "token")
	t.Setenv("SLOGGER_DISPATCHER_QUEUE_SOCKET_CLIENT_URL", "tcp://slogger:9002")

	root := t.TempDir()
	envPath := filepath.Join(root, ".env")
	if err := os.WriteFile(envPath, nil, 0o600); err != nil {
		t.Fatal(err)
	}

	configuration, err := Load(envPath, root)
	if err != nil || configuration.MaxSpoolBytes != DefaultMaxSpoolBytes {
		t.Fatalf("default max spool bytes = %d, error = %v", configuration.MaxSpoolBytes, err)
	}

	for _, value := range []string{"0", "-1", "invalid", "9223372036854775808"} {
		if err := os.WriteFile(envPath, []byte("SLOGGER_LOCAL_PROXY_MAX_SPOOL_BYTES="+value+"\n"), 0o600); err != nil {
			t.Fatal(err)
		}
		if _, err := Load(envPath, root); err == nil {
			t.Fatalf("max spool bytes %q unexpectedly accepted", value)
		}
	}
}

func TestLoadRejectsExplicitlyEmptyTracesPath(t *testing.T) {
	clearEnvironment(t, "SLOGGER_LOCAL_PROXY_TRACES_PATH")
	t.Setenv("SLOGGER_TOKEN", "token")
	t.Setenv("SLOGGER_DISPATCHER_QUEUE_SOCKET_CLIENT_URL", "tcp://slogger:9002")

	root := t.TempDir()
	envPath := filepath.Join(root, ".env")
	if err := os.WriteFile(envPath, nil, 0o600); err != nil {
		t.Fatal(err)
	}

	configuration, err := Load(envPath, root)
	if err != nil || configuration.TracesPath != DefaultTracesPath {
		t.Fatalf("configuration = %+v, error = %v", configuration, err)
	}

	t.Setenv("SLOGGER_LOCAL_PROXY_TRACES_PATH", "")
	if _, err := Load(envPath, root); err == nil {
		t.Fatal("explicitly empty traces path was accepted")
	}
}

func TestLoadSanitizesEnvParseError(t *testing.T) {
	root := t.TempDir()
	envPath := filepath.Join(root, ".env")
	secret := "REVIEW_CONTROL_SECRET"
	if err := os.WriteFile(envPath, []byte("BROKEN=\""+secret+"\n"), 0o600); err != nil {
		t.Fatal(err)
	}

	_, err := Load(envPath, root)
	if err == nil {
		t.Fatal("malformed env unexpectedly succeeded")
	}
	if strings.Contains(err.Error(), secret) {
		t.Fatalf("error leaked env contents: %v", err)
	}
}

func TestLoadInterpolatesEnvironmentReferences(t *testing.T) {
	clearEnvironment(t, "SLOGGER_LOCAL_PROXY_TRACES_PATH")
	clearEnvironment(t, "SLOGGER_LOCAL_PROXY_MAX_SPOOL_BYTES")
	t.Setenv("SLOGGER_TOKEN", "token")
	t.Setenv("REVIEW_UP_HOST", "localhost")

	root := t.TempDir()
	envPath := filepath.Join(root, ".env")
	contents := "REVIEW_UP_HOST=file-host\n" +
		"SLOGGER_DISPATCHER_QUEUE_SOCKET_CLIENT_URL=tcp://${REVIEW_UP_HOST}:9000\n"
	if err := os.WriteFile(envPath, []byte(contents), 0o600); err != nil {
		t.Fatal(err)
	}

	configuration, err := Load(envPath, root)
	if err != nil {
		t.Fatal(err)
	}
	if configuration.UpstreamURL != "tcp://localhost:9000" {
		t.Fatalf("upstream URL = %q", configuration.UpstreamURL)
	}
}

func TestReadEnvMatchesLaravelReferenceQuoting(t *testing.T) {
	const processValue = "process-host\" # ${REVIEW_OTHER_VALUE}"
	t.Setenv("REVIEW_PROCESS_VALUE", processValue)
	t.Setenv("REVIEW_OTHER_VALUE", "other-host")

	envPath := filepath.Join(t.TempDir(), ".env")
	contents := "UNQUOTED=${REVIEW_PROCESS_VALUE}\n" +
		"DOUBLE=\"${REVIEW_PROCESS_VALUE}\"\n" +
		"SINGLE='${REVIEW_PROCESS_VALUE}'\n" +
		"DOUBLE_ESCAPED=\"\\${REVIEW_PROCESS_VALUE}\"\n" +
		"SINGLE_ESCAPED='\\${REVIEW_PROCESS_VALUE}'\n" +
		"UNQUOTED_ESCAPED=\\${REVIEW_PROCESS_VALUE}\n" +
		"DOUBLE_EVEN_ESCAPED=\"\\\\${REVIEW_PROCESS_VALUE}\"\n"
	if err := os.WriteFile(envPath, []byte(contents), 0o600); err != nil {
		t.Fatal(err)
	}

	values, err := readEnv(envPath)
	if err != nil {
		t.Fatal(err)
	}

	expected := map[string]string{
		"UNQUOTED":            processValue,
		"DOUBLE":              processValue,
		"SINGLE":              "${REVIEW_PROCESS_VALUE}",
		"DOUBLE_ESCAPED":      "${REVIEW_PROCESS_VALUE}",
		"SINGLE_ESCAPED":      "\\${REVIEW_PROCESS_VALUE}",
		"UNQUOTED_ESCAPED":    "\\" + processValue,
		"DOUBLE_EVEN_ESCAPED": "\\" + processValue,
	}
	for key, want := range expected {
		if values[key] != want {
			t.Errorf("%s = %q, want %q", key, values[key], want)
		}
	}
}

func TestReadEnvPreservesBareDollarAndUndefinedReferences(t *testing.T) {
	t.Setenv("REVIEW_PROCESS_VALUE", "process-value")
	clearEnvironment(t, "REVIEW_UNDEFINED_VALUE")

	envPath := filepath.Join(t.TempDir(), ".env")
	contents := "REVIEW_FILE_VALUE=file-value\n" +
		"BARE=abc$REVIEW_PROCESS_VALUE\n" +
		"DOUBLE_BARE=\"abc$REVIEW_PROCESS_VALUE\"\n" +
		"BARE_FILE=abc$REVIEW_FILE_VALUE\n" +
		"UNDEFINED=abc${REVIEW_UNDEFINED_VALUE}\n" +
		"DOUBLE_UNDEFINED=\"abc${REVIEW_UNDEFINED_VALUE}\"\n" +
		"FILE_REFERENCE=abc${REVIEW_FILE_VALUE}\n"
	if err := os.WriteFile(envPath, []byte(contents), 0o600); err != nil {
		t.Fatal(err)
	}

	values, err := readEnv(envPath)
	if err != nil {
		t.Fatal(err)
	}

	expected := map[string]string{
		"BARE":             "abc$REVIEW_PROCESS_VALUE",
		"DOUBLE_BARE":      "abc$REVIEW_PROCESS_VALUE",
		"BARE_FILE":        "abc$REVIEW_FILE_VALUE",
		"UNDEFINED":        "abc${REVIEW_UNDEFINED_VALUE}",
		"DOUBLE_UNDEFINED": "abc${REVIEW_UNDEFINED_VALUE}",
		"FILE_REFERENCE":   "abcfile-value",
	}
	for key, want := range expected {
		if values[key] != want {
			t.Errorf("%s = %q, want %q", key, values[key], want)
		}
	}
}

func TestReadEnvDoesNotLeakProcessValuesInParseErrors(t *testing.T) {
	const secret = "REVIEW_PROCESS_SECRET"
	t.Setenv("REVIEW_PROCESS_VALUE", secret)

	envPath := filepath.Join(t.TempDir(), ".env")
	if err := os.WriteFile(envPath, []byte("BROKEN=\"${REVIEW_PROCESS_VALUE}\n"), 0o600); err != nil {
		t.Fatal(err)
	}

	_, err := readEnv(envPath)
	if err == nil {
		t.Fatal("malformed env unexpectedly succeeded")
	}
	if strings.Contains(err.Error(), secret) {
		t.Fatalf("error leaked process environment contents: %v", err)
	}
}

func TestLoadInterpolatesMixedCaseFileReference(t *testing.T) {
	clearEnvironment(t, "upHost")
	clearEnvironment(t, "SLOGGER_LOCAL_PROXY_TRACES_PATH")
	t.Setenv("SLOGGER_TOKEN", "token")

	root := t.TempDir()
	envPath := filepath.Join(root, ".env")
	contents := "upHost=localhost\n" +
		"SLOGGER_DISPATCHER_QUEUE_SOCKET_CLIENT_URL=tcp://${upHost}:9000\n"
	if err := os.WriteFile(envPath, []byte(contents), 0o600); err != nil {
		t.Fatal(err)
	}

	configuration, err := Load(envPath, root)
	if err != nil {
		t.Fatal(err)
	}
	if configuration.UpstreamURL != "tcp://localhost:9000" {
		t.Fatalf("upstream URL = %q, want %q", configuration.UpstreamURL, "tcp://localhost:9000")
	}
}

func TestReadEnvResolvesMixedCaseReferencesByExactName(t *testing.T) {
	for _, key := range []string{"revMixed", "REV_MIXed", "REV_MIXED", "revLater"} {
		clearEnvironment(t, key)
	}

	envPath := filepath.Join(t.TempDir(), ".env")
	contents := "revMixed=lower\n" +
		"REV_MIXed=mixed\n" +
		"REV_MIXED=upper\n" +
		"LOWER=${revMixed}\n" +
		"MIXED=${REV_MIXed}\n" +
		"UPPER=${REV_MIXED}\n" +
		"revMixed=${revMixed}-again\n" +
		"REPEATED=${revMixed}\n" +
		"FORWARD=${revLater}\n" +
		"revLater=after\n"
	if err := os.WriteFile(envPath, []byte(contents), 0o600); err != nil {
		t.Fatal(err)
	}

	values, err := readEnv(envPath)
	if err != nil {
		t.Fatal(err)
	}

	expected := map[string]string{
		"revMixed":  "lower-again",
		"REV_MIXed": "mixed",
		"REV_MIXED": "upper",
		"LOWER":     "lower",
		"MIXED":     "mixed",
		"UPPER":     "upper",
		"REPEATED":  "lower-again",
		"FORWARD":   "${revLater}",
		"revLater":  "after",
	}
	for key, want := range expected {
		if values[key] != want {
			t.Errorf("%s = %q, want %q", key, values[key], want)
		}
	}
}

func TestReadEnvPreservesForwardReferences(t *testing.T) {
	clearEnvironment(t, "REVIEW_LATER")
	clearEnvironment(t, "REVIEW_COMMENTED")
	t.Setenv("REVIEW_PROCESS_LATER", "process-later")

	envPath := filepath.Join(t.TempDir(), ".env")
	contents := "FORWARD=abc${REVIEW_LATER}\n" +
		"REVIEW_LATER=after\n" +
		"BACKWARD=abc${REVIEW_LATER}\n" +
		"DOUBLE_FORWARD=\"abc${REVIEW_LATER_SECOND}\"\n" +
		"REVIEW_LATER_SECOND=after-second\n" +
		"SINGLE_FORWARD='abc${REVIEW_LATER_THIRD}'\n" +
		"REVIEW_LATER_THIRD=after-third\n" +
		"COMMENTED=abc${REVIEW_COMMENTED}\n" +
		"#REVIEW_COMMENTED=commented\n" +
		"PROCESS_FORWARD=abc${REVIEW_PROCESS_LATER}\n" +
		"REVIEW_PROCESS_LATER=file-later\n"
	if err := os.WriteFile(envPath, []byte(contents), 0o600); err != nil {
		t.Fatal(err)
	}

	values, err := readEnv(envPath)
	if err != nil {
		t.Fatal(err)
	}

	expected := map[string]string{
		"FORWARD":         "abc${REVIEW_LATER}",
		"REVIEW_LATER":    "after",
		"BACKWARD":        "abcafter",
		"DOUBLE_FORWARD":  "abc${REVIEW_LATER_SECOND}",
		"SINGLE_FORWARD":  "abc${REVIEW_LATER_THIRD}",
		"COMMENTED":       "abc${REVIEW_COMMENTED}",
		"PROCESS_FORWARD": "abcprocess-later",
	}
	for key, want := range expected {
		if values[key] != want {
			t.Errorf("%s = %q, want %q", key, values[key], want)
		}
	}
}

func TestReadEnvSelfReferences(t *testing.T) {
	clearEnvironment(t, "REVIEW_SELF")
	clearEnvironment(t, "REVIEW_SELF_REPEAT")
	clearEnvironment(t, "REVIEW_SELF_APPEND")

	envPath := filepath.Join(t.TempDir(), ".env")
	contents := "REVIEW_SELF=${REVIEW_SELF}\n" +
		"REVIEW_SELF_REPEAT=first\n" +
		"REVIEW_SELF_REPEAT=${REVIEW_SELF_REPEAT}\n" +
		"REVIEW_SELF_APPEND=first\n" +
		"REVIEW_SELF_APPEND=${REVIEW_SELF_APPEND}-second\n"
	if err := os.WriteFile(envPath, []byte(contents), 0o600); err != nil {
		t.Fatal(err)
	}

	values, err := readEnv(envPath)
	if err != nil {
		t.Fatal(err)
	}

	expected := map[string]string{
		"REVIEW_SELF":        "${REVIEW_SELF}",
		"REVIEW_SELF_REPEAT": "first",
		"REVIEW_SELF_APPEND": "first-second",
	}
	for key, want := range expected {
		if values[key] != want {
			t.Errorf("%s = %q, want %q", key, values[key], want)
		}
	}
}

func TestReadEnvMultilineReferences(t *testing.T) {
	clearEnvironment(t, "REVIEW_MULTI_LATER")
	t.Setenv("REVIEW_MULTI_PROCESS", "process-multi")

	envPath := filepath.Join(t.TempDir(), ".env")
	contents := "REVIEW_MULTI_EARLIER=before\n" +
		"MULTILINE_BACKWARD=\"first\n${REVIEW_MULTI_EARLIER}\"\n" +
		"MULTILINE_FORWARD=\"first\n${REVIEW_MULTI_LATER}\"\n" +
		"REVIEW_MULTI_LATER=after\n" +
		"MULTILINE_PROCESS=\"first\n${REVIEW_MULTI_PROCESS}\"\n" +
		"MULTILINE_SELF=\"first\n${MULTILINE_SELF}\"\n"
	if err := os.WriteFile(envPath, []byte(contents), 0o600); err != nil {
		t.Fatal(err)
	}

	values, err := readEnv(envPath)
	if err != nil {
		t.Fatal(err)
	}

	expected := map[string]string{
		"MULTILINE_BACKWARD": "first\nbefore",
		"MULTILINE_FORWARD":  "first\n${REVIEW_MULTI_LATER}",
		"MULTILINE_PROCESS":  "first\nprocess-multi",
		"MULTILINE_SELF":     "first\n${MULTILINE_SELF}",
	}
	for key, want := range expected {
		if values[key] != want {
			t.Errorf("%s = %q, want %q", key, values[key], want)
		}
	}
}

func TestLoadPreservesForwardTracesPathReference(t *testing.T) {
	clearEnvironment(t, "SLOGGER_LOCAL_PROXY_TRACES_PATH")
	clearEnvironment(t, "REV_LATER")
	t.Setenv("SLOGGER_TOKEN", "token")
	t.Setenv("SLOGGER_DISPATCHER_QUEUE_SOCKET_CLIENT_URL", "tcp://slogger:9002")

	root := t.TempDir()
	envPath := filepath.Join(root, ".env")
	contents := "SLOGGER_LOCAL_PROXY_TRACES_PATH=${REV_LATER}\n" +
		"REV_LATER=after\n"
	if err := os.WriteFile(envPath, []byte(contents), 0o600); err != nil {
		t.Fatal(err)
	}

	configuration, err := Load(envPath, root)
	if err != nil {
		t.Fatal(err)
	}
	if configuration.TracesPath != "${REV_LATER}" {
		t.Fatalf("traces path = %q, want literal ${REV_LATER}", configuration.TracesPath)
	}
	if configuration.TracesDir != filepath.Join(root, "${REV_LATER}") {
		t.Fatalf("traces dir = %q", configuration.TracesDir)
	}
}

func TestReadEnvPreservesHashInsideDoubleQuotes(t *testing.T) {
	t.Setenv("REV_HOST", "process_value")

	envPath := filepath.Join(t.TempDir(), ".env")
	contents := "COMMENT=\"text # comment\"\n" +
		"VALUE=\"x#${REV_HOST}\"\n" +
		"AFTER=${REV_HOST}\n"
	if err := os.WriteFile(envPath, []byte(contents), 0o600); err != nil {
		t.Fatal(err)
	}

	values, err := readEnv(envPath)
	if err != nil {
		t.Fatal(err)
	}

	expected := map[string]string{
		"COMMENT": "text # comment",
		"VALUE":   "x#process_value",
		"AFTER":   "process_value",
	}
	for key, want := range expected {
		if values[key] != want {
			t.Errorf("%s = %q, want %q", key, values[key], want)
		}
	}
}

func TestLoadExpandsProcessReferenceAfterHash(t *testing.T) {
	clearEnvironment(t, "SLOGGER_LOCAL_PROXY_TRACES_PATH")
	t.Setenv("SLOGGER_TOKEN", "token")
	t.Setenv("SLOGGER_DISPATCHER_QUEUE_SOCKET_CLIENT_URL", "tcp://slogger:9002")
	t.Setenv("REV_HOST", "process_value")

	root := t.TempDir()
	envPath := filepath.Join(root, ".env")
	contents := "SLOGGER_LOCAL_PROXY_TRACES_PATH=\"x#${REV_HOST}\"\n"
	if err := os.WriteFile(envPath, []byte(contents), 0o600); err != nil {
		t.Fatal(err)
	}

	configuration, err := Load(envPath, root)
	if err != nil {
		t.Fatal(err)
	}
	if configuration.TracesPath != "x#process_value" {
		t.Fatalf("traces path = %q, want %q", configuration.TracesPath, "x#process_value")
	}
}

func TestLoadRejectsInvalidFinalLineWithoutNewline(t *testing.T) {
	clearEnvironment(t, "SLOGGER_LOCAL_PROXY_TRACES_PATH")
	t.Setenv("SLOGGER_TOKEN", "token")
	t.Setenv("SLOGGER_DISPATCHER_QUEUE_SOCKET_CLIENT_URL", "tcp://slogger:9002")

	root := t.TempDir()
	envPath := filepath.Join(root, ".env")
	if err := os.WriteFile(envPath, []byte("BAD!"), 0o600); err != nil {
		t.Fatal(err)
	}

	if _, err := Load(envPath, root); err == nil {
		t.Fatal("invalid final line without newline was accepted")
	}
}

func TestReadEnvAcceptsValidFinalLineWithoutNewline(t *testing.T) {
	envPath := filepath.Join(t.TempDir(), ".env")
	if err := os.WriteFile(envPath, []byte("VALID=value"), 0o600); err != nil {
		t.Fatal(err)
	}

	values, err := readEnv(envPath)
	if err != nil {
		t.Fatal(err)
	}
	if values["VALID"] != "value" {
		t.Fatalf("VALID = %q, want %q", values["VALID"], "value")
	}
}

func clearEnvironment(t *testing.T, key string) {
	t.Helper()

	value, exists := os.LookupEnv(key)
	if err := os.Unsetenv(key); err != nil {
		t.Fatal(err)
	}

	t.Cleanup(func() {
		if exists {
			_ = os.Setenv(key, value)
		} else {
			_ = os.Unsetenv(key)
		}
	})
}
