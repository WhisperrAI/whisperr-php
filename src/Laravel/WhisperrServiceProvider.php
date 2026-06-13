<?php

declare(strict_types=1);

namespace Whisperr\Laravel;

use Illuminate\Support\ServiceProvider;
use Whisperr\Whisperr;

class WhisperrServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/whisperr.php', 'whisperr');

        $this->app->singleton(Whisperr::class, function ($app) {
            $cfg = $app['config']['whisperr'];
            return new Whisperr([
                'api_key' => $cfg['api_key'] ?? '',
                'base_url' => $cfg['base_url'] ?? 'https://api.whisperr.net',
                'disabled' => $cfg['disabled'] ?? false,
                'debug' => $cfg['debug'] ?? false,
            ]);
        });
        $this->app->alias(Whisperr::class, 'whisperr');
    }

    public function boot(): void
    {
        $this->publishes(
            [__DIR__ . '/../../config/whisperr.php' => $this->app->configPath('whisperr.php')],
            'whisperr-config',
        );

        // Flush after the response has been sent so tracking adds no latency to
        // the user's request.
        $this->app->terminating(function () {
            if ($this->app->resolved(Whisperr::class)) {
                $this->app->make(Whisperr::class)->flush();
            }
        });
    }
}
