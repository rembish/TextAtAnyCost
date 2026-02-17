FROM php:8.3-cli

# ext-zip (required by ZippedXmlParser / DOCX + ODT tests)
# Xdebug (required by make test-coverage; disabled by default, no runtime cost)
RUN apt-get update -qq \
    && apt-get install -y --no-install-recommends libzip-dev \
    && docker-php-ext-install zip \
    && pecl install xdebug \
    && docker-php-ext-enable xdebug \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

WORKDIR /app
