# Utopia\Video v2 — Design

> Status: **implemented** · last updated 2026-08-23 · targets ffmpeg 8.1 (pinned in the repo image)
> CMAF dual-manifest strategy verified end-to-end against the repo image's ffmpeg build (see §4).

A small, generic PHP library for video workflows: **probe** media and its metadata (tech specs,
tags, chapters, tracks), **encode** renditions, **pack** adaptive streams (HLS, DASH, CMAF),
**grab** thumbnails, and **tile** sprite timelines.
Built on the Adapter pattern so every backend is swappable: the two public classes depend on
capability *interfaces*, never on ffmpeg.

Two entry points, one per kind of job:

- **`Encoder`** — one file out: an encode, a thumbnail, a sprite sheet.
- **`Packager`** — an adaptive ladder out: segments plus the manifests describing them.

Subtitles are read but not packaged, and metadata comes from one probe. Extracting, converting and
publishing subtitle files belongs to the application that owns them — for Appwrite, the Videos
worker (§6).

Boundaries — the library:

- takes **local input paths** (plain strings, so remote/live sources can slot in later — §11),
  writes **local output artifacts**, returns **structured results**;
- does not touch cloud storage, databases, HTTP, auth, queues, DRM, or live streaming
  (live is deliberately out of v2, but the API reserves clean seams for adding it later — see §11);
- depends on [`utopia-php/console`](https://github.com/utopia-php/console) for status lines, behind a
  `Reporter` seam so a caller that does not own stdout can redirect or silence them; backends are
  external binaries.

Style: one-word public methods (`open`, `probe`, `encode`, `pack`, `grab`, `tile`, `valid`),
short class names with no redundant context (`Adapter\FFmpeg`, not `FFmpegEncoderAdapter`),
config objects in, readonly results out.

## 1. Architecture

**One class per backend**, each declaring what it can do by implementing capability interfaces.
`abstract class Utopia\Video\Adapter` carries the plumbing they all need; the interfaces live in
`Utopia\Video\Adapter\*`:

```
Consumer (worker / CLI / app)
        │
        ├──────────────────────┐
        ▼                      ▼
     Encoder                Packager ─── facades: own the job chain, pick the execution path,
        │                      │                  push config into whichever adapter they were given
        └──────────┬───────────┘
                   ▼
        ├─ Adapter\FFmpeg     ffmpeg      → Encoder, Packager   (default for both facades)
        ├─ Adapter\FFprobe    ffprobe     → Probe               (default probe)
        └─ Adapter\Mock       none        → all three           (ships in src/, for tests)

Contracts:  Adapter\{Named, Observable} ← Adapter\{Encoder, Packager, Probe}
Shared:     abstract Adapter (identity, listeners, config, guards) · Adapter\{Job, Reads} traits
            Reporter (Console|Silent) — where status lines go · trait Decimal, shared by the
            adapter, config and argv hierarchies alike (four of them wanted one number formatter)

Config in   (immutable): Format (X264|HEVC|VP9|Copy) · Representation · Output (Hls|Dash|Cmaf) · Thumb · Tile
Results out (readonly):  Info{Track, Chapter} · Package{Variant, Segment, Manifest} · Spritesheet{Cue} · Progress
Internal (@internal):    Process (proc_open) · Arguments\{Hls,Dash,Cmaf} (argv) · Parser\{M3u8,Mpd}
                         Name (guards every caller-chosen string that reaches a path or argv)
```

Capabilities are interfaces rather than methods on one god-class, so a backend
that cannot do something simply does not declare it — and static analysis rejects
passing a pure packager where an encoder is required, rather than leaving it to
fail at run time. But *classes* follow binaries, not capabilities: ffmpeg encodes,
packages and pulls stills, so it is one class implementing two interfaces.
`ffprobe` is separate only because it is a separate binary with its own timeout.

**Replacing a backend is additive.** `Encoder` names only `Adapter\Encoder`,
`Packager` only `Adapter\Packager` (plus `Adapter\Encoder` for the staged path) —
neither mentions `Adapter\FFmpeg` outside a constructor default. So a new backend
is a new class and nothing else: write `Adapter\Shaka implements Adapter\Packager`
and `new Packager(new Shaka())` works, because the route for a packager that
cannot encode already exists and is unit-tested against a pure fake. Shaka itself
is deferred to a later release; the seam it plugs into ships now.

Three rules keep the design honest:

0. **Config is pushed into adapters, never passed to their constructors.** No
   adapter can build its own probe — the base has no default and no way to make
   one — so `new Encoder(probe: $mine)` reaches the adapter, including one the
   caller constructed. An earlier design let each adapter default its own probe,
   which silently ignored the one asked for.

1. **Data flows one way.** `Format`, `Representation`, `Output`, `Thumb`, and `Tile` are pure user config —
   adapters never write into them, and their setters return modified copies rather than touching the
   receiver, so they are immutable in both directions. Probe-derived facts (`hasVideo`, audio tracks,
   duration) live in `Info`, produced once at `open()`, and are passed to the argv builders and the
   progress reporter. The same `Output` instance is safely shareable across jobs — and across
   coroutines — and progress always knows the duration. Facades and adapters are the opposite: they
   hold one job's chain, so concurrent work takes one instance per coroutine (they cost a handful of
   assignments to build; nothing runs until `open()`). The one process-global the library touches,
   libxml's error flag in `Parser\Mpd`, is set and restored with no suspension point in between.
2. **`Packager::pack()` picks the execution path from the types, not a flag.** If the adapter is also
   an `Adapter\Encoder` (i.e. `Adapter\FFmpeg`), the whole job runs as **one fused ffmpeg
   invocation** — encode and package in a single pass. If it only packages, `Packager` first encodes
   one keyframe-aligned intermediate MP4 per representation via the encoder adapter, then hands them
   over (progress is unified: encodes weighted ~90%, packaging ~10%).

## 2. Public API

### Facades

Both take the same shape: an adapter, a probe and a reporter, each defaulted, each only ever known by
its interface. Adapter names below are written relative to `Utopia\Video\Adapter`.

```php
Encoder::__construct(?Adapter\Encoder $adapter = null, ?Adapter\Probe $probe = null,
                     ?Reporter $reporter = null)
Packager::__construct(?Adapter\Packager $adapter = null,
                      ?Adapter\Encoder $encoder = null,   // staged path only; defaults to the
                      ?Adapter\Probe $probe = null,       //   adapter itself when it can encode
                      ?Reporter $reporter = null)         // null keeps Reporter\Console (§10.12)
```

```php
$encoder  = new Encoder();      // ffmpeg to work with, ffprobe to read with
$packager = new Packager();

// One backend, shared — both facades push the same probe into it.
$ffmpeg = new Adapter\FFmpeg(threads: 4);
$encoder = new Encoder($ffmpeg);
$packager = new Packager($ffmpeg);

$encoder->getName();   // 'ffmpeg'   — what a backend chosen from config reports back
```

```php
// Read, on either facade, so a caller holding one of them can gate its own input
$info = $encoder->probe($in);       // Info — specs + tags, chapters, tracks
$ok   = $packager->valid($in);      // bool

// One-shot verbs on Encoder
$poster = $encoder->grab($in, $jpg, new Thumb());   // written path — thumbnail / poster frame
$sheet  = $encoder->tile($in, $dir, new Tile());    // Spritesheet

// Job chain — encode()/pack() are terminal, and clear the job they finish (§2, trait Job)
$file = $encoder->open($in)->format($format)->add($rep)->encode($out);   // one plain file (≤1 rep)

$package = $packager->open($in)
    ->format((new X264())->crf(22)->bframes(3)->keyframe(2.0))
    ->add(new Representation(width: 1280, height: 720, video: 2538, audio: 128))
    ->output((new Cmaf())->segment(6))
    ->on(Packager::PROGRESS, fn (Progress $p) => /* ... */ null)
    ->pack($outDir);                                                     // Package
```

`Encoder` delegates each call straight through — the adapter's own `open()` already restarts a job,
so there is nothing worth buffering. `Packager` buffers the chain, because `pack()` has to know the
whole job before it can choose between the fused and staged routes.

Listeners are the one thing a facade keeps rather than delegates. An adapter drops its own on
`open()`, which is what stops one job's listeners firing during the next; a facade re-registers
its listeners afterwards, so `on()` means the same thing before `open()` as after it. Without that,
`->on(...)->open(...)->pack(...)` — the ordering the chain invites — registered a listener and
then silently discarded it. `off($event)` drops one event's listeners and `off()` drops all of
them, which is how a reused facade swaps one job's listener for the next one's. `off()` is on
`Adapter` rather than on `Observable`, so a backend written against the narrow interface still
satisfies it.

Events: `PROGRESS` → `fn (Progress $p)`, `LOG` → `fn (string $line)` (raw backend stderr). The
constants live on `Adapter\Observable` — the interface that declares `on()` — and are re-exported by
both facades, so `Packager::PROGRESS` and `Encoder::PROGRESS` are the same string. Throttling is the
consumer's job: the library reports every progress block it gets. How *much* arrives as `LOG` is the
adapter's display level (below), not a filter in the facade — the backend decides what to print, and
everything it prints is forwarded.

### Adapter interfaces

Two parent interfaces hold what every backend shares, so nothing is declared twice:

```php
interface Named      { public function getName(): string; }
interface Observable { public function on(string $event, callable $listener): static; }

interface Encoder extends Named, Observable
{
    public function open(string $path): static;
    public function format(Format $format): static;
    public function add(Representation ...$representations): static;
    public function encode(string $path): string;          // exactly one output file

    // Stills live here rather than in an interface of their own: they are the same
    // work (decode the source, write a picture), and anything that can encode can
    // grab a frame, so splitting them out would only make a caller ask for two
    // capabilities where one will do.
    public function grab(string $path, string $output, ?Thumb $options = null): string;
    public function tile(string $path, string $dir, ?Tile $options = null): Spritesheet;
}

interface Packager extends Named, Observable
{
    public function open(string $path, ?Representation $as = null): static;
        // callable more than once within a job, one input per call; $as tags an
        // already encoded intermediate with the rung it represents
    public function add(Representation ...$representations): static;   // fused path: the ABR ladder
    public function output(Output $output): static;
    public function pack(string $dir): Package;
}

interface Probe extends Named
{
    public function read(string $path): Info;               // read(), not probe() — no Probe::probe() stutter
    public function valid(string $path): bool;
}
```

`abstract class Adapter` supplies `getName()`, `on()`, `available()`, the probe seam, and the guards
(`source()`, `directory()`, `wrote()`) that put the backend's name in every failure message.
`trait Job` holds the chain state shared by encoding and packaging — inputs, ladder, output, format —
and the rule about where one job ends and the next begins: a **terminal** clears the job it finishes,
so `open()` has nothing of the previous one left to sweep away and drops only the adapter's listeners.
That is what lets the chain read in either order — `add($rep)->open($in)` keeps its ladder, where
clearing on `open()` instead discarded it silently, exactly the bug that made listeners outlive a job
in the first place. `trait Reads` holds the one `valid()` body the probes share; `trait Decimal` the
one number formatter four hierarchies wanted.

`Packager` deliberately has no `format()` — a pure packager never encodes. `Adapter\FFmpeg` has it by
also implementing `Encoder`, which is exactly what makes the fused pass possible, and what
`Packager::pack()` branches on.

Inside the two facade files the interfaces are aliased (`use Adapter\Encoder as EncoderAdapter;`)
because the facade classes carry the same names. Concrete adapters live *in* the `Adapter` namespace,
so they never import the interfaces and never need the alias.

### Adapters

Every adapter takes the same constructor, declared once on the base — no
positional slot ever means two different things:

```php
__construct(?string $binary = null, ?int $timeout = null, int $threads = 0, ?string $level = null)
```

Defaults come from `NAME` / `BINARY` / `TIMEOUT` / `LEVEL` constants per class. Timeout `0`
disables the limit; `FFprobe` defaults to 30s, because reading metadata should
never hang.

`$level` is the **display level** — how much the backend says about its work:
`Adapter::QUIET | ERROR | WARNING | INFO | VERBOSE | DEBUG`, quietest first, the
set enumerated in `Adapter::LEVELS`. It defaults to `ERROR`, because the
interesting output of an encode is the file it wrote rather than its commentary,
and it maps onto what each backend already understands (`ffmpeg -loglevel`,
`ffprobe -v`). Whatever the backend then prints arrives as `LOG` events, so
raising the level is how a consumer gets an explanation without inventing a
second logging concept. Separately, finished encode / pack / grab / tile jobs
print a green success line through the adapter's `Reporter` (the terminal
unless a facade was given another), and a failed backend command reports an
error before throwing — so a consumer sees the outcome without a listener. Probe stays quiet on success. `QUIET` silences both the status lines
and the log; `PROGRESS` fires at every level, because progress is structured
data rather than commentary. An unrecognised level throws
`Exception\Unsupported` at construction — while it is still cheap to say so —
and `level()` reports whichever level a backend ended up with.

Like `available()` and `setProbe()`, `level()` lives on the abstract base rather
than on a capability interface: it is shared plumbing, not part of what it means
to be an encoder. Callers that hold an interface-typed backend and want it check
`instanceof Adapter` first, exactly as the facades do before pushing a probe.

| Class | Binary | Timeout | Notes |
|---|---|---|---|
| `Adapter\FFmpeg` | `ffmpeg` | 0 | `Encoder`, `Packager`. Every invocation is prefixed `-y -hide_banner -loglevel {level}`. Fused encode+package; `Format\Copy` is available for single-file `encode()` remuxes, but adaptive packaging rejects it because every video representation is filtered to its requested size and filters cannot be combined with stream copy. `encode()` writes one rendition of the picture and keeps **every** audio track. `grab()` takes an exact `-ss` seek or an auto representative frame (`-vf thumbnail`), and finds embedded cover art via `Info::$cover`. `tile()` owns the adaptive-interval table |
| `Adapter\FFprobe` | `ffprobe` | 30 | `Probe`. `-v {level} -print_format json -show_format -show_streams -show_chapters`. The payload comes back on stdout and the commentary on stderr, so a raised level cannot corrupt the JSON |
| `Adapter\Mock` | none | — | All three, writing placeholder files. Ships in `src/` so consumers can test pipelines with no tools installed |

Chosen by name when the choice comes from configuration, one factory per
capability so the return type is narrow enough to hand straight to a facade:

```php
Adapter::encoder(string $name, ?string $binary = null, ?int $timeout = null,
                 int $threads = 0, ?string $level = null): Encoder
Adapter::packager(...): Packager    Adapter::probe(string $name, ?string $binary = null,
                                                  ?int $timeout = null, ?string $level = null): Probe
```

Names are what `getName()` reports, and `Encoder::getName()`/`Packager::getName()` report them back,
which is how a backend chosen from config stays identifiable.

### Config objects

```php
// Representation — readonly value object, named-args friendly; no collection class, add() is variadic
new Representation(width: 1280, height: 720, video: 2538, audio: 128, name: '720p',
                   maxrate: null, bufsize: null);
// name defaults to "{height}p" — or "audio" for a rung with no frame size — and drives artifact
// naming, so it is restricted to letters, digits, underscores and hyphens: it becomes a filename, and
// it identifies the rendition to the muxer in a list where a comma or a space would end the entry.
// Anything else throws Exception\Input.
// Capped CRF by default: when unset, maxrate = video (hard bitrate ceiling, -maxrate:v:N) and
// bufsize = 2 × maxrate (the smoothing window, -bufsize:v:N) — busy scenes can no longer spike
// above the advertised rendition bitrate and stall constrained viewers.

// Format — codec preset + the GOP/quality knobs that gate ABR correctness; bitrate is NOT here
abstract class Format
{
    // Codecs are the preset's own; either is overridable at construction rather than by a
    // setter, because a preset whose codec was swapped afterwards is a different preset:
    //   new X264(audio: 'libfdk_aac')
    public function __construct(?string $video = null, ?string $audio = null);

    public function crf(int $crf): static;             // -crf
    public function bframes(int $count): static;       // -bf   (unset ⇒ the codec's own default)
    public function keyframe(float $seconds): static;  // -force_key_frames expr:gte(t,n_forced*S)
                                                      // unset ⇒ packaging uses the segment length,
                                                      // and an interval longer than a segment is
                                                      // rejected with Exception\Unsupported
    public function params(array $params): static;     // raw argv escape hatch (e.g. -dn -sn -vf …)

    // Getters: video()/audio() are the preset's defaults, codec()/sound() what this instance
    // settled on, interval() the configured keyframe cadence, supports() the outputs it can be
    // packaged into (which is what rejects VP9 in CMAF — §4).
}
Format\X264   // libx264 + aac; defaults keyint_min 25, g 250, sc_threshold 0
Format\HEVC   // libx265 + aac
Format\VP9    // libvpx-vp9 + libopus     (DASH-only packaging — see §4)
Format\Copy   // -c copy: remux one file with Encoder::encode(); adaptive packaging rejects stream copy

// Output — base: segment(float $seconds = 6.0), manifests(bool = true), name(string = 'stream'), params(array)
//          type() is the protocol this output is ('hls'|'dash'|'cmaf'), not a setter
Output\Hls  ->segments(Hls::MPEGTS | Hls::FMP4)  // default MPEGTS; fmp4 → init segment + EXT-X-MAP,
                                                //   playlist VERSION 7
            ->init('init.mp4')->master('master.m3u8')
            ->url($prefix)                      // written in front of every segment reference
            ->flags([...]);                     // hls_flags; defaults to ['independent_segments']
Output\Dash ->template(bool)->timeline(bool) // both false ⇒ explicit <SegmentList>/<SegmentURL>; default true/true
            ->init($pattern)->media($pattern)->manifest('manifest.mpd')->sets($definition);
Output\Cmaf ->master('master.m3u8')->manifest('manifest.mpd')
            ->template(bool)->timeline(bool)->init($pattern);   // one fMP4 set, both manifests — see §4
            // Extends Dash but inverts its defaults: template and timeline both start false, because
            // explicit addressing is what keeps the two manifests in step over one segment set.

// Thumb — thumbnail options
(new Thumb())->time(null)      // null = auto: -vf thumbnail picks a representative frame; float = exact seek (s)
             ->width(320)      // px, height derived from aspect; 0 = source size
             ->quality(2);     // image format inferred from the output extension: .jpg / .png / .webp

// Tile — sprite options
(new Tile())->interval(null)   // null = adaptive by duration: 2s (<120s), 5s (<600s), 10s (<1800s), 20s (<3600s), 30s
            ->width(160)       // thumb width px; height derived from aspect ratio
            ->grid(5, 5)->quality(3)->name('sprite')->vtt(true);
```

Multi-audio sources: every audio stream becomes a separate audio track in the package (HLS
`var_stream_map` audio group / DASH audio adaptation set), with the first flagged as default. A
language tag is what labels a track, not what qualifies it — untagged streams are carried unlabelled
rather than dropped, since a source with four untagged dubs still has four. Explicit track
selection/ordering can be added to `Output` later without breaking anything.

### Result objects (all readonly)

```php
Info         // probe result — container + primary video/audio stream + full metadata
  duration (s) · format · hasVideo · hasAudio · width · height · aspect ("16:9") · fps
  fpsMode ('Constant'|'Variable') · videoCodec · videoFormat · videoProfile · videoBitrate (bps)
  audioCodec · audioFormat · audioBitrate (bps) · sampleRate · audioTracks [{codec, language}]
  tags []     // container-level descriptive metadata: title, artist, album, creation_time, encoder, …
  tracks: Track[] · chapters: Chapter[] · rotation (degrees, from the display matrix, else null)
  cover       // stream index of embedded artwork, else null — a sound file reports no video but
              //   may still have a picture, which is what grab() reaches for
  raw []      // full decoded backend payload — the genericness escape hatch
  milliseconds(): int          // duration as most catalogues store it
  ratio(): ?string             // aspect, derived from the frame size when the container omits one
  tracks(string $type): Track[] // one stream kind, e.g. tracks(Track::SUBTITLE)

Track        // every stream, not just the primary: index · type ('video'|'audio'|'subtitle'|'data')
             // codec · language · title · default (bool) · forced (bool) · tags []
Chapter      // start (s) · end (s) · title

Package      // pack() result — the structured source of truth (see §5)
  variants(): Variant[]                    // per HLS variant / DASH AdaptationSet
  segments(?string $variant): Segment[]    // flat, optionally filtered
  manifests(): Manifest[]                  // [] when Output::manifests(false)
  files(): string[]                        // every artifact path, ready for upload
  metadata(): array                        // MPD attrs (profiles, type, mediaPresentationDuration,
                                           // maxSegmentDuration, minBufferTime) + HLS attrs (version, targetDuration)
  duration(): float
  variant(string $id): ?Variant            // one rung by name
  size(): int                              // total bytes across every segment

Variant      // id · type ('video'|'audio') · mimeType · codecs · bandwidth · width · height · sar
             // sampleRate · language · timescale · startNumber · target · segments[] · playlist (path|null)
             // resolution(): ?string — "1280x720", or null for an audio rung
Segment      // variant · file · path · duration (0.0 for init) · init (bool) · number · size (bytes)
Manifest     // type (Manifest::HLS | Manifest::DASH) · path · main (bool: master/mpd vs media playlist)

Spritesheet  // images(): string[] · cues(): Cue[] · vtt(): ?string · files(): string[]
             // width(): int · height(): int — one thumbnail's size, for a consumer sizing its own CSS
             // render(?callable $url): string — the WebVTT body, each sheet URL rewritten by $url
Cue          // start · end · file · x · y · width · height
             // render(?string $url): string — one cue as structured #xywh, URL freely rewritten

Progress     // percent (0–100, computed from $info->duration inside the adapter) · time · frame · fps · speed
```

### Exceptions

```php
Exception extends \Exception                 // library base
Exception\Input        // missing/unreadable/invalid source, bad chain state (e.g. encode() with 2 reps),
                       //   and every rejected name, flag or pattern (§10.13)
Exception\Unsupported  // the job cannot be expressed: a codec the output cannot carry (VP9 + CMAF),
                       //   stream copy in an adaptive package, a keyframe interval longer than a
                       //   segment, a segment container or display level that does not exist
Exception\Runtime      // a backend command failed, or succeeded without writing what it promised —
                       //   command(): full argv, output(): stderr tail, and the exit code as the
                       //   exception code (§10.15)
```

### Deviations from v1

`save()`/`run()` are gone — `encode()`/`pack()` are the terminals, synchronous, returning results.
v1's single `Adapter` interface is replaced by three capability interfaces plus an abstract base, and
v1's one `Encoder` facade gains `Packager` as its sibling. `Representations` is dropped for a
variadic `add()`. `Representation` is a readonly constructor VO, not a setter builder. Config arrays
(`['timeout' => 0, 'ffmpeg.threads' => 4]`) become named constructor args. `Output` no longer carries
probe-derived state. Exceptions root in `\Exception` properly.

## 3. Adapter matrix

| Capability | `Adapter\FFmpeg` | `Adapter\FFprobe` | `Adapter\Mock` |
|---|:-:|:-:|:-:|
| encode (scale/bitrate/CRF, capped CRF) | ✅ | — | ✅ pretend |
| keeps every audio track through `encode()` | ✅ | — | — |
| pack HLS (MPEG-TS) | ✅ | — | ✅ pretend |
| pack HLS (fMP4) | ✅ | — | — |
| pack DASH `SegmentTemplate` | ✅ | — | — |
| pack DASH `SegmentList` | ✅ | — | — |
| pack CMAF (dual manifests) | ✅ **default** | — | — |
| one adaptation set / rendition per audio language | ✅ | — | — |
| fused single-pass encode+pack | ✅ | — | ✅ |
| progress events | ✅ `-progress pipe:1` | — | ✅ pretend |
| display level (`-loglevel` / `-v`) | ✅ | ✅ | ✅ withholds its log when quiet |
| probe / valid | via the injected probe | ✅ | ✅ |
| tags / chapters / tracks / rotation / cover | — | ✅ | partial |
| grab thumbnail / cover art | ✅ | — | ✅ pretend |
| tile sprite sheet + WebVTT cues | ✅ | — | ✅ pretend |
| external binary | `ffmpeg` | `ffprobe` | none |

`Adapter\FFmpeg` is the default for both facades and the only backend that does real work; `Mock`
exists so a consumer's pipeline can be tested with no tools installed.

**Deferred to a later release:** `Adapter\Shaka` (`packager` binary, `Packager` only). It is
deferred rather than designed out — the staged route it needs is implemented, documented (§1) and
unit-tested against a pure fake, so graduating it is one new class plus a factory arm. Its real
payoff is DRM, which is out of scope here. Its known limits, for whoever writes it: cannot encode,
no progress beyond start/end, `SegmentTemplate`/`SegmentBase` MPDs only (no `SegmentList`), and
fMP4-only for dual output.

## 4. CMAF strategy

`Output\Cmaf` = **one shared fMP4 segment set + both an HLS playlist tree and a DASH MPD referencing
the same `.m4s` files**. Default backend: the FFmpeg dash muxer.

```
-f dash -dash_segment_type mp4
-seg_duration 6 -window_size 0
-use_template 0 -use_timeline 0                       # or 1/1 via template()/timeline()
-init_seg_name  '{name}_init_$RepresentationID$.m4s'
-media_seg_name '{name}_chunk_$RepresentationID$_$Number%05d$.m4s'
-adaptation_sets 'id=0,streams=v id=1,streams=a'
-hls_playlist 1 -hls_master_name master.m3u8
{dir}/{name}.mpd
```

Verified behavior (ffmpeg `dashenc.c` + formats docs, and reproduced live against the repo image's
ffmpeg 8.1.2 build: one command over a test source produced a `SegmentList`/`SegmentURL`-only MPD,
a VERSION-7 HLS master with an audio group, and `media_%d.m3u8` playlists with `EXT-X-MAP` — all
referencing one shared `.m4s` segment set. Re-checked on 8.1.2 when the pin moved off 7.1: every gap
below is still a gap, so every workaround is still load-bearing):

- `-hls_playlist 1` writes a master plus one media playlist per stream that enumerate the **same
  concrete segment files** the MPD references — it works in both `SegmentTemplate` and `SegmentList`
  modes, because the HLS writer iterates real segment records, never templates.
- Media playlist names are **hardcoded** `media_%d.m3u8` (only the master name is configurable);
  `Package` records the stream-index → playlist mapping.
- HLS output requires mp4 segments (`-dash_segment_type mp4` is forced by `Output\Cmaf`); VP9-in-CMAF
  is therefore unsupported. The rejection is not the adapter's: `Arguments` checks the output's
  protocol against `Format::supports()` before a command is built, so `Format\VP9` + `Cmaf` throws
  `Exception\Unsupported` from any backend, and a new codec declares its own limits in one place.
- `-single_file` is never used (byte-range playlists defeat per-segment addressing).
- The dash muxer never writes `EXT-X-INDEPENDENT-SEGMENTS`. Since every segment starts on a forced
  keyframe the guarantee genuinely holds, so `pack()` post-appends the tag to the CMAF master —
  faster quality switching in players and clean Apple validation for one line of string editing.
- Requires ffmpeg ≥ 4.1. The image builds and pins **8.1.2**, which is what CI tests against
  (§10.7). 4.1 remains the documented floor; older lines are not exercised by CI.

Why FFmpeg is the default, and why Shaka is deferred: one ffmpeg process does encode+package (no
intermediate files), progress reporting exists, `SegmentList` MPDs are supported, and ffmpeg is
already present wherever the encoder runs. Shaka packages beautifully but cannot transcode, reports
no progress, and only emits `SegmentTemplate`/`SegmentBase` MPDs — a consumer needing per-segment
URLs from Shaka would have to rely on `Package::segments()` parsed from its HLS playlists (which do
always enumerate concrete files) rather than its MPD. That is exactly the dynamic-manifest mode
described next.

Standalone HLS fMP4 (`Output\Hls::segments(Hls::FMP4)`) for completeness: `-hls_segment_type fmp4`,
`-hls_fmp4_init_filename`, `.m4s` segment extension, auto `EXT-X-MAP` + `EXT-X-VERSION:7`, plus
`-hls_flags independent_segments`; `hls_allow_cache` is dropped in fMP4 mode (the tag was removed
from the HLS spec at protocol version 7).

## 5. Manifest dual-mode

Consumers split into two camps: those who **serve** the playlist files as-is, and those who **ignore**
them and rebuild manifests dynamically (e.g. to rewrite every segment URL to an authenticated,
per-segment endpoint). The library serves both with one rule:

> `pack()` always parses what the packager produced into structured `Package` data.
> `Output::manifests(bool)` only controls whether the playlist *files* are kept.

- **`manifests(true)`** (default): playlists stay on disk, listed in `manifests()` and `files()`.
- **`manifests(false)`**: playlists are parsed, then deleted; `manifests()` returns `[]`, and
  `files()` contains only segments — nothing forces the consumer to host library-written playlists.

The internal parsers (`Parser\M3u8`, `Parser\Mpd`) are the single source of `segments()`:

- **M3u8**: `#EXT-X-MAP` → init entry; `#EXTINF` + URI → media entries; captures `TARGETDURATION`,
  `VERSION`; master parse yields `BANDWIDTH`/`CODECS`/`RESOLUTION`/audio groups per variant.
- **Mpd**: `SegmentList` mode reads `<Initialization>`/`<SegmentURL>` plus
  `timescale`/`duration`/`startNumber` directly; `SegmentTemplate` mode is **expanded to concrete
  segment names** (substituting `$RepresentationID$`, `$Number%0Nd$`, `$Bandwidth$` and `$Time$`,
  iterating `SegmentTimeline` runs) so `segments()` is complete regardless of addressing mode.
  `$Time$` is the running total of the durations declared ahead of a segment, and an `S@t` restarts
  that clock rather than being added to it. Every identifier a name is *allowed* to carry is resolved
  here, which is the rule that keeps the writing and reading halves in step: `Name::template()` and
  this parser accept exactly the same set, so a template the library approved cannot produce a
  manifest pointing at filenames nothing on disk is called. `$ext$` is the one identifier accepted
  but never met, because the muxer resolves it while writing the manifest; `$SubNumber$` is refused,
  because subsegment addressing is neither written nor read.
- **Integrity check**: after parsing, every referenced segment is checked with `is_file()` and sized
  with `filesize()`, and the size is what `Segment::$size` reports. A reference with nothing behind it
  fails `pack()` with `Exception\Runtime` — a truncated run can never return a plausible-looking
  `Package`.

The same pattern applies to sprites: `Spritesheet::cues()` (structured
`start/end/file/x/y/width/height`) is
always populated; `Tile::vtt(false)` skips writing the `.vtt` file for consumers that generate their
own timeline documents with rewritten URLs.

## 6. Appwrite Videos worker mapping

The one concrete consumer driving requirements. Appwrite owns everything around the library; the
library owns everything between a local input file and local artifacts + structured results.

| Concern | Owner | v2 call / note |
|---|---|---|
| Download / decrypt / decompress source from storage | **Appwrite** (Devices, OpenSSL, Zstd/GZIP) | produces the local `$in` path |
| Videos / profiles / renditions CRUD, DB, permissions | **Appwrite** | — |
| **Subtitles end to end** — extracting embedded tracks, converting to WebVTT, storing them, referencing them from served manifests | **Appwrite** | the library only *reports* them: `Info::tracks(Track::SUBTITLE)` names each one with its language. Nothing is written, and `pack()` never emits a subtitle rendition |
| Queue, worker loop, statuses, realtime events, usage metrics, cleanup | **Appwrite** | — |
| Probe fields for the `videos` document | **Library** | `$encoder->probe($in): Info` — duration (seconds; × 1000 for the ms the DB stores), format, width/height, aspect, fps + fpsMode, video codec/format/profile/bitrate, audio codec/format/bitrate/sampleRate, hasVideo/hasAudio. `Adapter\FFprobe` is the one probe; a worker holding only a `Packager` can call `probe()`/`valid()` on it too |
| Descriptive metadata (tags, chapters, embedded track list) | **Library** | `Info::tags` / `chapters` / `tracks` — e.g. title, creation time, embedded-subtitle detection, rotation |
| Validity gate before encode | **Library** | `$encoder->valid($in)` |
| Encode + package one rendition (one `Representation` per job) | **Library** | `$packager->open($in)->format((new X264())->crf(22)->bframes(3)->keyframe(2.0)->params(['-dn','-sn']))->add($rep)->output($output->segment(6))->on(Packager::PROGRESS, $cb)->pack($outDir)` |
| DASH with per-segment document-id URLs | **Library** | `(new Dash())->template(false)->timeline(false)` → explicit `<SegmentList>` (today's load-bearing `use_template 0 use_timeline 0`) |
| Segment rows + rendition `metadata` JSON | **Appwrite DB ← Library** | `Package::segments()` / `variants()` / `metadata()` replace the worker's `parseHlsMaster` / `parseHlsPlaylist` / `parseMpd` (~200 lines deleted from the worker) |
| Upload artifacts to storage | **Appwrite** | iterate `Package::files()` / `Spritesheet::files()` |
| Progress → DB + realtime | **Appwrite** | `Packager::PROGRESS` callback; worker keeps its `percent % 3 === 0` throttle |
| Dynamic manifest serving (phtml templates) | **Appwrite** | library playlists skipped via `manifests(false)` — never served |
| Sprite timeline (160px thumbs, 5×5 jpeg, adaptive interval, WebVTT `#xywh`) | **Library** | `$encoder->tile($in, $dir)` — `Tile` defaults match today's table and filter; Appwrite rewrites URLs from `cues()` to its preview endpoints |
| Poster / thumbnail image | **Library** | `$encoder->grab($in, $out, (new Thumb())->time($t))` — auto-picks a representative frame when no time is given; resize/crop at serve time stays Appwrite-side |

## 7. Source layout

PSR-4 `Utopia\Video\` → `src/Video`, flat and small:

```
src/Video/
├── Encoder.php · Packager.php                 the two facades
├── Adapter.php                                abstract base: identity, listeners, config, guards
├── Adapter/
│   ├── Named.php · Observable.php              shared contracts (Observable owns PROGRESS/LOG)
│   ├── Encoder.php · Packager.php · Probe.php  capabilities
│   ├── Job.php · Reads.php                     @internal traits
│   ├── FFmpeg.php      Encoder + Packager
│   ├── FFprobe.php     Probe
│   └── Mock.php        all three (no binary, for tests)
├── Format.php · Output.php · Representation.php · Thumb.php · Tile.php   config
├── Format/ X264.php · HEVC.php · VP9.php · Copy.php
├── Output/ Hls.php · Dash.php · Cmaf.php
├── Info.php · Track.php · Chapter.php · Package.php · Variant.php        results
├── Segment.php · Manifest.php · Progress.php · Spritesheet.php · Cue.php
├── Exception.php · Exception/ Input.php · Unsupported.php · Runtime.php
├── Reporter.php                               where status lines go
├── Reporter/ Console.php · Silent.php          the terminal, or nowhere
├── Name.php                                   @internal guards for caller-chosen strings
├── Decimal.php                                @internal shared number formatter
├── Process.php                                @internal proc_open + stream_select wrapper
├── Arguments.php · Arguments/ Hls.php · Dash.php · Cmaf.php   @internal argv builders
└── Parser/ M3u8.php · Mpd.php                 @internal manifest → Package readers
```

Tests mirror the house split: `tests/Video/Unit` (no binaries needed) and
`tests/Video/E2E` (drives a real encoder), exposed as the `unit` and `e2e`
PHPUnit suites.

Exception names avoid colliding with the config and runner classes they sat beside:
`Exception\Unsupported` (was `Output`, which clashed with the `Output` config class) and
`Exception\Runtime` (was `Process`, which clashed with the process runner). That removed a forced
`as` alias from 15 files.

`composer.json`: `"require": {"php": ">=8.2", "ext-dom": "*", "ext-json": "*",
"utopia-php/console": "^0.2"}` — `ffmpeg`/`ffprobe` are external binaries, and console only prints the
status line, behind the `Reporter` seam. `ext-swoole` is a **suggest**, never a requirement: the
library only has to keep yielding inside a coroutine, which is a property of how it is written rather
than something it imports (§10.17). `swoole/ide-helper` sits in `require-dev` for editors —
phpstan needs no help, since it resolves the Swoole symbols from stubs bundled in its own phar.
The `php-ffmpeg/php-ffmpeg` dependency is
dropped —
v1 already bypassed its command model, and it was the root of the vendor-type leaks (`Format`
extending `DefaultVideo`, `Probe` exposing `StreamCollection`) and the duration-less progress
listeners. `ffmpeg -progress pipe:1 -nostats` emits stable machine-readable `key=value` blocks;
`ffprobe -print_format json` is one `json_decode` away from `Info`.

## 8. Migration

| Today | v2 |
|---|---|
| `aminyazdanpanah/php-ffmpeg-video-streaming` fork: `$media->hls()/dash()` + `setAdditionalParams([...])` | `Adapter\FFmpeg` fused pass; extra args → `Format::params()`; segment duration → `Output::segment()` |
| `use_template 0` / `use_timeline 0` fork patch | first-class: `Output\Dash::template(false)->timeline(false)` |
| `Mhor\MediaInfo` + `FFProbe::isValid` | `Adapter\FFprobe` → `Info`; `valid()` on either facade. MediaInfo is gone: two probes meant two field-mapping surfaces to keep honest for no gain over `Info::$raw` |
| `captioning/captioning` `SubripFile->convertTo('webvtt')` | **stays in the worker.** Subtitle conversion is not video work, needs no encoder, and the worker already owns the files |
| Hand-built sprite ffmpeg command in the worker | `Encoder::tile()` — same `select/scale/tile` filter, `-qscale:v 3`, `sprite%d.jpg` naming, adaptive interval table now inside the adapter |
| Poster frames: none today (previews are Imagick-cropped sprite cells at serve time) | first-class `Encoder::grab()` — dedicated representative-frame or timestamped poster; sprite-cell cropping for scrubbing stays consumer-side |
| Probe limited to technical fields | `Info` adds `tags`, `chapters`, `tracks` (full stream list incl. embedded subtitles), `rotation`, `cover` |
| Worker-side `parseHlsMaster` / `parseHlsPlaylist` / `parseMpd` | `Parser\M3u8` / `Parser\Mpd` → `Package::variants()/segments()/metadata()` |
| php-ffmpeg stderr-regex progress (duration never wired → dead listeners) | `-progress pipe:1 -nostats` → `Progress` with a real `percent` |
| v1 `save()`/`run()`, `Representations`, `Output` mutated by the adapter | removed / restructured (§2) |
| v1 HLS multi-rep bug: N reps → N positional outputs, each re-encoding everything and rewriting `master.m3u8` (last wins) | one command: video mapped once **per rung**, options indexed `:v:N`, a single `-var_stream_map "v:0,agroup:aud,name:360p v:1,… a:0,agroup:aud,default:yes"`, one `master_pl_name`, one `%v` output pattern |
| Raw `exec()` / `Console::execute` shell strings | `Process` (@internal): argv arrays (no shell), line callbacks, timeout, `Exception\Runtime` with command, stderr tail and exit code |

Consumers migrate incrementally: `probe()` is drop-in first, `tile()`/`grab()` second, `pack()` last
(it changes the parsing/DB-write side from hand-rolled to `Package`).

## 9. Test plan

**Unit — no binaries required**

- `Arguments\{Hls,Dash,Cmaf}`: argv assertions on the built command — multi-rep single-command shape
  (the v1 regression), SegmentList vs SegmentTemplate flags, fMP4 flag set (`independent_segments`,
  no `allow_cache`), audio-only inputs dropping all video args, single-file `Format\Copy` remux and
  adaptive-package rejection, capped-CRF
  defaults (`-maxrate:v:N`/`-bufsize:v:N` derived when unset), multi-audio `var_stream_map` built
  from language tags with untagged tracks still carried, and the keyframe cadence falling back to the
  segment length (with an interval longer than a segment rejected).
- `Parser\{M3u8,Mpd}` on fixture manifests: TS + fMP4 (`EXT-X-MAP`) playlists, `SegmentList` MPDs,
  `SegmentTemplate` expansion (`$Number%05d$`, `$Bandwidth$`, `$Time$`, `SegmentTimeline` `S@t/@d/@r`
  runs, including a timeline whose `S@t` restarts the clock rather than advancing it).
- Probe JSON fixtures (ffprobe payloads): every `Info` field incl. `tags`, `chapters`, `tracks`
  (multi-track with embedded subtitles), `rotation`, `cover`, audio-only, missing-field defaults.
- The facades against fakes: `Encoder` delegation, `Packager`'s fused-vs-staged choice, staged
  progress climbing once 0→100 across every rung, listener forwarding, `open()` forgetting the last
  job's config, and listeners surviving it (registered before `open()` or after, dropped by `off()`). The staged half runs against a **pure `Adapter\Packager` fake** — that is what keeps the v2
  packager seam continuously proven while no pure packager ships.
- `Thumb` defaults and grab argv: exact `-ss` seek vs `-vf thumbnail` auto mode, scale math,
  image format from output extension, cover-art (`attached_pic`) mapping.
- `Tile` adaptive-interval table; cue geometry math (`x/y` from grid position); every `Tile` and
  `Thumb` knob that cannot work (zero interval, unscalable width, empty grid, off-scale quality)
  rejected as `Exception\Input` at the setter rather than as a division by zero mid-job.
- Config validation: `Representation` bounds, `Format` knobs, unsupported `Output` combos throwing
  `Exception\Unsupported` — including a mixed-aspect ladder for DASH/CMAF, whose message names the
  offending rungs (the muxer says only "Conflicting stream aspect ratios", after it has started).
- Config immutability: every setter on `Format`, `Output`, `Thumb` and `Tile`, table-driven —
  the return is a new instance, the receiver keeps its value, the copy carries the new one — plus
  the sharing scenario itself (two holders of one preset configuring it independently). An E2E
  (`CoroutineTest`) then proves the same thing under a real scheduler: three coroutines, one
  `Encoder` each, off one shared `Thumb` under `SWOOLE_HOOK_ALL`, asserting that all three stills
  were written, that none of the jobs failed, and that the shared `Thumb` came out unmutated. The
  image ships ext-swoole so this runs rather than skips (§10.17) — it is the only test that shows
  `proc_open` and `stream_select` genuinely yielding instead of deadlocking.
- `Process`: fake scripts exercising exit codes, timeouts (including the stderr tail surviving the
  termination that ends them), stdout/stderr line callbacks, progress-block parsing, and a stream
  that never sends a line break. Plus how a run ended: a signalled command reports 128 + its signal,
  a command that ignores `SIGTERM` is killed after the grace period and says so, and a command that
  will not stop printing fails on the output cap instead of exhausting memory.
- Names that would leave the directory they were given or break the argument they sit in
  (`Representation`, `Output::name()`, manifest and playlist filenames, `Tile::name()`), and the
  settings that reach argv without being filenames (`Hls::url()`, `Hls::flags()`, `Dash::sets()`),
  each held to the rule its own use needs (§10.13).
- Job lifecycle on the adapters: a ladder or format described before `open()` survives it, a terminal
  clears the job it finished, and the next `open()` therefore starts from nothing rather than
  inheriting it.
- Status lines: reported through a `Reporter`, the terminal by default, whichever one a facade is
  given reaching every backend, and a quiet backend reporting nothing.
- Display level: every backend starts at `ERROR`; a level can be raised or silenced; the factories
  pass it through and omitting it keeps each backend's default; an unrecognised level is rejected at
  construction; the level reaches the built command; a quiet backend still reports progress.

**Integration — ffmpeg required (generated `testsrc`+`sine` fixture, Docker CI)**

- HLS TS and fMP4: master lists **all** variants (multi-rep regression), media playlists +
  segments exist.
- DASH SegmentList: `<SegmentURL>` present, no `<SegmentTemplate>`.
- CMAF: MPD, `master.m3u8`, and `media_0.m3u8` all reference the **same** `.m4s` files; the master
  carries the post-appended `EXT-X-INDEPENDENT-SEGMENTS` tag.
- HEVC end-to-end (HLS fMP4, DASH, CMAF) and VP9 end-to-end (DASH) — both codecs are first-class;
  impossible combos (VP9 + CMAF/HLS) throw `Exception\Unsupported`.
- `Package::segments()` sizes match the files on disk; `manifests(false)` leaves no playlist files;
  `files()` uploads cleanly.
- Progress fires with ascending `percent` reaching 100; audio-only input packs; `encode()`
  produces a single playable file; `tile()` sprite count and cue math match fixture duration, and
  tiling a shorter source into a directory that already holds a longer run's sheets reports only
  the sheets it wrote.
- `grab()` writes a decodable image at the requested size (timed and auto modes); cover art is
  extracted from an audio fixture with embedded artwork; `probe()` reads a chaptered fixture.
- The display level genuinely changes what ffmpeg reports: a clean encode emits no `LOG` lines at
  `QUIET` or at the default `ERROR` (there is nothing wrong to report) and several at `VERBOSE`.
- Several audio languages: one rendition/adaptation set each, `LANGUAGE=` present on every HLS and
  CMAF audio rendition, exactly one `DEFAULT=YES`; `encode()` carries all four dubs into MP4, MKV,
  MOV, WMV and AVI.
- Subtitles are reported by `probe()` and **never packaged**: a source with three subtitle tracks
  yields zero subtitle variants, no `*.vtt` beside the media, and no `TYPE=SUBTITLES` in the master.

**Breadth** — a generated library of 25 sources under `tests/samples/in` (10 containers, 7 video and
5 audio codecs, multi-audio, multi-subtitle, anamorphic/VFR/rotated/portrait/tiny/silent, chapters,
audio-only, embedded artwork) put through probe, grab, tile and packaging, with results left in
`tests/samples/out`. Built by `composer samples`, gitignored rather than committed.

**Parity** — the argv assertions above are written against the captured legacy production command
shape, so a rung, flag or map that v1 emitted cannot quietly disappear.

Every test prints one line naming what it checks and whether it passed — PHPUnit's testdox output,
enabled in `phpunit.xml` so it applies to every run rather than needing a flag. Method names are
written to read as sentences; where a name cannot (a leading acronym, or a digit testdox would split,
as in `Mp4` → "Mp 4"), a `@testdox` annotation supplies the wording instead. A run therefore reads as
a description of what the library does.

CI: GitHub Actions matrix (PHP 8.2/8.3/8.4 against the pinned ffmpeg 8.1.2 the image builds), phpstan
at level **max** over `src` and `tests`, pint (`psr12`). All three run on pull requests and on pushes
to `main`.
The repo Dockerfile builds ffmpeg **from source**, pinned via the `FFMPEG_VERSION` build arg
(GPL, with libx264/x265/vpx/opus/lame/theora/vorbis — exactly the `Format` presets and the sample
library's codecs), in a stage that shares the runtime base image so codec shared libraries match
ABI-for-ABI. ext-swoole is built the same way, pinned via `SWOOLE_VERSION`, in a stage of its own so
it compiles alongside ffmpeg rather than after it. Both are then *asserted* in the final stage, not
merely printed: a swoole that failed to load would otherwise turn the coroutine suite back into a
skip, and a skipped test says nothing about being absent. Building with `TESTING=true` installs dev
dependencies so `phpunit` runs in-image.

## 9a. Several audio languages, and where subtitles stop

A source with more than one tagged audio track yields one selectable rendition per language in every
output: `EXT-X-MEDIA:TYPE=AUDIO` rows for HLS, one `<AdaptationSet lang>` per language for DASH. Video
rungs remain alternatives of one another and share a set; languages are separate choices and must not
— collapsing them into one set was a real bug, caught only by a four-language sample.

Two things the muxers get wrong that are corrected on the way out:

- The dash muxer's HLS output omits `LANGUAGE` from its audio renditions, so a CMAF package would
  offer several dubs with nothing to tell them apart. They are named from the probed track order.
- Neither muxer writes `EXT-X-INDEPENDENT-SEGMENTS` where it counts: the dash muxer never writes it,
  and the hls muxer writes it into the video playlist but not the audio one. It is declared once in
  the master instead, which covers every rendition.

`encode()` produces one rendition of the picture but keeps **every audio track**. This matters beyond
convenience: the staged packaging path builds its intermediates with `encode()`, so dropping tracks
there would silently cost every dub but the first before a pure packager ever saw the file.

**Subtitles are read, not packaged.** `probe()` reports every embedded subtitle track — index, codec,
language, title, default/forced flags — and that is where the library stops. Extracting each track,
converting it, storing it and referencing it from a served manifest all belong to the application
that owns those files; for Appwrite that is the Videos worker (§6). So `pack()` emits no subtitle
rendition, writes no WebVTT, and leaves no `TYPE=SUBTITLES` in a master, whatever the source
contained. There is a regression test that says exactly that against a three-subtitle source.

(The `.vtt` that `tile()` writes is a thumbnail index, not a subtitle track. Different thing, same
file extension.)

Container limits worth knowing when reading `Info`: AVI cannot record a per-stream language at all,
and AVI/WMV report French as the bibliographic `fre` where MP4, MOV and Matroska keep `fra`. The
library reports what the file says; `Info::$raw` has the untouched payload.

## 10. Decisions

All former open questions, resolved with the maintainer (2026-07-26):

1. **Package identity**: `utopia-php/video`, renamed from `utopia-php/streaming` alongside the
   namespace (2026-07-31).
2. **Namespace**: `Utopia\Video` (2026-07-31), renamed from `Utopia\Streaming`. The library is
   named for its subject rather than one of the things it does with it — packaging for adaptive
   streaming is a feature, not the whole scope, which already covers probing, encoding, stills and
   sprite timelines. The public classes stay `Encoder` and `Packager`, matching the `main` branch's
   shape (`new Encoder($adapter)`), so they read as `Video\Encoder` and `Video\Packager`.
   "Streaming" survives only where it is the domain term — adaptive streaming, live streaming, and
   the expansion of HLS.
3. **HEVC / VP9**: full first-class support — both codecs integration-tested end-to-end (§9);
   impossible combos (VP9 in CMAF/HLS) fail loudly with `Exception\Unsupported` instead of degrading.
4. **Rate control**: capped CRF by default — `Representation` carries optional `maxrate`/`bufsize`,
   auto-derived (`maxrate = video`, `bufsize = 2 × maxrate`) when unset (§2).
5. **`EXT-X-INDEPENDENT-SEGMENTS`**: post-appended to CMAF masters (§4) — the guarantee genuinely
   holds and the edit costs one line.
6. **Multi-audio**: every audio stream becomes a track, labelled with its language tag where it has
   one, first one is default (§2); explicit selection is a later additive option.
7. **ffmpeg version**: pin the newest release the suite actually passes on, via the `FFMPEG_VERSION`
   build arg; 4.1 is the documented floor. Revised 2026-08-23 from "the most mature LTS line" (7.1):
   with no consumers yet there is nothing to be conservative for, so the pin was walked forward
   empirically instead. **8.1.2** is where it stopped. 9.0.1 does not work: ffmpeg **segfaults**
   (SIGSEGV) decoding an MPEG-TS file that ffmpeg itself wrote, which took out packaging and stills
   for that source while every other container passed. Nothing in our argv is implicated — a plain
   `-vf scale` over the same input crashes just as readily, stderr is empty at `-loglevel error`, and
   the 9.0 changelog removes nothing this library emits (`-psnr`, `-map_channel` and the old HLS
   *protocol* handler, none of which we use). So the ceiling is upstream, not ours; revisit on 9.0.2.
   The cost of moving lines is never the version string — it is re-verifying §4, because the CMAF
   strategy is built on observed muxer gaps that a new release could quietly close.
8. **`Adapter\Shaka`**: deferred to a later release rather than shipped as a stub — an untested
   adapter for an absent binary is a liability, and DRM (the reason Shaka exists here) is out of
   scope. What ships instead is the *seam*: the staged route, exercised by a pure-packager fake, so
   graduating Shaka is one new class plus a factory arm and no facade change (§1, §3).
9. **Batch stills**: `grab()` stays one-frame-per-call; a `Thumb::count(n)` single-pass mode is
   added only if looping proves a hot path.
10. **Display level** (2026-07-31): one `LEVEL` constant per backend, defaulting to `ERROR`,
   overridable per instance and through the factories, mapped onto each binary's own verbosity flag.
   The library does not filter what a backend prints — it raises or lowers what the backend says and
   forwards all of it as `LOG`, so there is no second logging concept to keep in step.
11. **Subtitles and MediaInfo are out of scope** (2026-07-31): the Videos worker already owns the
   subtitle files, and their conversion needs no encoder; a second probe meant a second field-mapping
   surface to keep honest for no gain over `Info::$raw`. `probe()` still *reports* subtitle tracks
   (§9a) — reporting and packaging are different jobs.
12. **Status lines behind a `Reporter`**: printing suits a command line and nothing else, so the
   destination is an interface — `Reporter\Console` by default, `Reporter\Silent` for anywhere stdout
   belongs to something else, and two methods for anyone bridging to a PSR-3 logger. Handed to
   backends by the facades the same way the probe is, so one job cannot report from half of itself.
   The display level still decides *whether* a line is reported; this decides only where it goes.
13. **Names are validated, not trusted**: a representation, output, manifest or sprite name becomes a
   filename in a directory the caller named and, for renditions, an entry in `-var_stream_map`. Both
   uses break on separators, commas and spaces, so they are rejected at construction with
   `Exception\Input` rather than joined onto a path and hoped for. The same reasoning reaches the
   settings that are not filenames but still end up in the argument list, each with the rule its own
   use needs rather than the strictest one going: `Hls::url()` is a URL or path prefix, so it may
   carry separators but no whitespace (which would end the URI it is written in front of);
   `Hls::flags()` are single words of muxer vocabulary; `Output\Dash::sets()` is free-form, because
   the option's syntax owns its shape — spaces separate its groups and `descriptor=` carries XML — so
   only what would break the argument list is refused. Nothing here is shell quoting: `Process` passes
   argv arrays. What is being prevented is a value that reads as *another option*, which is why none
   of the three may begin with a dash.
14. **Keyframes follow segments**: a packaging job without an explicit `keyframe()` takes the segment
   length as its cadence, and an interval longer than a segment is rejected. Cuts land on keyframes,
   so the two were never independent — leaving them so meant a ladder built with the defaults could
   be segmented wherever the encoder happened to place a keyframe.
15. **How a command ended is reported, not guessed** (2026-08-23): a process that has to be stopped is stopped in
   stages — `SIGTERM`, `GRACE` seconds to explain itself, then `SIGKILL` — and the reason travels with
   the failure. `Exception\Runtime` carries the exit code as its own code, using the shell's
   128 + signal convention for anything that was signalled (143 for `SIGTERM`, 137 for `SIGKILL`), so a
   timeout is distinguishable from a command that merely failed. Two things follow from the same
   reasoning. Every pipe is drained during the grace period, not just stderr: a command blocked on a
   full stdout pipe cannot act on the signal it was sent, so draining only the pipe we wanted to read
   would hang exactly the case the grace period exists for. And collected output is bounded (`OUTPUT`,
   8 MiB) rather than accumulated indefinitely — a backend stuck printing would otherwise exhaust
   memory instead of failing, and a command that has produced megabytes of output where a JSON payload
   or a filename was expected has already gone wrong.
16. **A result describes the run that produced it** (2026-08-23): `tile()` clears the numbered sheets its own naming
   would claim before writing any, so a job that produces fewer sheets than the last one cannot report
   the previous run's leftovers among its `images()`. Only names this job could have written are
   removed, matched exactly rather than by prefix, so an unrelated file sharing the directory survives.
17. **Swoole is a test dependency, never a library one** (2026-08-23): following the house pattern from
   `utopia-php/database` and `utopia-php/queue` — `ext-swoole` declared as a `suggest`,
   `swoole/ide-helper` in `require-dev`, and the extension itself built from pinned source in the test
   image. The library imports nothing from Swoole and must not: coroutine-safety here is a property of
   construction (immutable config, one facade per coroutine, and a `Process` built on `proc_open`,
   `stream_select` and `usleep`, all of which yield under the hooks). But a property nobody exercises
   is a claim, not a guarantee, and this one matters more than most because the consumer that drives
   these requirements runs Swoole workers. The extension was previously absent, so the one test that
   proves it skipped itself on every run. Installing it costs a build stage that compiles in parallel
   with ffmpeg, and buys the suite its first clean run with no skips at all.

## 11. Future: live streaming

Live is deliberately out of v2 — but nothing in the design blocks adding it. The seams reserved:

- **Inputs are just strings.** ffmpeg accepts `rtmp://`, `srt://`, or device inputs in the same
  argv slot as a file path, so `open('rtmp://…')` needs no signature change — only `valid()`
  behaves differently (a live source has no file size or fixed duration to check).
- **Live is a new verb, not a mode.** `pack()` stays the synchronous VOD terminal returning a
  complete `Package`. Live packaging is a long-running process with a rolling segment window, so it
  arrives as a new terminal on the same chain — e.g. `start(): Live`, a handle exposing `stop()`
  and rolling per-segment events — leaving every existing v2 signature untouched.
- **Output configs are open.** `Hls`/`Dash`/`Cmaf` are fluent option bags; live knobs are additive,
  non-breaking methods (`live()`, `window(n)`, `latency(s)` mapping to `-window_size`, `-ldash`,
  LL-HLS partial segments).
- **The plumbing already fits.** The internal `Process` wrapper is built for indefinitely-running
  commands (`timeout: 0`) with streaming line callbacks; the `Observable::PROGRESS`/`LOG` event
  surface generalizes to per-segment events; `Parser\M3u8` handles rolling playlists (they are
  ordinary playlists whose entries drop off).
- **Backends line up.** The ffmpeg hls/dash muxers support live natively, and so does Shaka — whose
  live mode pairs naturally with the DRM story that would bring it in (§10.8).

The one rule that keeps this live-safe: **no adapter may assume an input is a seekable local file**,
except the inherently-VOD verbs (`probe`, `tile`, `grab`).
