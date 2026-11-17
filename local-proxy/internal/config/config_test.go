package config

import (
	"os"
	"path/filepath"
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

	for _, value := range []string{"", "/", ".", "../traces", "storage/../traces"} {
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

	root := t.TempDir()
	envPath := filepath.Join(root, ".env")
	contents := "SLOGGER_TOKEN=file-token\n" +
		"SLOGGER_DISPATCHER_QUEUE_SOCKET_CLIENT_URL=tcp://slogger:9002\n" +
		"SLOGGER_LOCAL_PROXY_TRACES_PATH=/storage/slogger/traces/\n" +
		"SLOGGER_LOCAL_PROXY_RETENTION_HOURS=36\n"
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
