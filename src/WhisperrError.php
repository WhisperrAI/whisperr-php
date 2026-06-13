<?php

declare(strict_types=1);

namespace Whisperr;

/** Passed to the on_error callback for delivery/drop observability. */
final class WhisperrError
{
    public function __construct(
        public readonly string $type, // "auth" | "dropped" | "retry_exhausted"
        public readonly string $message,
        public readonly ?int $status = null,
    ) {
    }
}
