# Utopia Streaming

FFmpeg-based ABR packaging library for HLS and DASH under the `Utopia\Streaming` namespace.

## Requirements

- PHP 8.1+
- FFmpeg and FFProbe on `PATH`
- Composer

## Install

```bash
composer require shimonewman/streaming
```

## Usage

```php
use Utopia\Streaming\Adapter\FFmpeg;
use Utopia\Streaming\Format\X264;
use Utopia\Streaming\Output\Hls;
use Utopia\Streaming\Representation;
use Utopia\Streaming\Stream;

$stream = new Stream(new FFmpeg([
    'timeout' => 0,
    'ffmpeg.threads' => 4,
]));

$stream
    ->open('/path/to/input.mp4')
    ->setFormat(new X264())
    ->addRepresentations([
        (new Representation())->setResize(640, 360)->setKiloBitrate(276)->setAudioKiloBitrate(128),
        (new Representation())->setResize(1280, 720)->setKiloBitrate(2048)->setAudioKiloBitrate(128),
    ])
    ->setOutput(new Hls())
    ->save('/path/to/output/stream.m3u8');
```

DASH:

```php
use Utopia\Streaming\Output\Dash;

$stream
    ->open('/path/to/input.mp4')
    ->setFormat(new X264())
    ->addRepresentations([/* ... */])
    ->setOutput(
        (new Dash())
            ->setUseTimeline(1)
            ->setUseTemplate(1)
            ->setInitSegmentName(true)
            ->setMediaSegmentName(true)
    )
    ->save('/path/to/output/stream.mpd');
```

## Development

```bash
composer install
composer test
composer analyse
composer lint
```

Docker:

```bash
docker compose up --build -d
docker compose exec php8 vendor/bin/phpunit
```

## License

MIT
