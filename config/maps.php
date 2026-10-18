<?php

return [
    'tile_url' => env(
        'MAPS_TILE_URL',
        'https://api.maptiler.com/maps/streets-v2/{z}/{x}/{y}.png?key='.env('MAPTILER_API_KEY', '')
    ),
    'tile_attribution' => env('MAPS_TILE_ATTRIBUTION', '&copy; MapTiler &copy; OpenStreetMap contributors'),
];
