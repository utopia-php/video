ARG FFMPEG_VERSION=7.1.1
ARG PHP_VERSION=8.1

FROM php:${PHP_VERSION}-cli-alpine AS ffmpeg

ARG FFMPEG_VERSION

RUN apk add --no-cache \
    build-base nasm pkgconf curl xz \
    x264-dev x265-dev libvpx-dev opus-dev lame-dev zlib-dev \
    libtheora-dev libvorbis-dev

RUN curl -fsSL "https://ffmpeg.org/releases/ffmpeg-${FFMPEG_VERSION}.tar.xz" | tar -xJ -C /tmp \
    && cd "/tmp/ffmpeg-${FFMPEG_VERSION}" \
    && ./configure \
        --prefix=/opt/ffmpeg \
        --enable-gpl \
        --enable-libx264 \
        --enable-libx265 \
        --enable-libvpx \
        --enable-libopus \
        --enable-libmp3lame \
        --enable-libtheora \
        --enable-libvorbis \
        --disable-debug \
        --disable-doc \
        --disable-ffplay \
    && make -j"$(nproc)" \
    && make install \
    && /opt/ffmpeg/bin/ffmpeg -version \
    && rm -rf "/tmp/ffmpeg-${FFMPEG_VERSION}"

FROM composer:2 AS vendor

ARG TESTING=false

WORKDIR /usr/local/src/

COPY composer.json composer.lock /usr/local/src/

# No --ignore-platform-reqs: the lock is resolved against the oldest supported
# PHP, so anything that would not run in this image should fail the build rather
# than install and break at runtime.
RUN composer install --optimize-autoloader \
    --no-plugins --no-scripts --prefer-dist \
    $(test "$TESTING" = "true" || echo "--no-dev")

FROM php:${PHP_VERSION}-cli-alpine AS final

LABEL maintainer="team@appwrite.io"

ENV TZ=UTC

RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

RUN apk add --no-cache \
    x264-libs x265-libs libvpx opus lame-libs libtheora libvorbis libstdc++

COPY --from=ffmpeg /opt/ffmpeg/bin/ffmpeg /opt/ffmpeg/bin/ffprobe /usr/local/bin/

WORKDIR /usr/src/code

COPY --from=vendor /usr/local/src/vendor /usr/src/code/vendor

# Add Source Code
COPY ./src /usr/src/code/src
COPY ./tests /usr/src/code/tests
COPY ./composer.json /usr/src/code/composer.json
COPY ./phpunit.xml /usr/src/code/phpunit.xml
COPY ./phpstan.neon /usr/src/code/phpstan.neon
COPY ./pint.json /usr/src/code/pint.json

RUN ffmpeg -version && ffprobe -version

CMD [ "tail", "-f", "/dev/null" ]
