<?php

declare(strict_types=1);

namespace Utopia\Video\Parser;

use DOMDocument;
use DOMElement;
use Utopia\Video\Exception\Runtime;
use Utopia\Video\Segment;
use Utopia\Video\Track;
use Utopia\Video\Variant;

/**
 * Reads a DASH manifest back into structured data.
 *
 * A manifest can address its segments in two ways: by listing them, or by
 * describing a formula that generates their names. Callers should not have to
 * care which, so templates are expanded here and both modes come back looking
 * the same.
 *
 * @internal
 */
final class Mpd
{
    /**
     * @return array{variants: list<Variant>, metadata: array<string, mixed>}
     */
    public static function read(string $path, string $dir): array
    {
        $dir = \rtrim($dir, '/');
        $document = self::load($path);
        $root = $document->documentElement;

        if ($root === null) {
            throw new Runtime('Manifest "'.$path.'" has no root element');
        }

        $duration = self::seconds($root->getAttribute('mediaPresentationDuration'));

        $metadata = [
            'profiles' => $root->getAttribute('profiles'),
            'type' => $root->getAttribute('type'),
            'mediaPresentationDuration' => $root->getAttribute('mediaPresentationDuration'),
            'maxSegmentDuration' => $root->getAttribute('maxSegmentDuration'),
            'minBufferTime' => $root->getAttribute('minBufferTime'),
            'duration' => $duration,
        ];

        $variants = [];

        foreach ($root->getElementsByTagName('AdaptationSet') as $index => $set) {
            if (! $set instanceof DOMElement) {
                continue;
            }

            foreach ($set->getElementsByTagName('Representation') as $representation) {
                if (! $representation instanceof DOMElement) {
                    continue;
                }

                $variants[] = self::variant($set, $representation, (string) $index, $dir, $duration);
            }
        }

        return ['variants' => $variants, 'metadata' => $metadata];
    }

    private static function variant(
        DOMElement $set,
        DOMElement $representation,
        string $group,
        string $dir,
        float $duration,
    ): Variant {
        $id = $representation->getAttribute('id');
        $id = $id !== '' ? $id : $group;

        $mime = $representation->getAttribute('mimeType');
        $mime = $mime !== '' ? $mime : $set->getAttribute('mimeType');

        $type = \str_starts_with($mime, 'audio') ? Track::AUDIO : Track::VIDEO;
        $contentType = $set->getAttribute('contentType');

        if ($contentType !== '') {
            $type = $contentType === 'audio' ? Track::AUDIO : Track::VIDEO;
        }

        [$segments, $timescale, $start] = self::segments($set, $representation, $id, $dir, $duration);

        $language = $set->getAttribute('lang');

        return new Variant(
            id: $id,
            type: $type,
            mimeType: $mime !== '' ? $mime : null,
            codecs: self::text($representation->getAttribute('codecs')),
            bandwidth: (int) $representation->getAttribute('bandwidth'),
            width: self::number($representation, $set, 'width'),
            height: self::number($representation, $set, 'height'),
            sar: self::text($representation->getAttribute('sar')),
            sampleRate: self::number($representation, $set, 'audioSamplingRate'),
            language: $language !== '' && $language !== 'und' ? $language : null,
            timescale: $timescale,
            startNumber: $start,
            target: (float) $set->getAttribute('maxSegmentDuration'),
            segments: $segments,
        );
    }

    /**
     * @return array{0: list<Segment>, 1: int, 2: int}
     */
    private static function segments(
        DOMElement $set,
        DOMElement $representation,
        string $id,
        string $dir,
        float $duration,
    ): array {
        $list = self::child($representation, 'SegmentList') ?? self::child($set, 'SegmentList');

        if ($list !== null) {
            return self::listed($list, $id, $dir);
        }

        $template = self::child($representation, 'SegmentTemplate') ?? self::child($set, 'SegmentTemplate');

        if ($template !== null) {
            return self::templated($template, $id, $dir, $duration);
        }

        return [[], 0, 0];
    }

    /**
     * Every segment written out one by one.
     *
     * @return array{0: list<Segment>, 1: int, 2: int}
     */
    private static function listed(DOMElement $list, string $id, string $dir): array
    {
        $timescale = (int) $list->getAttribute('timescale');
        $start = (int) $list->getAttribute('startNumber');
        $segments = [];

        $duration = $timescale > 0
            ? ((float) $list->getAttribute('duration')) / $timescale
            : 0.0;

        $init = self::child($list, 'Initialization');

        if ($init !== null && $init->getAttribute('sourceURL') !== '') {
            $segments[] = self::segment($id, $dir, $init->getAttribute('sourceURL'), 0.0, true, 0);
        }

        $number = $start;

        foreach ($list->getElementsByTagName('SegmentURL') as $url) {
            if (! $url instanceof DOMElement) {
                continue;
            }

            $segments[] = self::segment($id, $dir, $url->getAttribute('media'), $duration, false, $number);
            $number++;
        }

        return [$segments, $timescale, $start];
    }

    /**
     * Segment names described by formula, resolved into real filenames.
     *
     * @return array{0: list<Segment>, 1: int, 2: int}
     */
    private static function templated(DOMElement $template, string $id, string $dir, float $duration): array
    {
        $timescale = (int) $template->getAttribute('timescale');
        $timescale = $timescale > 0 ? $timescale : 1;
        $start = $template->hasAttribute('startNumber')
            ? (int) $template->getAttribute('startNumber')
            : 1;

        $segments = [];
        $init = $template->getAttribute('initialization');

        if ($init !== '') {
            $segments[] = self::segment($id, $dir, self::expand($init, $id, 0), 0.0, true, 0);
        }

        $timeline = self::child($template, 'SegmentTimeline');
        $media = $template->getAttribute('media');

        if ($timeline !== null) {
            $number = $start;

            foreach ($timeline->getElementsByTagName('S') as $entry) {
                if (! $entry instanceof DOMElement) {
                    continue;
                }

                $length = ((float) $entry->getAttribute('d')) / $timescale;
                $repeat = $entry->hasAttribute('r') ? (int) $entry->getAttribute('r') : 0;

                for ($i = 0; $i <= $repeat; $i++) {
                    $segments[] = self::segment(
                        $id,
                        $dir,
                        self::expand($media, $id, $number),
                        $length,
                        false,
                        $number,
                    );
                    $number++;
                }
            }

            return [$segments, $timescale, $start];
        }

        $length = ((float) $template->getAttribute('duration')) / $timescale;

        if ($length <= 0 || $duration <= 0) {
            return [$segments, $timescale, $start];
        }

        $count = (int) \ceil($duration / $length);

        for ($i = 0; $i < $count; $i++) {
            $number = $start + $i;
            $segments[] = self::segment(
                $id,
                $dir,
                self::expand($media, $id, $number),
                $length,
                false,
                $number,
            );
        }

        return [$segments, $timescale, $start];
    }

    /**
     * Substitutes the identifiers DASH allows inside a segment name.
     */
    public static function expand(string $pattern, string $id, int $number): string
    {
        $name = \str_replace(['$RepresentationID$', '$Bandwidth$'], [$id, ''], $pattern);

        $name = \preg_replace_callback(
            '/\$Number(%0?\d*d)?\$/',
            static fn (array $match): string => isset($match[1]) && $match[1] !== ''
                ? \sprintf($match[1], $number)
                : (string) $number,
            $name,
        ) ?? $name;

        return \str_replace('$$', '$', $name);
    }

    private static function segment(
        string $variant,
        string $dir,
        string $file,
        float $duration,
        bool $init,
        int $number,
    ): Segment {
        $file = \basename($file);
        $path = $dir.'/'.$file;

        if (! \is_file($path)) {
            throw new Runtime('Segment "'.$file.'" is missing from the package');
        }

        return new Segment(
            variant: $variant,
            file: $file,
            path: $path,
            duration: $duration,
            init: $init,
            number: $number,
            size: (int) \filesize($path),
        );
    }

    /**
     * Reads an ISO 8601 duration such as PT1M4.5S.
     */
    public static function seconds(string $value): float
    {
        if ($value === '') {
            return 0.0;
        }

        if (\preg_match('/^PT(?:(\d+(?:\.\d+)?)H)?(?:(\d+(?:\.\d+)?)M)?(?:(\d+(?:\.\d+)?)S)?$/', $value, $match) !== 1) {
            return 0.0;
        }

        return ((float) ($match[1] ?? 0)) * 3600
            + ((float) ($match[2] ?? 0)) * 60
            + ((float) ($match[3] ?? 0));
    }

    private static function child(DOMElement $element, string $tag): ?DOMElement
    {
        foreach ($element->childNodes as $node) {
            if ($node instanceof DOMElement && $node->tagName === $tag) {
                return $node;
            }
        }

        // Initialization can sit one level down inside a SegmentList.
        $nodes = $element->getElementsByTagName($tag);
        $first = $nodes->item(0);

        return $first instanceof DOMElement ? $first : null;
    }

    private static function number(DOMElement $representation, DOMElement $set, string $name): ?int
    {
        $value = $representation->getAttribute($name);

        if ($value === '') {
            $value = $set->getAttribute($name);
        }

        if ($value === '') {
            $value = $set->getAttribute('max'.\ucfirst($name));
        }

        return $value === '' ? null : (int) $value;
    }

    private static function text(string $value): ?string
    {
        return $value === '' ? null : $value;
    }

    private static function load(string $path): DOMDocument
    {
        if (! \is_file($path)) {
            throw new Runtime('Unable to read manifest "'.$path.'"');
        }

        $document = new DOMDocument();
        $previous = \libxml_use_internal_errors(true);
        $loaded = $document->load($path);
        \libxml_clear_errors();
        \libxml_use_internal_errors($previous);

        if ($loaded === false) {
            throw new Runtime('Manifest "'.$path.'" is not valid XML');
        }

        return $document;
    }
}
