#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

unformatted="$(gofmt -l .)"
if [[ -n "$unformatted" ]]; then
    printf 'Unformatted Go files:\n%s\n' "$unformatted"
    exit 1
fi

golangci-lint fmt --diff ./...
golangci-lint run --tests=true ./...
go mod tidy -diff
go mod verify
go vet ./...
govulncheck ./...
if git grep -n '//nolint' -- '*.go'; then
    printf 'nolint directives are not allowed in Go files\n' >&2
    exit 1
else
    grep_status=$?
    if [[ "$grep_status" -ne 1 ]]; then
        exit "$grep_status"
    fi
fi
go test -race ./...

mkdir -p ../build
go build -o ../build/slogger-local-proxy-check ./cmd/slogger-local-proxy
