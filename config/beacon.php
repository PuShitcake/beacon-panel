<?php

return [
    'self_service_enabled' => env('BEACON_SELF_SERVICE_ENABLED', false),
    'minecraft_versions' => array_values(array_filter(array_map(
        'trim',
        explode(',', env('BEACON_MINECRAFT_VERSIONS', '1.21.8,1.21.4,1.20.4,1.20.1'))
    ))),
    'rate_limit' => [
        'api' => (int) env('BEACON_API_RATELIMIT', 60),
        'api_period' => (int) env('BEACON_API_RATELIMIT_PERIOD', 1),
        'content' => (int) env('BEACON_CONTENT_RATELIMIT', 20),
        'content_period' => (int) env('BEACON_CONTENT_RATELIMIT_PERIOD', 1),
    ],
    'modrinth' => [
        'base_url' => 'https://api.modrinth.com/v2',
        'allowed_download_hosts' => ['cdn.modrinth.com'],
        'user_agent' => env('BEACON_MODRINTH_USER_AGENT', 'PuShitcake/BeaconPanel'),
        'timeout' => (int) env('BEACON_MODRINTH_TIMEOUT', 10),
        'cache_ttl' => (int) env('BEACON_MODRINTH_CACHE_TTL', 300),
    ],
    'content' => [
        'max_file_bytes' => (int) env('BEACON_CONTENT_MAX_FILE_BYTES', 268435456),
        'max_total_bytes' => (int) env('BEACON_CONTENT_MAX_TOTAL_BYTES', 536870912),
        'max_dependencies' => (int) env('BEACON_CONTENT_MAX_DEPENDENCIES', 50),
        'max_dependency_depth' => (int) env('BEACON_CONTENT_MAX_DEPENDENCY_DEPTH', 8),
        'require_stopped_server' => env('BEACON_CONTENT_REQUIRE_STOPPED_SERVER', true),
        'backup_before_mutation' => env('BEACON_CONTENT_BACKUP_BEFORE_MUTATION', true),
    ],
    'minecraft_configuration' => [
        'require_stopped_server' => env('BEACON_CONFIG_REQUIRE_STOPPED_SERVER', true),
        'max_file_bytes' => 262144,
    ],
];
