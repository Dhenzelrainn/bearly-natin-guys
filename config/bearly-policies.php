<?php

return [
    'version' => '2026-09-26-draft',
    // Draft notices remain labelled until the team completes its policy review.
    'draft' => true,
    'operator' => env('BEARLY_POLICY_OPERATOR', 'Bearly project team'),
    'contact_email' => env('BEARLY_PRIVACY_EMAIL'),
];
