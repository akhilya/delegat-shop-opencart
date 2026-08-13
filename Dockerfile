FROM php:8.2-apache

ARG OC_VERSION=4.0.2.3

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        curl \
        unzip \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libzip-dev \
        libcurl4-openssl-dev \
        libonig-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" gd mysqli zip curl mbstring \
    && a2enmod rewrite \
    && sed -ri 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf \
    && printf '%s\n' \
         '<Directory /var/www/html>' \
         '    AllowOverride All' \
         '    Require all granted' \
         '    CGIPassAuth On' \
         '    SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1' \
         '</Directory>' \
         > /etc/apache2/conf-available/opencart-html.conf \
    && a2enconf opencart-html \
    && printf 'memory_limit=256M\nupload_max_filesize=64M\npost_max_size=64M\n' > /usr/local/etc/php/conf.d/opencart.ini \
    && rm -rf /var/lib/apt/lists/*

RUN curl -fsSL -o /tmp/opencart.zip \
      "https://github.com/opencart/opencart/releases/download/${OC_VERSION}/opencart-${OC_VERSION}.zip" \
    && mkdir -p /tmp/oc /usr/src/opencart \
    && unzip -q /tmp/opencart.zip -d /tmp/oc \
    && UPLOAD="$(find /tmp/oc -type d -name upload | head -n 1)" \
    && test -n "$UPLOAD" \
    && cp -a "$UPLOAD"/. /usr/src/opencart/ \
    && if [ -f /usr/src/opencart/.htaccess.txt ] && [ ! -f /usr/src/opencart/.htaccess ]; then \
         cp /usr/src/opencart/.htaccess.txt /usr/src/opencart/.htaccess; \
       fi \
    && rm -rf /tmp/oc /tmp/opencart.zip \
    && chown -R www-data:www-data /usr/src/opencart

COPY extension/delegat_bridge /usr/src/delegat_bridge
COPY docker-entrypoint.sh /usr/local/bin/opencart-entrypoint.sh
COPY enable-bridge.php /usr/local/bin/enable-bridge.php
COPY rewrite-site-url.php /usr/local/bin/rewrite-site-url.php

RUN sed -i 's/\r$//' /usr/local/bin/opencart-entrypoint.sh \
    && chmod +x /usr/local/bin/opencart-entrypoint.sh

ENTRYPOINT ["opencart-entrypoint.sh"]
CMD ["apache2-foreground"]
