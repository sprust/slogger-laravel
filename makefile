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

stan:
	"$(PHP_CLI)" ./vendor/bin/phpstan analyse \
		--memory-limit=1G

check:
	make stan
	make test

declare-strict:
	grep -Lr "declare(strict_types=1);" ./src | grep .php
