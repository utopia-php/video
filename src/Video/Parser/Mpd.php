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

        // Segment length is declared once, on the root, as an ISO 8601 duration.
        // Every variant shares it, so it is read here rather than looked for on
        // an AdaptationSet that never carries it.
        $target = self::seconds($root->getAttribute('maxSegmentDuration'));

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
            foreach ($set->getElementsByTagName('Representation') as $representation) {
                $variants[] = self::variant(
                    $set,
                    $representation,
                    (string) $index,
                    $dir,
                    $duration,
                    $target,
                );
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
        float $target,
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

        // Segment names can be described by a formula that includes the
        // bandwidth, so it has to be known before they can be resolved.
        $bandwidth = (int) $representation->getAttribute('bandwidth');

        [$segments, $timescale, $start] = self::segments(
            $set,
            $representation,
            $id,
            $dir,
            $duration,
            $bandwidth,
        );

        $media = \array_filter(
            $segments,
            static fn (Segment $segment): bool => ! $segment->init,
        );

        if ($media === []) {
            throw new Runtime('Representation "'.$id.'" contains no media segments');
        }

        $language = $set->getAttribute('lang');

        return new Variant(
            id: $id,
            type: $type,
            mimeType: $mime !== '' ? $mime : null,
            codecs: self::text($representation->getAttribute('codecs')),
            bandwidth: $bandwidth,
            width: self::number($representation, $set, 'width'),
            height: self::number($representation, $set, 'height'),
            sar: self::text($representation->getAttribute('sar')),
            sampleRate: self::number($representation, $set, 'audioSamplingRate'),
            language: $language !== '' && $language !== 'und' ? $language : null,
            timescale: $timescale,
            startNumber: $start,
            target: $target > 0.0 ? $target : self::longest($segments),
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
        int $bandwidth,
    ): array {
        // A Representation describes its own segments when it says anything at
        // all, and only falls back to the AdaptationSet when it says nothing.
        // Both of its own elements are therefore checked before either of the
        // set's, or a rung carrying a template would be handed the set's list.
        foreach ([$representation, $set] as $element) {
            $list = self::child($element, 'SegmentList');

            if ($list !== null) {
                return self::listed($list, $id, $dir);
            }

            $template = self::child($element, 'SegmentTemplate');

            if ($template !== null) {
                return self::templated($template, $id, $dir, $duration, $bandwidth);
            }
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

        // Scoped to the list itself, which holds nothing but its own segments,
        // so reaching past a direct child cannot stray into another rung.
        $init = self::child($list, 'Initialization') ?? self::descendant($list, 'Initialization');

        if ($init !== null && $init->getAttribute('sourceURL') !== '') {
            $segments[] = self::segment($id, $dir, $init->getAttribute('sourceURL'), 0.0, true, 0);
        }

        $number = $start;

        foreach ($list->getElementsByTagName('SegmentURL') as $url) {
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
    private static function templated(
        DOMElement $template,
        string $id,
        string $dir,
        float $duration,
        int $bandwidth,
    ): array {
        $timescale = (int) $template->getAttribute('timescale');
        $timescale = $timescale > 0 ? $timescale : 1;
        $start = $template->hasAttribute('startNumber')
            ? (int) $template->getAttribute('startNumber')
            : 1;

        $segments = [];
        $init = $template->getAttribute('initialization');

        if ($init !== '') {
            $segments[] = self::segment($id, $dir, self::expand($init, $id, 0, $bandwidth), 0.0, true, 0);
        }

        $timeline = self::child($template, 'SegmentTimeline');
        $media = $template->getAttribute('media');

        if ($timeline !== null) {
            $number = $start;

            // Media time, in timescale units, of the segment about to be read.
            // A name built from $Time$ is the running total of the durations
            // declared ahead of it, so it has to be carried across entries.
            $time = 0;

            foreach ($timeline->getElementsByTagName('S') as $entry) {
                $units = (int) $entry->getAttribute('d');
                $length = $units / $timescale;
                $repeat = $entry->hasAttribute('r') ? (int) $entry->getAttribute('r') : 0;

                // An entry is free to say where it begins, which is how a
                // timeline records a gap or starts its clock somewhere other
                // than zero. Only when it says nothing does the total carry on.
                if ($entry->hasAttribute('t')) {
                    $time = (int) $entry->getAttribute('t');
                }

                for ($i = 0; $i <= $repeat; $i++) {
                    $segments[] = self::segment(
                        $id,
                        $dir,
                        self::expand($media, $id, $number, $bandwidth, $time),
                        $length,
                        false,
                        $number,
                    );
                    $number++;
                    $time += $units;
                }
            }

            return [$segments, $timescale, $start];
        }

        $units = (int) $template->getAttribute('duration');
        $length = $units / $timescale;

        if ($length <= 0 || $duration <= 0) {
            return [$segments, $timescale, $start];
        }

        $count = (int) \ceil($duration / $length);

        for ($i = 0; $i < $count; $i++) {
            $number = $start + $i;
            $segments[] = self::segment(
                $id,
                $dir,
                self::expand($media, $id, $number, $bandwidth, $i * $units),
                $length,
                false,
                $number,
            );
        }

        return [$segments, $timescale, $start];
    }

    /**
     * Substitutes the identifiers DASH allows inside a segment name.
     *
     * $Number$, $Bandwidth$ and $Time$ each take an optional printf width, which
     * is how a manifest asks for zero padded names, and $$ is how it asks for a
     * literal dollar sign. Every identifier a name is allowed to carry is
     * resolved here: one left behind would be looked for on disk verbatim, and
     * reported as a missing segment rather than as a name nothing understood.
     *
     * @param  int  $time  Media time of this segment, in timescale units.
     */
    public static function expand(
        string $pattern,
        string $id,
        int $number,
        int $bandwidth = 0,
        int $time = 0,
    ): string {
        $name = \str_replace('$RepresentationID$', $id, $pattern);

        foreach (['Number' => $number, 'Bandwidth' => $bandwidth, 'Time' => $time] as $identifier => $value) {
            $name = \preg_replace_callback(
                '/\$'.$identifier.'(%0?\d*d)?\$/',
                // The width group needs at least "%d" to match, so it is either
                // absent or usable — there is no empty case to guard against.
                static fn (array $match): string => isset($match[1])
                    ? \sprintf($match[1], $value)
                    : (string) $value,
                $name,
            ) ?? $name;
        }

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
     * The longest segment in a list, which is what a target duration means.
     *
     * Used only when the manifest declares no maximum of its own.
     *
     * @param  list<Segment>  $segments
     */
    private static function longest(array $segments): float
    {
        $longest = 0.0;

        foreach ($segments as $segment) {
            $longest = \max($longest, $segment->duration);
        }

        return $longest;
    }

    /**
     * Reads an ISO 8601 duration such as PT1M4.5S.
     *
     * The date half is accepted as well as the time half, because a manifest is
     * free to write PT2.0S as P0Y0M0DT0H0M2.0S and mean the same thing. Years
     * and months have no fixed length, so a duration using them is refused
     * rather than guessed at.
     */
    public static function seconds(string $value): float
    {
        if ($value === '') {
            return 0.0;
        }

        $pattern = '/^P(?:(\d+(?:\.\d+)?)Y)?(?:(\d+(?:\.\d+)?)M)?(?:(\d+(?:\.\d+)?)W)?(?:(\d+(?:\.\d+)?)D)?'
            .'(?:T(?:(\d+(?:\.\d+)?)H)?(?:(\d+(?:\.\d+)?)M)?(?:(\d+(?:\.\d+)?)S)?)?$/';

        if (\preg_match($pattern, $value, $match) !== 1) {
            return 0.0;
        }

        // A bare P or PT matches, every group absent, and totals zero on its own.
        $part = static fn (int $at): float => (float) ($match[$at] ?? 0);

        // Years and months are the two designators with no fixed length in
        // seconds, so a duration built from them is refused, not approximated.
        if ($part(1) > 0.0 || $part(2) > 0.0) {
            return 0.0;
        }

        return $part(3) * 604800
            + $part(4) * 86400
            + $part(5) * 3600
            + $part(6) * 60
            + $part(7);
    }

    /**
     * The first direct child with this tag.
     *
     * Direct children only, deliberately. A descendant search run from an
     * AdaptationSet walks into its Representations, so a rung that declared
     * nothing would be handed whatever a sibling rung declared.
     */
    private static function child(DOMElement $element, string $tag): ?DOMElement
    {
        foreach ($element->childNodes as $node) {
            if ($node instanceof DOMElement && $node->tagName === $tag) {
                return $node;
            }
        }

        return null;
    }

    /**
     * The first descendant with this tag, at any depth.
     *
     * Only safe on an element that cannot contain another rung's markup.
     */
    private static function descendant(DOMElement $element, string $tag): ?DOMElement
    {
        $first = $element->getElementsByTagName($tag)->item(0);

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
        // Read first, parse second. The libxml error flag below is process
        // global, and under a coroutine runtime the file read is where this
        // function can be suspended — so the read happens before the flag is
        // touched, and nothing between set and restore can yield.
        $body = \is_file($path) ? \file_get_contents($path) : false;

        if ($body === false) {
            throw new Runtime('Unable to read manifest "'.$path.'"');
        }

        $document = new DOMDocument();
        $previous = \libxml_use_internal_errors(true);
        $loaded = $body !== '' && $document->loadXML($body);
        \libxml_clear_errors();
        \libxml_use_internal_errors($previous);

        if ($loaded === false) {
            throw new Runtime('Manifest "'.$path.'" is not valid XML');
        }

        return $document;
    }
}
