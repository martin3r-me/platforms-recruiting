<?php

namespace Platform\Recruiting\Services;

class SyncResult
{
    /** @var string[] */
    public array $changed = [];

    /** @var string[] */
    public array $unchanged = [];

    /** @var string[] */
    public array $skipped = [];

    public function anythingChanged(): bool
    {
        return !empty($this->changed);
    }

    public function toLines(): array
    {
        $lines = [];
        foreach ($this->changed as $c)   $lines[] = '✓ ' . $c;
        foreach ($this->unchanged as $c) $lines[] = '= ' . $c;
        foreach ($this->skipped as $c)   $lines[] = '· ' . $c;
        return $lines;
    }
}
