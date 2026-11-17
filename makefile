PHP_CLI="docker-compose exec php"

env-copy:
	cp -i .env.example .env

setup:
	make down
	make build
	make up
	make composer c=i

build:
	docker-compose build

down:
	docker-compose down

up:
	docker-compose up -d

stop:
	docker-compose stop

restart:
	make stop
	make up

bash-php:
	"$(PHP_CLI)" bash

composer:
	"$(PHP_CLI)" composer ${c}

artisan:
	"$(PHP_CLI)" ./vendor/bin/testbench ${c}

test:
	"$(PHP_CLI)" ./vendor/bin/phpunit \
		-d memory_limit=512M \
		--colors=auto \
		--testdox \
		--display-incomplete \
		--display-skipped \
		--display-deprecations \
		--display-phpunit-deprecations \
		--display-errors \
		--display-notices \
		--display-warnings \
		tests ${c}

proxy-check:
	mkdir -p build
	"$(PHP_CLI)" sh -c 'cd /app/local-proxy && test -z "$$(gofmt -l .)" && go vet ./... && go test -race ./... && go build -o /app/build/slogger-local-proxy-check ./cmd/slogger-local-proxy'

proxy-build:
	mkdir -p build
	"$(PHP_CLI)" sh -c 'cd /app/local-proxy && CGO_ENABLED=0 GOOS=linux GOARCH=amd64 go build -trimpath -ldflags="-s -w -X main.version=dev" -o /app/build/slogger-local-proxy ./cmd/slogger-local-proxy'

proxy-integration: proxy-build
	"$(PHP_CLI)" sh -c 'cd /app && SLOGGER_LOCAL_PROXY_TEST_BINARY=/app/build/slogger-local-proxy vendor/bin/phpunit tests/Feature/LocalProxy/LocalProxyIntegrationTest.php'

stan:
	"$(PHP_CLI)" ./vendor/bin/phpstan analyse \
		--memory-limit=1G

check:
	make cs-fixer-check
	make stan
	make test

declare-strict:
	grep -Lr "declare(strict_types=1);" ./src | grep .php

cs-fixer-check:
	"$(PHP_CLI)" ./vendor/bin/php-cs-fixer fix --config php-cs-fixer.dist.php --dry-run --diff --verbose

cs-fixer-fix:
	"$(PHP_CLI)" ./vendor/bin/php-cs-fixer fix --config php-cs-fixer.dist.php --verbose

# set-laravel-* rewrite the constraint in composer.json, so the run restores it even
# when a leg fails - otherwise the matrix leaves the package pinned to one version
# the same legs as the CI matrix: a caret resolves to the newest release, so 10.26 pins
# the floor composer.json claims
check-laravel-all:
	@trap 'make restore-composer' EXIT; \
	for v in 10.26 10 11 12; do \
		make set-laravel-$$v || exit 1; \
		make check || exit 1; \
	done

restore-composer:
	git checkout -- composer.json composer.lock
	make composer c="install --no-interaction"

set-laravel-10.26:
	make composer c="require "laravel/framework:10.26.*" --no-update"
	make composer c="update --with-all-dependencies --prefer-dist --no-interaction"

set-laravel-10:
	make composer c="require "laravel/framework:^10.26" --no-update"
	make composer c="update --with-all-dependencies --prefer-dist --no-interaction"

set-laravel-11:
	make composer c="require "laravel/framework:^11" --no-update"
	make composer c="update --with-all-dependencies --prefer-dist --no-interaction"

set-laravel-12:
	make composer c="require "laravel/framework:^12" --no-update"
	make composer c="update --with-all-dependencies --prefer-dist --no-interaction"
