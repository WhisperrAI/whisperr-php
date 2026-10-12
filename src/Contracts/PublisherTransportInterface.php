<?php

declare(strict_types=1);

namespace Whisperr\Contracts;

use Whisperr\PublishResult;

/** Optional extension: existing custom buffered transports remain compatible. */
interface PublisherTransportInterface
{
    /** @param array<string,mixed> $event */
    public function publishEvent(array $event): PublishResult;
}
