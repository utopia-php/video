FROM composer:2 AS step0

ARG TESTING=false
ENV TESTING=$TESTING

WORKDIR /usr/local/src/

COPY composer.lock /usr/local/src/
COPY composer.json /usr/local/src/

RUN composer install --ignore-platform-reqs --optimize-autoloader \
    --no-plugins --no-scripts --prefer-dist --no-dev

FROM php:8.1-cli-alpine AS final

LABEL maintainer="team@appwrite.io"

ENV TZ=UTC

RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

RUN apk add --no-cache ffmpeg \
    && rm -rf /var/cache/apk/*

WORKDIR /usr/src/code

COPY ./ /usr/src/code
COPY --from=step0 /usr/local/src/vendor /usr/src/code/vendor

CMD ["tail", "-f", "/dev/null"]
