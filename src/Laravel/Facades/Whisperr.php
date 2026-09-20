<?php

declare(strict_types=1);

namespace Whisperr\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use Whisperr\Whisperr as WhisperrClient;

/**
 * @method static array identifyNow(string $externalUserId, array $params = [])
 * @method static void identify(string $externalUserId, array $params = [])
 * @method static void track(string $externalUserId, string $eventType, array $properties = [], array $context = [])
 * @method static \Whisperr\PublishResult publish(string $externalUserId, string $eventType, array $properties, string $messageId, string $occurredAt, array $context = [])
 * @method static array messageHistory(string $externalUserId, int $limit = 50, ?string $cursor = null)
 * @method static array message(string $externalUserId, string $messageId)
 * @method static void flush()
 *
 * @see \Whisperr\Whisperr
 */
class Whisperr extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return WhisperrClient::class;
    }
}
