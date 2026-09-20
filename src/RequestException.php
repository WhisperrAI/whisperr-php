<?php

declare(strict_types=1);

namespace Whisperr;

/** Safe upstream failure information without credentials or response contents. */
final class RequestException extends \RuntimeException
{
    public function __construct(private string $errorCode, private ?int $status)
    {
        parent::__construct('Whisperr request failed: ' . $errorCode);
    }

    public function status(): ?int { return $this->status; }
    public function errorCode(): string { return $this->errorCode; }
}
