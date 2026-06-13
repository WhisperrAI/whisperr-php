<?php

declare(strict_types=1);

namespace Whisperr\Tests;

use Whisperr\Transport;

/** Captures the serialized wire body instead of making an HTTP call. */
final class CapturingTransport extends Transport
{
    /** @var array<string,mixed> */
    public array $captured = [];
    public string $returnValue = 'ok';

    protected function post(string $path, array $body): string
    {
        $this->captured = ['path' => $path, 'body' => $body];
        return $this->returnValue;
    }
}
