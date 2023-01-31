<?php

namespace Utopia\Streaming;

abstract class Output
{
    private string $strict = '-2';

    /**
     * @param  string  $strict
     * @return output
     */
    public function setStrict(string $strict): output
    {
        $this->strict = $strict;

        return $this;
    }

    /**
     * @return string
     */
    public function getStrict(): string
    {
        return $this->strict;
    }
}
