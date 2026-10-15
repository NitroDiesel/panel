# Stage 0:
# Build the assets that are needed for the frontend. This build stage is then discarded
# since we won't need NodeJS anymore in the future. This Docker image ships a final production
# level distribution of Pterodactyl.
FROM --platform=$TARGETOS/$TARGETARCH node:22-alpine
WORKDIR /app
COPY . ./
RUN yarn install --frozen-lockfile \
    && yarn run build:production

# Stage 1:
# Build the actual container with all of the needed PHP dependencies that will run the application.
FROM --platform=$TARGETOS/$TARGETARCH php:8.3-fpm-alpine
WORKDIR /app
COPY . ./
COPY --from=0 /app/public/assets ./public/assets
RUN apk add --no-cache --update ca-certificates dcron curl git supervisor tar unzip nginx libpng-dev libxml2-dev libzip-dev certbot certbot-nginx mysql-client \
    && docker-php-ext-configure zip \
    && docker-php-ext-install bcmath gd pdo_mysql zip \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && cp .env.example .env \
    && mkdir -p bootstrap/cache/ storage/logs storage/framework/sessions storage/framework/views storage/framework/cache var \
    && composer install --no-dev --optimize-autoloader \
    && rm -rf .env bootstrap/cache/*.php \
    && ln -s /app/var/.env /app/.env \
    && chown -R nginx:nginx storage bootstrap/cache var

RUN rm /usr/local/etc/php-fpm.conf \
    && echo "0 23 * * * certbot renew --nginx --quiet" >> /var/spool/cron/crontabs/root \
    && sed -i s/ssl_session_cache/#ssl_session_cache/g /etc/nginx/nginx.conf \
    && mkdir -p /var/run/php /var/run/nginx

COPY .github/docker/default.conf /etc/nginx/http.d/default.conf
COPY .github/docker/nginx-unprivileged.conf /etc/nginx/nginx-unprivileged.conf
COPY .github/docker/www.conf /usr/local/etc/php-fpm.conf
COPY .github/docker/supervisord.conf /etc/supervisord.conf

# When the container runs as a non-root user (e.g. "--user nginx") nginx can't bind to
# port 80 or write to its default paths, so it uses a copy of the same server block that
# listens on 8080 and logs to stderr.
RUN sed -e 's/listen 80;/listen 8080;/' -e 's|error_log .*;|error_log /dev/stderr error;|' \
        /etc/nginx/http.d/default.conf > /etc/nginx/panel-unprivileged.conf

# Laravel writes its package and service manifests on first boot. Keep them out of the
# application directory so the image can run with a read-only root filesystem.
ENV APP_PACKAGES_CACHE=/tmp/packages.php \
    APP_SERVICES_CACHE=/tmp/services.php \
    NGINX_CONF=/etc/nginx/nginx.conf

HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 \
    CMD [ "/bin/ash", "/app/.github/docker/healthcheck.sh" ]

EXPOSE 80 443 8080
ENTRYPOINT [ "/bin/ash", ".github/docker/entrypoint.sh" ]
CMD [ "supervisord", "-n", "-c", "/etc/supervisord.conf" ]
