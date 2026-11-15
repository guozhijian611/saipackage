<?php
return [
    // Run php webman saipackage:setup first, then enable and restart all workers.
    'enabled' => filter_var(env('SAIPACKAGE_MANAGEMENT_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    'settings_key' => env('SAIPACKAGE_SETTINGS_KEY', ''),
    'table_prefix' => '',
    'probes' => [],
    'settings_migrations' => [],
    // Return true only once plugin-owned jobs have drained. Required before managed uninstall.
    'drain' => null,
    'health_interval' => 60,
];
