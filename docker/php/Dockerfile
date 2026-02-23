FROM php:8.2-cli

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
