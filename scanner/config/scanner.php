<?php

return [
    'api_key' => env('SCANNER_API_KEY', ''),
    'max_bytes' => (int) env('SCANNER_MAX_BYTES', 2097152),
    'connect_timeout' => (int) env('SCANNER_CONNECT_TIMEOUT', 5),
    'total_timeout' => (int) env('SCANNER_TOTAL_TIMEOUT', 12),
    'user_agent' => env('SCANNER_USER_AGENT', 'WebsiteHealthReport/1.0 (+https://health.oldev.site/privacy/)'),
];
