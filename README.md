# Utopia Video

[![Tests](https://github.com/utopia-php/video/actions/workflows/tests.yml/badge.svg)](https://github.com/utopia-php/video/actions/workflows/tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/utopia-php/video.svg)](https://packagist.org/packages/utopia-php/video)
[![Discord](https://img.shields.io/badge/discord-join-5865F2)](https://appwrite.io/discord)

Utopia framework video library is simple and lite library for probing, encoding and packaging
video for adaptive streaming. This library is aiming to be as simple and easy to learn and use. This
library is maintained by the [Appwrite team](https://appwrite.io).

Although this library is part of the [Utopia Framework](https://github.com/utopia-php/framework)
project, it can be used as standalone with any other PHP project or framework. Its only runtime
dependency is [`utopia-php/console`](https://github.com/utopia-php/console), which prints the status
line at the end of a job — and even that can be replaced with a `Reporter` of your own.

It takes local files in, writes local files out, and describes what it produced. It does not talk to
cloud storage, a database, or an HTTP layer — that is the calling application's job.

See [docs/design.md](docs/design.md) for the full design.

## Getting Started

Install using composer:

```bash
composer require utopia-php/video
```

Two classes do the work. `Encoder` writes one file — an encode, a thumbnail, a sprite sheet.
`Packager` writes an adaptive ladder — segments plus the manifests that describe them.

Read what a file is:

```php
require_once __DIR__ . '/../../vendor/autoload.php';

use Utopia\Video\Encoder;

$encoder = new Encoder();

$info = $encoder->probe('/path/to/video.mp4');

$info->duration;      // 64.5 seconds
$info->milliseconds(); // 64500
$info->width;         // 1920
$info->videoCodec;    // 'h264'
$info->tags['title']; // container metadata
$info->chapters;      // list<Chapter>
$info->tracks;        // every stream, embedded subtitles included
```

Encode a single file:

```php
use Utopia\Video\Encoder;
use Utopia\Video\Format\X264;
use Utopia\Video\Representation;

$path = (new Encoder())
    ->open('/path/to/video.mp4')
    ->format((new X264())->crf(22)->bframes(3))
    ->add(new Representation(width: 1280, height: 720, video: 2538, audio: 128))
    ->encode('/path/to/720p.mp4');
```

One rendition of the picture comes out, at exactly the size asked for — a source of a different
shape is fitted inside the box rather than stretched. Every audio track comes through, so a release
with four dubs still has four afterwards.

Package an adaptive ladder:

```php
use Utopia\Video\Format\X264;
use Utopia\Video\Output\Cmaf;
use Utopia\Video\Packager;
use Utopia\Video\Progress;
use Utopia\Video\Representation;

$package = (new Packager())
    ->open('/path/to/video.mp4')
    ->format((new X264())->crf(22)->bframes(3))
    ->add(
        new Representation(width: 640, height: 360, video: 800, audio: 96),
        new Representation(width: 1280, height: 720, video: 2538, audio: 128),
    )
    ->output((new Cmaf())->segment(6))
    ->on(Packager::PROGRESS, fn (Progress $p) => print("{$p->percent}%\n"))
    ->pack('/path/to/output');

$package->segments();  // every segment, with duration, size and init flag
$package->variants();  // per rendition metadata
$package->manifests(); // the playlists written, if any were kept
$package->files();     // everything produced, ready to upload
```

`Cmaf` writes one set of fragmented MP4 segments described by both a DASH manifest and an HLS
playlist tree, so either kind of player downloads the same bytes. Use `Hls` or `Dash` to write just
one of them.

A segment can only start where a keyframe already is, so packaging forces a keyframe every segment
unless you ask for something else with `keyframe()`. Nothing has to be kept in step by hand, and a
keyframe interval longer than a segment — which cannot produce segments of the length asked for — is
rejected before ffmpeg is called.

Names are checked too. A representation name, an output base name and a sprite sheet name become
filenames in the directory you named, and a rendition name is also how the muxer is told which
variant is which, so anything carrying a path separator, a comma or a space is refused with
`Exception\Input` rather than written somewhere unexpected.

### Several audio languages

Every audio track the source carries comes through as its own selectable rendition — `EXT-X-MEDIA:TYPE=AUDIO`
rows for HLS, one `<AdaptationSet>` each for DASH. Tagged tracks are labelled with their language;
untagged ones are still carried, just unlabelled, because a dub without a tag is still a dub. Video
rungs stay switchable alternatives of one another; languages are separate choices.

```php
$package = (new Packager())->open('/path/to/movie.mkv')   // 4 dubs
    ->format(new X264())
    ->add($rep)
    ->output(new Cmaf())
    ->pack('/out');

foreach ($package->variants() as $variant) {
    $variant->type;      // 'video' | 'audio'
    $variant->language;  // 'eng', 'spa', 'fra', 'jpn', …
}
```

Subtitles are read but not packaged. `probe()` reports every embedded subtitle track in
`$info->tracks`, and what to do with them is the calling application's decision — extracting them,
converting them and referencing them from a manifest all belong outside this library.

Applications that serve their own manifests can keep the media and drop the playlists with
`manifests(false)` — `segments()` and `variants()` are populated either way:

```php
->output((new Dash())->template(false)->timeline(false)->manifests(false))
```

Thumbnails and sprite timelines:

```php
use Utopia\Video\Thumb;
use Utopia\Video\Tile;

$encoder = new Encoder();

$encoder->grab('/path/to/video.mp4', '/out/poster.jpg', (new Thumb())->width(640));
$sheet = $encoder->tile('/path/to/video.mp4', '/out/timeline');

$sheet->cues();   // list<Cue> with start, end, x, y, width, height
$sheet->render(fn (string $file) => "/preview/{$file}"); // WebVTT with your own URLs
```

Given a file with no video but embedded artwork, `grab()` finds the picture rather than failing.

## Adapters

One class per backend, each declaring what it can do by implementing capability interfaces from
`Utopia\Video\Adapter`:

| Backend            | Binary    | Implements            |
| ------------------ | --------- | --------------------- |
| `Adapter\FFmpeg`   | `ffmpeg`  | `Encoder`, `Packager` |
| `Adapter\FFprobe`  | `ffprobe` | `Probe`               |
| `Adapter\Mock`     | none      | all three             |

`new Encoder()` and `new Packager()` need nothing: each wires an `FFmpeg` to work with and an
`FFprobe` to read with. Pass your own to replace either:

```php
use Utopia\Video\Adapter\FFmpeg;

// One backend, shared by both facades.
$ffmpeg = new FFmpeg(threads: 4);

$encoder = new Encoder($ffmpeg);
$packager = new Packager($ffmpeg);
```

Whichever probe you pass is the one the adapter reads through — the facade hands it over, so an
adapter you built yourself cannot quietly keep one of its own:

```php
$encoder = new Encoder(probe: new MyProbe());
```

### How much a backend says

Every adapter has a display level, defaulting to `Adapter::ERROR` — only what went wrong, because
the interesting output of an encode is the file it wrote, not its commentary. Raise it when
something needs explaining; whatever the backend then prints arrives as `LOG` events.

Finished **encode / pack / grab / tile** jobs also report a status line — green via
`Utopia\Console::success`, and red via `Console::error` for a failed backend command before the
exception is thrown. Probe stays quiet on success. `Adapter::QUIET` suppresses both status lines as
well as `LOG` events; progress events keep firing at every level, because progress is structured
data rather than commentary.

```php
use Utopia\Video\Adapter;
use Utopia\Video\Adapter\FFmpeg;

$encoder = new Encoder(new FFmpeg(level: Adapter::VERBOSE));

$encoder->on(Encoder::LOG, fn (string $line) => error_log($line));
```

Listeners belong to the facade, not to one job: `on()` reads the same before or after `open()`, and
a listener registered once is heard by every job that facade runs. `off()` drops them again —
`off(Encoder::LOG)` for one event, `off()` for all of them — which is what a reused facade wants
before it registers the next job's listener.

`Adapter::QUIET`, `ERROR` (default), `WARNING`, `INFO`, `VERBOSE`, `DEBUG` — quietest first, listed
in `Adapter::LEVELS`. An unrecognised level is rejected when the adapter is constructed, not halfway
through a job.

### Where status lines go

The terminal, by default. Somewhere that is not a command line — an HTTP worker, a queue consumer, a
test suite — owns its own logging and would rather the library did not write to stdout behind its
back, so the destination is a `Reporter`:

```php
use Utopia\Video\Reporter;

$packager = new Packager(reporter: new Reporter\Silent());       // nothing at all
$encoder = new Encoder(reporter: new MyPsrLoggerReporter($log)); // two methods to implement
```

Whichever one a facade is given is handed to every backend it uses, so half a job cannot end up
printing. `LOG` and `PROGRESS` events are unaffected — those already go wherever your listeners send
them.

A backend defines its own default with a `LEVEL` constant, the same way it declares its `NAME`,
`BINARY` and `TIMEOUT`, and `level()` reports whichever level it ended up with.

Because `Adapter\FFmpeg` implements `Encoder` *and* `Packager`, `pack()` encodes and packages in a
single pass over the source. A packager that cannot encode is handed one finished file per rung
instead, with progress reported as one run either way — that is the route a backend like Shaka will
take when it arrives.

Ask which backend is serving what:

```php
$encoder->getName();   // 'ffmpeg'
$packager->getName();  // 'ffmpeg'
```

### Coroutines

The library is built to run inside Swoole coroutines. Two rules make it safe:

**Config and results are shareable; a facade is not.** `Format`, `Output`, `Thumb` and `Tile` are
immutable — every setter returns a modified copy, so a preset held in a container can be handed to
any number of concurrent jobs and configured per job without the jobs seeing each other:

```php
$preset = (new X264())->crf(22);          // shared, safe
$output = (new Cmaf())->segment(6);       // shared, safe

go(function () use ($preset, $output, $in) {
    // One facade per coroutine. Constructing one is a handful of assignments —
    // nothing runs until open() — so there is nothing to save by sharing it,
    // and a facade holds the job chain, which two coroutines would corrupt.
    (new Packager())
        ->open($in)
        ->format($preset->keyframe(2.0))  // a copy; $preset is untouched
        ->add(new Representation(width: 1280, height: 720, video: 2538, audio: 128))
        ->output($output)
        ->pack('/out/a');
});
```

The same goes for adapters: an `Adapter\FFmpeg` carries its job's state, so give each coroutine its
own rather than passing one instance to several facades running concurrently. Every result object
(`Info`, `Package`, `Spritesheet`, `Progress`) is readonly and safe to share.

**Enable the hooks.** `Process` drives backends through `proc_open`, `stream_select` and `usleep`,
all of which yield instead of blocking once Swoole's one-click hooks are on — including the process
hook (`SWOOLE_HOOK_PROC`, part of `SWOOLE_HOOK_ALL`):

```php
Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
```

Without the hooks everything still works; a running backend just blocks the worker instead of
yielding to other coroutines.

Because setters return copies, a setter called as a standalone statement configures the copy and
throws it away — chain from the constructor, or keep the return value.

### Choosing a backend by name

Handy when the choice comes from configuration. Each factory returns the narrow capability type, so
the result can be passed straight to a facade:

```php
use Utopia\Video\Adapter;

$encoder = new Encoder(
    Adapter::encoder($config['encoder'], level: $config['level']),   // 'ffmpeg' | 'mock'
    Adapter::probe($config['probe']),                                // 'ffprobe' | 'mock'
);
```

The names are exactly what `getName()` reports. An unknown name, or one that names a backend which
cannot do the job, throws `Exception\Unsupported`.

### Writing your own

Extend `Utopia\Video\Adapter` for the shared plumbing — identity, listeners, binary and timeout
config, the probe seam, and guards that put your backend's name in the failure message — then
implement whichever capability interfaces apply:

```php
use Utopia\Video\Adapter;
use Utopia\Video\Adapter\Packager;
use Utopia\Video\Package;

class Shaka extends Adapter implements Packager
{
    protected const NAME = 'shaka';

    protected const BINARY = 'packager';

    // Optional: override the default display level for this backend only.
    protected const LEVEL = self::WARNING;

    public function pack(string $dir): Package
    {
        // ...
    }
}
```

Nothing else changes: `new Packager(new Shaka())` works immediately, because a packager that cannot
encode already has a route through the facade. The same holds for an alternative encoder — implement
`Adapter\Encoder` and hand it to `new Encoder($yours)`.

`Adapter\Mock` implements every capability without touching a binary, which makes it useful for
testing code built on this library.

## Contribute

We use the [Utopia Framework](https://github.com/utopia-php/framework) contribution guide. See
[CONTRIBUTING.md](CONTRIBUTING.md).

## System Requirements

Utopia Video requires PHP 8.2 or later, `ffmpeg` with `ffprobe` on the `PATH`, and
[`utopia-php/console`](https://github.com/utopia-php/console) for status lines. The unit suite runs
without either binary.

## Tests

To run all unit tests, use the following Docker command:

```bash
docker compose up -d
docker compose exec tests vendor/bin/phpunit --testsuite unit
```

Every test prints one line saying what it checks and whether it passed:

```
Adapter (Utopia\Tests\Unit\Adapter)
 ✔ Every backend starts at the default display level
 ✔ The display level can be raised or silenced
 ✔ An unknown display level is rejected
```

That is PHPUnit's testdox output, turned on in `phpunit.xml` so it applies to every run rather than
needing a flag. Test method names are written to read as sentences; where a name cannot, a
`@testdox` annotation gives the wording instead.

The end-to-end suite drives a real encoder, and the image builds FFmpeg from source (pinned with the
`FFMPEG_VERSION` build arg) so it is reproducible. It also builds `ext-swoole` (pinned with
`SWOOLE_VERSION`), which is what lets the coroutine test above run for real rather than skip:

```bash
docker compose exec tests vendor/bin/phpunit --testsuite e2e
```

### Samples

A consumer is handed whatever a user uploaded, so the end-to-end suite runs against a library of
sample files rather than one generated clip:

```
tests/samples/in     the sources
tests/samples/out    whatever the tests produced, left behind for inspection
```

`out/` is cleared at the start of each run, so everything in it belongs to the run that produced it
and a directory written by a test that has since been deleted cannot linger there looking like
output.

Build them without running the tests:

```bash
composer samples            # fill in anything missing
composer samples:rebuild    # start over
```

The media is generated by [tests/Video/Samples.php](tests/Video/Samples.php) rather than
committed — it would add megabytes to every clone for files the build reproduces exactly, and a
generator describes each file's shape in a way a binary cannot. The set covers:

| Group | Files |
| --- | --- |
| Containers | `video.{mp4,mov,mkv,avi,wmv,flv,ts,webm,ogv,3gp}`, each with the codecs that container is normally used with |
| Codecs | h264, HEVC, VP9, MPEG-4, WMV2, FLV1, Theora, plus AAC, MP3, Opus, Vorbis, WMA |
| Many tracks | `multi-audio.mp4` (eng/spa/fra), `multi-audio.mkv` (eng/spa/fra/jpn), `multi-subtitle.mkv` (3 subtitle tracks, so probe can be checked against them), `multi-track.mkv` (4 dubs + 2 subtitle languages) |
| Awkward shapes | `anamorphic.mp4`, `variable-fps.mp4`, `rotated.mp4`, `portrait.mp4`, `tiny.mp4`, `silent.mp4` |
| Metadata | `chapters.mp4`, container tags |
| Sound only | `audio.m4a`, `audio.mp3`, `artwork.m4a` (embedded cover) |

[SamplesTest](tests/Video/E2E/SamplesTest.php) puts every one of them through probe, grab, tile
and packaging — HLS and DASH across the whole container matrix, CMAF and the multi-language cases on
the sources that have something to say — and asserts the codec is read from the file rather than its
extension.
[MediaTest](tests/Video/E2E/MediaTest.php) covers the same awkward properties as focused cases.

Locally, `composer test` runs both, `composer check` runs PHPStan and `composer lint` checks
formatting (`composer format` fixes it). Tests that need an encoder skip themselves when one is not
installed, as does the coroutine test without `ext-swoole` — so run the suite in the image if you
want the whole thing, since that is where both are guaranteed to be present.

## Copyright and license

The MIT License (MIT) [http://www.opensource.org/licenses/mit-license.php](http://www.opensource.org/licenses/mit-license.php)