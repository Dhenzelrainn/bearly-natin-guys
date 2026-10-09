<?php

return [
    'provider_key' => 'bearly_logistics',
    'provider_label' => 'Bearly Logistics',
    'default_volumetric_divisor' => 5000,
    'route_tiers' => [
        'nearby_delivery' => [
            'label' => 'Nearby delivery',
            'description' => 'Same city or municipality.',
        ],
        'same_province' => [
            'label' => 'Same province',
            'description' => 'Different city or municipality within the same province.',
        ],
        'nearby_provinces' => [
            'label' => 'Nearby provinces',
            'description' => 'Different provinces within the same major island group.',
        ],
        'inter_island' => [
            'label' => 'Inter-island',
            'description' => 'Origin and destination are in different major island groups.',
        ],
    ],
    'island_groups' => [
        'luzon' => [
            'Abra', 'Apayao', 'Aurora', 'Bataan', 'Batangas', 'Benguet', 'Bulacan',
            'Batanes', 'Cagayan', 'Camarines Norte', 'Camarines Sur', 'Catanduanes', 'Cavite',
            'Ifugao', 'Ilocos Norte', 'Ilocos Sur', 'Isabela', 'Kalinga', 'La Union',
            'Laguna', 'Marinduque', 'Mountain Province', 'Nueva Ecija', 'Nueva Vizcaya', 'Occidental Mindoro',
            'Oriental Mindoro', 'Palawan', 'Pampanga', 'Pangasinan', 'Quezon', 'Quirino',
            'Rizal', 'Romblon', 'Sorsogon', 'Tarlac', 'Zambales', 'Albay', 'Camarines Norte',
            'Camarines Sur', 'Masbate', 'Metro Manila', 'National Capital Region', 'NCR',
        ],
        'visayas' => [
            'Aklan', 'Antique', 'Biliran', 'Bohol', 'Capiz', 'Cebu', 'Eastern Samar',
            'Guimaras', 'Iloilo', 'Leyte', 'Negros Occidental', 'Negros Oriental',
            'Northern Samar', 'Samar', 'Southern Leyte', 'Western Samar',
        ],
        'mindanao' => [
            'Agusan del Norte', 'Agusan del Sur', 'Basilan', 'Bukidnon', 'Camiguin',
            'Cotabato', 'Davao de Oro', 'Davao del Norte', 'Davao del Sur', 'Davao Occidental',
            'Davao Oriental', 'Dinagat Islands', 'Lanao del Norte', 'Lanao del Sur',
            'Maguindanao del Norte', 'Maguindanao del Sur', 'Misamis Occidental',
            'Misamis Oriental', 'Sarangani', 'South Cotabato', 'Sultan Kudarat', 'Sulu',
            'Surigao del Norte', 'Surigao del Sur', 'Tawi-Tawi', 'Zamboanga del Norte',
            'Zamboanga del Sur', 'Zamboanga Sibugay', 'Zamboanga City',
        ],
    ],
];
