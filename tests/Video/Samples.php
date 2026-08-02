<?php

declare(strict_types=1);

namespace Utopia\Tests;

use Utopia\Video\Process;

/**
 * The sample library the end-to-end tests run against.
 *
 * Sources live in tests/samples/in and results are written to tests/samples/out,
 * so both are easy to inspect by hand after a run. The media itself is generated
 * rather than committed — the repository stays small, and each file's exact
 * shape is described here rather than in a binary nobody can read.
 *
 * Run `composer samples` to populate the directory without running the tests.
 */
final class Samples
{
    /** Seconds of content in each generated sample. */
    private const DURATION = 6;

    /** Keyframe cadence, so any of these can be cut into segments. */
    private const KEYFRAME = 'expr:gte(t,n_forced*2)';

    /**
     * Containers a consumer might realistically be handed, each with the codecs
     * that container is normally used with.
     *
     * @var array<string, list<string>>
     */
    private const CONTAINERS = [
        // The everyday case.
        'mp4' => ['-c:v', 'libx264', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p', '-c:a', 'aac'],
        // Editors and camera rigs.
        'mov' => ['-c:v', 'libx264', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p', '-c:a', 'aac'],
        // The container that actually supports many tracks well.
        'mkv' => ['-c:v', 'libx264', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p', '-c:a', 'aac'],
        // Older exports, and the reason "just read the extension" fails.
        'avi' => ['-c:v', 'mpeg4', '-vtag', 'DX50', '-c:a', 'libmp3lame'],
        'wmv' => ['-c:v', 'wmv2', '-c:a', 'wmav2'],
        'flv' => ['-c:v', 'flv1', '-c:a', 'libmp3lame', '-ar', '44100'],
        // Broadcast and HLS-adjacent.
        'ts' => ['-c:v', 'libx264', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p', '-c:a', 'aac'],
        // Royalty-free web.
        'webm' => ['-c:v', 'libvpx-vp9', '-b:v', '300k', '-cpu-used', '8', '-c:a', 'libopus'],
        'ogv' => ['-c:v', 'libtheora', '-q:v', '4', '-c:a', 'libvorbis'],
        // Phones.
        '3gp' => ['-c:v', 'libx264', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-ar', '8000'],
    ];

    /**
     * Audio tracks for the multi-language samples: language tag, title, and the
     * tone that makes each one audibly distinct.
     *
     * @var list<array{code: string, title: string, hz: int}>
     */
    private const LANGUAGES = [
        ['code' => 'eng', 'title' => 'English', 'hz' => 440],
        ['code' => 'spa', 'title' => 'Espanol', 'hz' => 554],
        ['code' => 'fra', 'title' => 'Francais', 'hz' => 659],
        ['code' => 'jpn', 'title' => 'Japanese', 'hz' => 880],
    ];

    public static function root(): string
    {
        return \dirname(__DIR__, 2).'/tests/samples';
    }

    public static function in(): string
    {
        return self::root().'/in';
    }

    public static function out(): string
    {
        return self::root().'/out';
    }

    /**
     * Whether the tools needed to build and use the samples are installed.
     */
    public static function available(): bool
    {
        return Process::exists('ffmpeg') && Process::exists('ffprobe');
    }

    /**
     * Every sample source, keyed by filename.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        $found = [];

        foreach (\glob(self::in().'/*') ?: [] as $path) {
            if (\is_file($path)) {
                $found[\basename($path)] = $path;
            }
        }

        \ksort($found);

        return $found;
    }

    /**
     * Samples whose name starts with the given prefix.
     *
     * @return array<string, string>
     */
    public static function matching(string $prefix): array
    {
        return \array_filter(
            self::all(),
            static fn (string $name): bool => \str_starts_with($name, $prefix),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * Builds anything missing. Safe to call repeatedly — existing files are left
     * alone, so a full run costs nothing after the first.
     */
    public static function build(bool $force = false): void
    {
        $in = self::in();

        if (! \is_dir($in)) {
            \mkdir($in, 0o755, true);
        }

        if (! \is_dir(self::out())) {
            \mkdir(self::out(), 0o755, true);
        }

        if ($force) {
            foreach (\glob($in.'/*') ?: [] as $path) {
                \unlink($path);
            }
        }

        $master = self::master();

        self::containers($master);
        self::multitrack($master);
        self::shapes($master);
        self::sound($master);
    }

    /**
     * The source everything else is derived from: moving detail that compresses
     * like real footage, plus a tone.
     */
    private static function master(): string
    {
        $path = self::in().'/video.mp4';

        if (\is_file($path)) {
            return $path;
        }

        self::run([
            '-f', 'lavfi', '-i', 'testsrc2=duration='.self::DURATION.':size=640x360:rate=30',
            '-f', 'lavfi', '-i', 'sine=frequency=440:duration='.self::DURATION,
            '-c:v', 'libx264', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p',
            '-force_key_frames', self::KEYFRAME,
            '-c:a', 'aac', '-shortest',
            '-metadata', 'title=Utopia Video Sample',
            '-metadata', 'artist=Utopia',
        ], $path);

        return $path;
    }

    /**
     * Encoders this ffmpeg was actually built with.
     *
     * Builds differ — a distribution package and a hand-rolled one rarely carry
     * the same set — so the sample library adapts instead of failing on whichever
     * codec happens to be missing.
     *
     * @return list<string>
     */
    private static function encoders(): array
    {
        static $found = null;

        if ($found !== null) {
            return $found;
        }

        $found = [];

        foreach (\explode("\n", Process::read(['ffmpeg', '-hide_banner', '-encoders'], 30)) as $line) {
            if (\preg_match('/^\s*[A-Z.]{6}\s+(\S+)/', $line, $match) === 1) {
                $found[] = $match[1];
            }
        }

        return $found;
    }

    /**
     * @param  list<string>  $codecs
     */
    private static function buildable(array $codecs): bool
    {
        $available = self::encoders();

        foreach ($codecs as $index => $value) {
            // Only inspect the values that follow a -c:v or -c:a flag.
            $flag = $codecs[$index - 1] ?? '';

            if (($flag === '-c:v' || $flag === '-c:a') && ! \in_array($value, $available, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The same content in every container worth supporting, so nothing depends
     * on a file extension.
     */
    private static function containers(string $master): void
    {
        foreach (self::CONTAINERS as $extension => $codecs) {
            $path = self::in().'/video.'.$extension;

            if ($extension === 'mp4' || \is_file($path)) {
                continue;
            }

            if (! self::buildable($codecs)) {
                // This ffmpeg cannot write that combination; the rest still build.
                continue;
            }

            $args = ['-i', $master, ...$codecs];

            // Not every codec honours forced keyframes, so only ask where it helps.
            if (\in_array('libx264', $codecs, true) || \in_array('libvpx-vp9', $codecs, true)) {
                $args[] = '-force_key_frames';
                $args[] = self::KEYFRAME;
            }

            if ($extension === '3gp') {
                // 3GP expects a small frame and a low sample rate.
                $args[] = '-vf';
                $args[] = 'scale=352:288';
            }

            self::run($args, $path);
        }
    }

    /**
     * Several audio tracks, each tagged with a language, in the two containers
     * where that is common: MP4 and Matroska. Matroska also carries subtitles in
     * more than one language.
     */
    private static function multitrack(string $master): void
    {
        foreach (['mp4' => 3, 'mkv' => 4] as $extension => $count) {
            $path = self::in().'/multi-audio.'.$extension;

            if (\is_file($path)) {
                continue;
            }

            $args = ['-i', $master];
            $languages = \array_slice(self::LANGUAGES, 0, $count);

            foreach ($languages as $language) {
                $args[] = '-f';
                $args[] = 'lavfi';
                $args[] = '-i';
                $args[] = 'sine=frequency='.$language['hz'].':duration='.self::DURATION;
            }

            $args[] = '-map';
            $args[] = '0:v:0';

            foreach (\array_keys($languages) as $index) {
                $args[] = '-map';
                $args[] = ($index + 1).':a:0';
            }

            foreach ($languages as $index => $language) {
                $args[] = '-metadata:s:a:'.$index;
                $args[] = 'language='.$language['code'];
                $args[] = '-metadata:s:a:'.$index;
                $args[] = 'title='.$language['title'];
            }

            $args[] = '-c:v';
            $args[] = 'copy';
            $args[] = '-c:a';
            $args[] = 'aac';
            $args[] = '-shortest';

            self::run($args, $path);
        }

        // The realistic case: a release with several dubs and several subtitle
        // languages in the one file.
        $both = self::in().'/multi-track.mkv';

        if (! \is_file($both) && \is_file(self::in().'/multi-audio.mkv')) {
            $tracks = ['eng' => "1\n00:00:01,000 --> 00:00:03,000\nHello\n", 'fra' => "1\n00:00:01,000 --> 00:00:03,000\nBonjour\n"];
            $args = ['-i', self::in().'/multi-audio.mkv'];
            $written = [];

            foreach ($tracks as $code => $body) {
                $srt = self::in().'/.both-'.$code.'.srt';
                \file_put_contents($srt, $body);
                $written[] = $srt;
                $args[] = '-i';
                $args[] = $srt;
            }

            $args[] = '-map';
            $args[] = '0';

            foreach (\array_keys($tracks) as $index => $code) {
                $args[] = '-map';
                $args[] = ($index + 1).':0';
            }

            foreach (\array_keys($tracks) as $index => $code) {
                $args[] = '-metadata:s:s:'.$index;
                $args[] = 'language='.$code;
            }

            $args[] = '-c';
            $args[] = 'copy';
            $args[] = '-c:s';
            $args[] = 'srt';

            self::run($args, $both);

            foreach ($written as $srt) {
                @\unlink($srt);
            }
        }

        // Subtitles in three languages, which is what Matroska is usually used for.
        $subtitled = self::in().'/multi-subtitle.mkv';

        if (! \is_file($subtitled)) {
            $tracks = [
                'eng' => "1\n00:00:01,000 --> 00:00:03,000\nHello\n",
                'fra' => "1\n00:00:01,000 --> 00:00:03,000\nBonjour\n",
                'jpn' => "1\n00:00:01,000 --> 00:00:03,000\nこんにちは\n",
            ];

            $args = ['-i', $master];
            $written = [];

            foreach ($tracks as $code => $body) {
                $srt = self::in().'/.'.$code.'.srt';
                \file_put_contents($srt, $body);
                $written[] = $srt;
                $args[] = '-i';
                $args[] = $srt;
            }

            $args[] = '-map';
            $args[] = '0';

            foreach (\array_keys($tracks) as $index => $code) {
                $args[] = '-map';
                $args[] = ($index + 1).':0';
            }

            foreach (\array_keys($tracks) as $index => $code) {
                $args[] = '-metadata:s:s:'.$index;
                $args[] = 'language='.$code;
            }

            $args[] = '-c:v';
            $args[] = 'copy';
            $args[] = '-c:a';
            $args[] = 'copy';
            $args[] = '-c:s';
            $args[] = 'srt';

            self::run($args, $subtitled);

            foreach ($written as $srt) {
                @\unlink($srt);
            }
        }
    }

    /**
     * The properties that make real files awkward.
     */
    private static function shapes(string $master): void
    {
        $in = self::in();

        // Non-square pixels: stored 4:3, displayed wider.
        self::once($in.'/anamorphic.mp4', [
            '-i', $master, '-vf', 'scale=640:480,setsar=4:3',
            '-c:v', 'libx264', '-preset', 'ultrafast', '-force_key_frames', self::KEYFRAME, '-c:a', 'copy',
        ]);

        // Frames arriving at an uneven rate.
        self::once($in.'/variable-fps.mp4', [
            '-i', $master, '-vf', "select='not(mod(n,3))+gte(t,3)'", '-fps_mode', 'vfr',
            '-c:v', 'libx264', '-preset', 'ultrafast', '-an',
        ]);

        // Orientation in the display matrix rather than the frame size.
        self::once($in.'/rotated.mp4', [
            '-display_rotation', '90', '-i', $master, '-c', 'copy',
        ]);

        // Shot on a phone, held upright.
        self::once($in.'/portrait.mp4', [
            '-i', $master, '-vf', 'scale=360:640,setsar=1:1',
            '-c:v', 'libx264', '-preset', 'ultrafast', '-force_key_frames', self::KEYFRAME, '-c:a', 'copy',
        ]);

        // Newer codec, same content.
        self::once($in.'/hevc.mp4', [
            '-i', $master, '-c:v', 'libx265', '-preset', 'ultrafast', '-tag:v', 'hvc1',
            '-force_key_frames', self::KEYFRAME, '-c:a', 'copy',
        ]);

        // No sound at all.
        self::once($in.'/silent.mp4', ['-i', $master, '-an', '-c:v', 'copy']);

        // Shorter than a single segment.
        self::once($in.'/tiny.mp4', ['-i', $master, '-t', '0.5', '-c', 'copy']);

        // Chapters, which players show as a timeline.
        $meta = $in.'/.chapters.txt';
        \file_put_contents($meta, <<<'META'
            ;FFMETADATA1
            title=Chaptered Sample
            [CHAPTER]
            TIMEBASE=1/1000
            START=0
            END=3000
            title=Opening
            [CHAPTER]
            TIMEBASE=1/1000
            START=3000
            END=6000
            title=Closing
            META);
        self::once($in.'/chapters.mp4', ['-i', $master, '-i', $meta, '-map_metadata', '1', '-map', '0', '-c', 'copy']);
        @\unlink($meta);
    }

    /**
     * Audio-only sources, with and without artwork.
     */
    private static function sound(string $master): void
    {
        $in = self::in();

        self::once($in.'/audio.m4a', ['-i', $master, '-vn', '-c:a', 'aac']);
        self::once($in.'/audio.mp3', ['-i', $master, '-vn', '-c:a', 'libmp3lame']);

        $cover = $in.'/.cover.jpg';

        if (! \is_file($in.'/artwork.m4a')) {
            self::run(['-i', $master, '-frames:v', '1', '-vf', 'scale=300:300'], $cover);
            self::run([
                '-i', $in.'/audio.m4a', '-i', $cover,
                '-map', '0:a', '-map', '1:v', '-c', 'copy',
                '-disposition:v:0', 'attached_pic',
            ], $in.'/artwork.m4a');
            @\unlink($cover);
        }
    }

    /**
     * @param  list<string>  $args
     */
    private static function once(string $path, array $args): void
    {
        if (! \is_file($path)) {
            self::run($args, $path);
        }
    }

    /**
     * @param  list<string>  $args
     */
    private static function run(array $args, string $target): void
    {
        Process::run(
            [...['ffmpeg', '-y', '-hide_banner', '-loglevel', 'error'], ...$args, $target],
            timeout: 300,
        );
    }
}
