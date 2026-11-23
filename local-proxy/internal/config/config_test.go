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
		"storage/slogger/traces",
		"/storage/slogger/traces/",
		"/storage/slogger/traces",
		"storage/slogger/traces/",
		"//storage//slogger/traces//",
	} {
		normalized, err := NormalizeTracesPath(value)
		if err != nil {
			t.Fatalf("NormalizeTracesPath(%q): %v", value, err)
		}

		if normalized != "storage/slogger/traces" {
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

	if configuration.TracesPath != "storage/slogger/traces" {
		t.Fatalf("traces path = %q", configuration.TracesPath)
	}

	if configuration.TracesDir != filepath.Join(root, "storage/slogger/traces") {
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
