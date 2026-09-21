<?php

declare(strict_types=1);

namespace Whisperr;

/** A durable ingestion receipt, not proof that a business outcome was processed. */
final class PublishResult
{
    public function __construct(
        private bool $acknowledged,
        private bool $retryable,
        private ?int $status = null,
        private ?string $deliveryId = null,
        private ?string $disposition = null,
        private bool $duplicate = false,
        private ?string $errorCode = null,
    ) {
    }

    public function acknowledged(): bool { return $this->acknowledged; }
    public function retryable(): bool { return $this->retryable; }
    public function status(): ?int { return $this->status; }
    public function deliveryId(): ?string { return $this->deliveryId; }
    public function disposition(): ?string { return $this->disposition; }
    public function duplicate(): bool { return $this->duplicate; }
    public function errorCode(): ?string { return $this->errorCode; }
}
