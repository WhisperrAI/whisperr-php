<?php

return [
    // App ingestion key (wrk_…). Set WHISPERR_API_KEY in your environment.
    'api_key' => env('WHISPERR_API_KEY', ''),

    // Ingestion base URL. Leave as default unless directed otherwise.
    'base_url' => env('WHISPERR_BASE_URL', 'https://api.whisperr.net'),

    // Your backend release (for example "2026.10.1"). Sent as context.app_version
    // on every event. Leave empty to send no app_version.
    'app_version' => env('WHISPERR_APP_VERSION'),

    // Set true to make the client a no-op (e.g. in local/testing).
    'disabled' => env('WHISPERR_DISABLED', false),

    // Verbose logging via error_log.
    'debug' => env('WHISPERR_DEBUG', false),
];
