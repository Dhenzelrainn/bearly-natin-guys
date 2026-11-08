<?php

return [
    'enabled' => env('EMAIL_VERIFICATION_ENABLED', false),
    'ttl' => 300,
    'proof_ttl' => 1800,
    'cooldown' => 60,
    'max_attempts' => 5,
    'send_limit_per_email' => (int) env('EMAIL_VERIFICATION_SEND_LIMIT_PER_EMAIL', 5),
    'send_limit_per_session' => (int) env('EMAIL_VERIFICATION_SEND_LIMIT_PER_SESSION', 10),
    'send_limit_per_ip' => (int) env('EMAIL_VERIFICATION_SEND_LIMIT_PER_IP', 60),
    'send_limit_window' => (int) env('EMAIL_VERIFICATION_SEND_LIMIT_WINDOW', 3600),
];
