##############################################
# Stage 1: Install Maho on SQLite (fast, no network)
# Ship the SQLite DB + pgloader in the image
##############################################
FROM mahocommerce/maho:26.3.0-php8.4 AS installer

# Replace stock Maho with API Platform branch
WORKDIR /tmp
ENV COMPOSER_ALLOW_SUPERUSER=1
RUN rm -rf /app && \
    git clone --branch feature/api-platform --single-branch --depth 1 \
        https://github.com/mageaustralia/maho.git /app && \
    cd /app && \
    composer install --no-dev --optimize-autoloader --no-interaction && \
    rm -rf /app/.git
WORKDIR /app

# API Platform expects this directory
RUN mkdir -p /app/app/code/core/Maho/ApiPlatform/symfony/src/ApiResource

# Install Maho to SQLite (fast — no DB server needed)
RUN echo "=== Starting Maho install to SQLite ===" && \
    SECONDS=0 && \
    php maho install \
        --license_agreement_accepted=yes \
        --locale=en_AU \
        --timezone=Australia/Sydney \
        --default_currency=AUD \
        --db_host=localhost \
        --db_name=/tmp/maho-seed.db \
        --db_engine=sqlite \
        --session_save=files \
        --url=https://2630.mageaustralia.com.au/ \
        --use_secure=yes \
        --secure_base_url=https://2630.mageaustralia.com.au/ \
        --use_secure_admin=yes \
        --admin_frontname=admin \
        --admin_lastname=Admin \
        --admin_firstname=Maho \
        --admin_email=admin@mageaustralia.com.au \
        --admin_username=admin \
        --admin_password=MahoAdmin2026! \
        --sample_data=yes && \
    echo "=== Maho install completed in ${SECONDS}s ===" && \
    php -r "preg_match('/<key[^>]*>(?:<!\[CDATA\[)?([a-f0-9]+)/', file_get_contents('/app/app/etc/local.xml'), \$m); file_put_contents('/tmp/maho-crypt-key', \$m[1]);" && \
    ls -lh /tmp/maho-seed.db && \
    rm -f /app/app/etc/local.xml && \
    echo "Install + key extraction complete."

# Apply Neon compatibility patches for runtime
COPY neon-patch.php /tmp/neon-patch.php
RUN php /tmp/neon-patch.php

##############################################
# Stage 2: Runtime image
# Contains: patched Maho + SQLite seed DB
# On first boot: PHP migrates SQLite→Neon (Sydney→Sydney)
##############################################
FROM mahocommerce/maho:26.3.0-php8.4

# Install pdo_pgsql + pg client (pgloader removed — can't handle Neon SNI)
RUN apt-get update && \
    apt-get install -y --no-install-recommends libpq-dev postgresql-client && \
    docker-php-ext-install pdo_pgsql && \
    rm -rf /var/lib/apt/lists/*

# Copy Maho app from installer stage (with Neon patches applied)
COPY --from=installer /app /app
WORKDIR /app

# Copy SQLite seed database and crypt key
COPY --from=installer /tmp/maho-seed.db /maho-seed.db
COPY --from=installer /tmp/maho-crypt-key /maho-crypt-key

# PHP-based SQLite → Postgres migration script
COPY seed-neon.php /seed-neon.php

# OPcache config for FrankenPHP (CLI SAPI)
RUN echo "zend_extension=opcache\n\
opcache.enable_cli=1\n\
opcache.memory_consumption=256\n\
opcache.max_accelerated_files=20000\n\
opcache.validate_timestamps=0" > /usr/local/etc/php/conf.d/docker-php-ext-opcache.ini && \
    echo "log_errors=On\n\
error_log=/dev/stderr\n\
display_errors=Off\n\
error_reporting=E_ALL & ~E_DEPRECATED & ~E_STRICT" > /usr/local/etc/php/conf.d/docker-php-logging.ini

# Symlink Maho log files to stderr so Fly captures them
RUN mkdir -p /app/var/log && \
    ln -sf /dev/stderr /app/var/log/exception.log && \
    ln -sf /dev/stderr /app/var/log/system.log

# FPC module
COPY fpc-module/app/ /app/app/
RUN mkdir -p /app/public/js/mageaustralia/fpc
COPY fpc-module/js/mageaustralia/fpc/ /app/public/js/mageaustralia/fpc/
# Create symlink for Caddy to serve FPC files
RUN mkdir -p /app/public/var && ln -sfn /app/var/fpc /app/public/var/fpc
# No composer dump-autoload needed — maho-composer-plugin discovers app/code/local at runtime

# Caddyfile
COPY Caddyfile /etc/caddy/Caddyfile
COPY Caddyfile /etc/frankenphp/Caddyfile

# Startup script
COPY start-pgloader.sh /start.sh
RUN chmod +x /start.sh

ENTRYPOINT []
CMD ["/start.sh"]
