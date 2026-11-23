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
! git grep -n '//nolint' -- .
go test -race ./...

mkdir -p ../build
go build -o ../build/slogger-local-proxy-check ./cmd/slogger-local-proxy
