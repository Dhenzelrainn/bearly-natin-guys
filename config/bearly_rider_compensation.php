<?php

return [
    // Local Rider pickup compensation is funded by Logistics and paid per
    // successfully collected outbound parcel. Values are integer centavos.
    'pickup_rate_regular_minor' => 1500,
    'pickup_rate_bulky_minor' => 3000,

    // The exact authoritative bulky classification. Unknown or null size
    // classes are never inferred from parcel count or dimensions.
    'pickup_bulky_size_class' => 'bulky',

    // Persisted parcel size classes that map to the regular pickup rate.
    'pickup_regular_size_classes' => ['small', 'regular'],

    'pickup_earning_type' => 'pickup_parcel',

    // Verified Courier compensation uses QR-recorded attendance. Rates and
    // caps are stored in integer centavos; one ledger row is posted per
    // qualifying Asia/Manila work date and no wallet balance is created.
    'courier_hourly_rate_minor' => 8125,
    'courier_daily_cap_minor' => 65000,
    'courier_daily_max_paid_seconds' => 28800,
    'courier_daily_earning_type' => 'courier_daily',

    // A provider QR expires quickly. Rotating creates a new random secret;
    // only its SHA-256 hash is retained in the database.
    'rider_attendance_qr_ttl_seconds' => 45,
];
