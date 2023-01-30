FROM composer:2.0 as step0

ARG TESTING=false
ENV TESTING=$TESTING

WORKDIR /usr/local/src/

COPY composer.lock /usr/local/src/
COPY composer.json /usr/local/src/

#docker run -v "$(pwd)":/usr/src/code -it --net=host --rm google/shaka-packager

RUN composer update --ignore-platform-reqs --optimize-autoloader \
    --no-plugins --no-scripts --prefer-dist

FROM php:8.1-cli-alpine as final

LABEL maintainer="team@appwrite.io"

RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

RUN \
  apk update \
  && apk add --no-cache make automake autoconf gcc g++ git zlib-dev libmemcached-dev \
   ffmpeg \
    && rm -rf /var/cache/apk/*

WORKDIR /usr/src/code

# Add Source Code
COPY ./ /usr/src/code

COPY --from=step0 /usr/local/src/vendor /usr/src/code/vendor

CMD [ "tail", "-f", "/dev/null" ]