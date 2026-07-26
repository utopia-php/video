<?php

declare(strict_types=1);

namespace Utopia\Streaming\Arguments;

use Utopia\Streaming\Arguments;
use Utopia\Streaming\Exception\InvalidArgumentException;
use Utopia\Streaming\Format as StreamingFormat;
use Utopia\Streaming\Output;
use Utopia\Streaming\Output\Dash as DashOutput;
use Utopia\Streaming\Representation;
use Utopia\Streaming\Representations;
use Utopia\Streaming\Utils;

class Dash extends Arguments
{
    private DashOutput $dash;

    public function __construct(
        string $path,
        StreamingFormat $format,
        Output $output,
        Representations $reps
    ) {
        if (! $output instanceof DashOutput) {
            throw new InvalidArgumentException('Arguments\\Dash requires Output\\Dash');
        }

        parent::__construct($path, $format, $output, $reps);
        $this->dash = $output;
    }

    public function build(): array
    {
        if ($this->reps->count() === 0) {
            throw new InvalidArgumentException('At least one representation is required');
        }

        return [
            ...$this->formatOptions(),
            ...$this->initOptions(),
            ...$this->streamOptions(),
            '-strict',
            $this->dash->getStrict(),
        ];
    }

    public function getOutputPath(): string
    {
        return $this->basePath().'.mpd';
    }

    /**
     * @return list<string>
     */
    private function initOptions(): array
    {
        $init = [
            'use_timeline' => $this->dash->getUseTimeline(),
            'use_template' => $this->dash->getUseTemplate(),
            'seg_duration' => $this->dash->getSegmentDuration(),
            'hls_playlist' => (int) $this->dash->isGenerateHlsPlaylist(),
            'f' => 'dash',
        ];

        if ($this->dash->getInitSegmentName()) {
            $init['init_seg_name'] = $this->filename.'_init_$RepresentationID$.$ext$';
        }

        if ($this->dash->getMediaSegmentName()) {
            $init['media_seg_name'] = $this->filename.'_chunk_$RepresentationID$_$Number%05d$.$ext$';
        }

        $args = Utils::arrayToFFmpegOpt($init);

        if ($this->dash->getAdaption() !== '') {
            $args[] = '-adaptation_sets';
            $args[] = $this->dash->getAdaption();
        }

        return [...$args, ...Utils::arrayToFFmpegOpt($this->dash->getAdditionalParams())];
    }

    /**
     * @return list<string>
     */
    private function streamOptions(): array
    {
        $chunks = [];

        foreach ($this->reps as $key => $rep) {
            /** @var Representation $rep */
            $opts = ['map' => '0'];

            if ($this->dash->hasVideo()) {
                $opts['s:v:'.$key] = $rep->getResolution();
                $opts['b:v:'.$key] = $rep->getVideoKiloBitrate().'k';
            }

            $chunks[] = Utils::arrayToFFmpegOpt($opts);

            $audioBitrate = $rep->getAudioKiloBitrate();
            if ($audioBitrate > 0) {
                $chunks[] = ['-b:a:'.$key, $audioBitrate.'k'];
            }
        }

        return $chunks === [] ? [] : array_merge(...$chunks);
    }
}
