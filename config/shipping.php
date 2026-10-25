<?php

return [
    'provider_key' => 'bearly_logistics',
    'provider_label' => 'Bearly Logistics',
    'default_base_fee_minor' => 5000,
    'default_zone_surcharge_per_level_minor' => 500,
    // Retained as a compatibility fallback for Admin configurations saved
    // before zone pricing was introduced. New edits write base_fee_minor.
    'default_seller_order_fee_minor' => 5000,
    'default_volumetric_divisor' => 5000,
];
