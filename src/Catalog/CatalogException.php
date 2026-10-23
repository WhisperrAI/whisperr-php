<?php

declare(strict_types=1);

namespace Whisperr\Catalog;

/**
 * Whisperr refused a catalog call, or could not be reached.
 *
 * getStatus() is the HTTP status (0 for a network error or timeout),
 * getErrorCode() the server's error code (server_key_required,
 * catalog_push_disabled, snapshot_not_open, ...) or network_error.
 * isRetryable() is true when retries ran out on a transient failure. For
 * upsert and delete, getPartial() holds what earlier chunks already did.
 */
class CatalogException extends \RuntimeException
{
    /** @param array<string,mixed>|null $partial */
    public function __construct(
        string $message,
        private int $status,
        private string $errorCode,
        private bool $retryable,
        private ?array $partial = null,
    ) {
        parent::__construct($message, $status);
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function isRetryable(): bool
    {
        return $this->retryable;
    }

    /** @return array<string,mixed>|null */
    public function getPartial(): ?array
    {
        return $this->partial;
    }

    /** @param array<string,mixed> $partial */
    public function withPartial(array $partial): self
    {
        return new self($this->getMessage(), $this->status, $this->errorCode, $this->retryable, $partial);
    }
}
