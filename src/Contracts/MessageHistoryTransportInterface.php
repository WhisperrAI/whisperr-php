<?php

declare(strict_types=1);

namespace Whisperr\Contracts;

interface MessageHistoryTransportInterface
{
    /** @return array<string,mixed> */
    public function messageHistory(string $externalUserId, int $limit = 50, ?string $cursor = null): array;

    /** @return array<string,mixed> */
    public function message(string $externalUserId, string $messageId): array;
}
