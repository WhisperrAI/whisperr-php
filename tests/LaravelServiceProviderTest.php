<?php

declare(strict_types=1);

namespace Whisperr\Tests;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use Whisperr\Laravel\WhisperrServiceProvider;
use Whisperr\Whisperr;

final class LaravelServiceProviderTest extends TestCase
{
    public function testProviderWorksWithInstalledIlluminateVersionAndResolvesSingleton(): void
    {
        // Match the public Application methods used by ServiceProvider, backed
        // by the real Illuminate container and config classes on each CI version.
        $app = new class extends Container {
            public array $terminationCallbacks = [];
            public function configurationIsCached(): bool { return true; }
            public function configPath($path = ''): string { return '/tmp/config/' . $path; }
            public function terminating($callback): void { $this->terminationCallbacks[] = $callback; }
        };
        $app->instance('config', new Repository(['whisperr' => ['api_key' => 'test', 'disabled' => true]]));
        $provider = new WhisperrServiceProvider($app);
        $provider->register();
        $provider->boot();
        $client = $app->make(Whisperr::class);
        $this->assertInstanceOf(Whisperr::class, $client);
        $this->assertSame($client, $app->make('whisperr'));
        $this->assertCount(1, $app->terminationCallbacks);
        ($app->terminationCallbacks[0])();
        $this->assertFalse($client->publish('42', 'order_completed', [], 'mid', '2026-09-21T00:00:00Z')->acknowledged());
    }
}
