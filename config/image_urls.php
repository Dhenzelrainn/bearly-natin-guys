<?php

$localHttpHosts = array_values(array_unique(array_filter(array_map(
    static fn (string $host): string => strtolower(trim($host)),
    explode(',', (string) env('LOCAL_IMAGE_HTTP_HOSTS', 'localhost,127.0.0.1,::1')),
))));

return [
    /*
    |--------------------------------------------------------------------------
    | Explicit local HTTP image hosts
    |--------------------------------------------------------------------------
    |
    | Set LOCAL_IMAGE_HTTP_HOSTS to a comma-separated list of exact hostnames
    | or private IP addresses for local development. This setting is consumed
    | through Laravel config so it works with cached configuration.
    |
    */
    'local_http_hosts' => $localHttpHosts,
];
