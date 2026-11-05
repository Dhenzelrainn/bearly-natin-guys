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

    // Verified Courier line-haul compensation is a fixed daily rate in integer
    // centavos, posted at most once per local calendar work date. It is not a
    // per-parcel or per-run rate and does not create a stored wallet balance.
    'courier_daily_rate_minor' => 65000,
    'courier_daily_earning_type' => 'courier_daily',
];
