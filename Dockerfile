FROM node:24-alpine AS frontend
WORKDIR /build
COPY package.json package-lock.json ./
RUN npm ci --ignore-scripts --no-audit --no-fund
COPY frontend/ ./frontend/
RUN node frontend/build.cjs

FROM php:8.5.12-apache-trixie
RUN pecl install redis-6.3.0 </dev/null \
    && docker-php-ext-enable redis \
    && rm -rf /tmp/pear
COPY docker/chat.ini /usr/local/etc/php/conf.d/chat.ini
COPY app/ /var/www/app/
COPY public/ /var/www/html/
COPY --from=frontend /build/assets/ /var/www/html/assets/
