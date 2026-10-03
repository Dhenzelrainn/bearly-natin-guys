<?php

return [
    'enabled' => env('EMAIL_VERIFICATION_ENABLED', false),
    'ttl' => 600,
    'proof_ttl' => 1800,
    'cooldown' => 60,
    'max_attempts' => 5,
];
