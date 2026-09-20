<?php

declare(strict_types=1);

namespace Whisperr\Tests;

use Whisperr\Transport;

final class ScriptedTransport extends Transport
{
    public array $requests = [];
    public array $responses = [];

    public function __construct()
    {
        parent::__construct('https://example.invalid', 'server_key', 1.0, fn ($message) => null);
    }

    protected function request(string $method, string $path, ?array $body = null): array
    {
        $this->requests[] = ['method' => $method, 'path' => $path, 'body' => $body];
        return array_shift($this->responses) ?? ['status' => null, 'body' => ''];
    }

    public function respond(?int $status, array $body): void
    {
        $this->responses[] = ['status' => $status, 'body' => json_encode($body)];
    }
}
