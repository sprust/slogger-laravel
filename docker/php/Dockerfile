FROM golang:1.26.11-bookworm AS golang

FROM php:8.2-cli

COPY --from=golang /usr/local/go /usr/local/go
ENV PATH="/usr/local/go/bin:${PATH}"

ARG GOLANGCI_LINT_VERSION=v2.15.0
ARG GOVULNCHECK_VERSION=v1.10.0
RUN GOBIN=/usr/local/bin go install github.com/golangci/golangci-lint/v2/cmd/golangci-lint@${GOLANGCI_LINT_VERSION} \
    && GOBIN=/usr/local/bin go install golang.org/x/vuln/cmd/govulncheck@${GOVULNCHECK_VERSION}

RUN apt update && apt install -y \
    git \
    libzip-dev \
    zip \
    unzip \
    wget \
    make

# PHP
RUN pecl install zip pcntl && docker-php-ext-enable zip

RUN docker-php-ext-install sockets pcntl && docker-php-ext-enable sockets

RUN git config --global --add safe.directory /app

RUN curl -sS https://getcomposer.org/installer  \
    | php -- --install-dir=/usr/local/bin --filename=composer

ARG USER_ID
ARG GROUP_ID

# Check if user and group doesn't exist before creating
RUN getent group "$GROUP_ID" || addgroup --gid "$GROUP_ID" user
RUN getent passwd "$USER_ID" || adduser --disabled-password --gecos '' --uid "$USER_ID" --gid "$GROUP_ID" user

USER "$USER_ID"
