<?php

declare(strict_types=1);

namespace Whisperr\Tests;

use Whisperr\Contracts\TransportInterface;

/** Records ops at the client boundary (before wire serialization). */
final class FakeTransport implements TransportInterface
{
    /** @var array<int,array<int,array<string,mixed>>> */
    public array $batches = [];
    /** @var array<int,array<string,mixed>> */
    public array $identifies = [];

    public function __construct(private string $result = 'ok')
    {
    }

    public function sendBatch(array $events): string
    {
        $this->batches[] = $events;
        return $this->result;
    }

    public function sendIdentify(array $op): string
    {
        $this->identifies[] = $op;
        return $this->result;
    }
}
