# Two images from one file, sharing everything expensive.
#
# ```text
# base          PHP extensions, Composer, the runtime dependency set
#   development dev dependencies, migrations on boot, timestamps revalidated
#   production  cached config and routes, no Composer, opcache locked down
# ```
#
# Compose names `target: development`; docker-compose.prod.yml names
# `target: production`.
#
# FrankenPHP rather than php-fpm behind nginx. It is one process serving HTTP
# directly, which is one container, one log stream and no second config file
# describing how to talk to the first. Classic mode, not Octane worker mode:
# a worker keeps the application in memory between requests, and state leaking
# from one person's request into another's is not a class of bug worth
# accepting for throughput nobody has measured a need for yet.
FROM dunglas/frankenphp:1-php8.5-alpine AS base

# pdo_pgsql  PostgreSQL, the only database this application talks to
# intl       locale-aware money and date formatting
# bcmath     exact integer arithmetic over values larger than a PHP int
# zip        Composer uses it to unpack dist packages
# opcache    compiled opcodes; configured per target below
RUN install-php-extensions \
    pdo_pgsql \
    intl \
    bcmath \
    zip \
    opcache

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# `:8000` rather than a hostname. A bare port tells Caddy to serve plain HTTP
# and skip certificate provisioning entirely, which is what we want: this
# service is reachable only on the internal Docker network, and TLS terminates
# in front of the Next.js server. Give it a real hostname and it will try to
# obtain a certificate for a host the internet cannot resolve.
ENV SERVER_NAME=:8000 \
    SERVER_ROOT=/app/public \
    COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /app

# Manifests first, so the dependency layer is cached until they actually
# change. --no-scripts because the application source is not here yet and
# Laravel's post-autoload hook boots the framework to discover packages.
COPY apps/api/composer.json apps/api/composer.lock ./
RUN composer install \
    --no-dev \
    --no-scripts \
    --no-autoloader \
    --prefer-dist \
    --no-interaction

COPY apps/api .

COPY docker/api/entrypoint.dev.sh docker/api/entrypoint.prod.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/entrypoint.dev.sh /usr/local/bin/entrypoint.prod.sh

# The two directories Laravel writes to at runtime. Created and owned here,
# before any volume is mounted over them, so Docker copies this ownership onto
# a fresh named volume - otherwise the volume arrives owned by root and the
# unprivileged process below cannot write a log line.
RUN adduser -D -u 1000 app \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views \
    storage/logs bootstrap/cache \
    && chown -R app:app /app

# Caddy's own state and configuration directories, owned by the user that will
# run it. Caddy provisions its local certificate authority during startup even
# when no site is served over TLS, and writing the root certificate is the
# first thing it does - as root-owned directories that is a permission error
# before the server ever listens.
RUN mkdir -p /data/caddy /config/caddy \
    && chown -R app:app /data /config

EXPOSE 8000


# --- development ------------------------------------------------------------
#
# What Compose builds. Source and vendor/ arrive as mounts, so almost nothing
# baked in here is the code that actually runs - the entrypoint reinstalls
# dependencies and migrates on boot, which is a convenience worth having on a
# laptop and nowhere else.
FROM base AS development

COPY docker/api/conf.d/development.ini /usr/local/etc/php/conf.d/zz-app.ini

USER app

ENTRYPOINT ["entrypoint.dev.sh"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]


# --- production -------------------------------------------------------------
#
# What a deployment runs. No dev dependencies, no Composer left on the image,
# and no migrations on boot: under more than one replica a migrate per
# container is a race, so a deployment runs them as an explicit step.
FROM base AS production

COPY docker/api/conf.d/production.ini /usr/local/etc/php/conf.d/zz-app.ini

# Classmap autoloader over the whole application. Also the point where
# Laravel's package discovery runs, which is why it comes after the source.
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev \
    && rm -f /usr/local/bin/composer

USER app

ENTRYPOINT ["entrypoint.prod.sh"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
