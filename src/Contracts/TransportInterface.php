<?php

declare(strict_types=1);

namespace Whisperr\Contracts;

interface TransportInterface
{
    /** @param array<int,array<string,mixed>> $events @return string ok|retry|auth|drop */
    public function sendBatch(array $events): string;

    /** @param array<string,mixed> $op @return string ok|retry|auth|drop */
    public function sendIdentify(array $op): string;
}
