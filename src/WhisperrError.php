<?php

declare(strict_types=1);

namespace Whisperr;

/**
 * Passed to on_error. Read-only properties are available on PHP 8.0 as well.
 * @property-read string $type
 * @property-read string $message
 * @property-read int|null $status
 */
final class WhisperrError
{
    public function __construct(
        private string $type,
        private string $message,
        private ?int $status = null,
    ) {
    }

    /** @return string|int|null */
    public function __get(string $name)
    {
        if (!in_array($name, ['type', 'message', 'status'], true)) {
            throw new \LogicException('Unknown WhisperrError property: ' . $name);
        }
        return $this->$name;
    }

    public function __isset(string $name): bool
    {
        return in_array($name, ['type', 'message', 'status'], true) && $this->$name !== null;
    }

    /** @param mixed $value */
    public function __set(string $name, $value): void
    {
        throw new \LogicException('WhisperrError properties are read-only');
    }
}
