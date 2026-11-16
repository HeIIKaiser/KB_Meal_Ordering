FROM php:8.3-cli-alpine
RUN apk add --no-cache tzdata && docker-php-ext-install pdo_mysql
ENV TZ=Europe/Bratislava
WORKDIR /bridge
COPY bin/ bin/
COPY src/ src/
USER nobody
CMD ["php", "bin/bridge", "run"]
