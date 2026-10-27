# Application image for local development and CI-style runs.
#
# It runs `php artisan serve`, which is right for development and wrong for
# production: use docker/Dockerfile.prod (PHP-FPM behind Caddy) for that.
#
# Two usable final targets:
#   runtime  what docker-compose.yml builds by default. No Node.js, npm or
#            Chromium: application code never needs them at runtime.
#   test     adds Node.js and Playwright's Chromium, only needed by the
#            Pest browser suite (tests/Browser). Build it explicitly:
#              docker build --target test -f docker/Dockerfile -t starter-app-test .
#
# `sockets` and `pcntl` stay in the shared stage on purpose: pest-plugin-browser
# hooks into Pest's lifecycle at boot, so every `pest` run (not only the
# browser suite) needs them once the package is installed.

FROM php:8.5-cli AS deps

RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libpq-dev \
        libzip-dev \
        curl \
        ca-certificates \
    && docker-php-ext-install pdo pdo_pgsql zip sockets pcntl \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock* ./

# Fails the build on any install error. docker-compose.yml bind-mounts the repo
# over /var/www/html for live reload, which would hide this layer's vendor/
# directory; the anonymous /var/www/html/vendor volume on each service keeps it.
RUN composer install --no-interaction --no-scripts --no-progress

# ---------------------------------------------------------------------------
FROM deps AS runtime

COPY . .

RUN composer install --no-interaction --no-progress

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]

# ---------------------------------------------------------------------------
FROM deps AS test-deps

RUN apt-get update && apt-get install -y --no-install-recommends gnupg \
    && curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && rm -rf /var/lib/apt/lists/*

# Playwright's Chromium is the slowest layer here and depends only on the
# package manifests, so it is installed before the application code is copied:
# an ordinary code change does not invalidate it.
COPY package.json package-lock.json* ./
RUN npm ci --no-audit --no-fund \
    && npx playwright install --with-deps chromium

# ---------------------------------------------------------------------------
FROM test-deps AS test

COPY . .

RUN composer install --no-interaction --no-progress

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
