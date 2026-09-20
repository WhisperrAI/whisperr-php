<?php

declare(strict_types=1);

namespace Whisperr\Contracts;

interface IdentityTransportInterface
{
    /** @param array<string,mixed> $op @return array<string,mixed> */
    public function identifyNow(array $op): array;
}
