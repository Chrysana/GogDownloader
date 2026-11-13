FROM php:8.5-alpine

ENV CONFIG_DIRECTORY=/Configs
ENV DOWNLOAD_DIRECTORY=/Downloads
# keep the key used to encrypt the stored GOG login in the persistent config volume
ENV GOG_DOWNLOADER_SECRET_KEY_FILE=/Configs/secret.key

RUN mkdir -p $CONFIG_DIRECTORY $DOWNLOAD_DIRECTORY \
    && chmod 0777 $CONFIG_DIRECTORY \
    && apk add --no-cache libxml2-dev sqlite-dev \
    && docker-php-ext-install simplexml pcntl pdo_sqlite \
    && echo 'memory_limit = -1' >> /usr/local/etc/php/conf.d/docker-php-memory-limit.ini

COPY gog-downloader.phar /app/gog-downloader
RUN chmod +x /app/gog-downloader
WORKDIR /app

ENTRYPOINT ["/app/gog-downloader"]
