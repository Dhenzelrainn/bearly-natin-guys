<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Seller Wallet refund cutoff
    |--------------------------------------------------------------------------
    |
    | Completed Seller-responsible refunds at or after this UTC timestamp are
    | deducted from the Seller Wallet automatically. Refunds completed before
    | the cutoff remain legacy records and must be closed explicitly.
    |
    */
    'refund_cutoff_at' => '2026-10-21 00:00:00',
];
