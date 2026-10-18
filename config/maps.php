<?php

return [
    'tile_url' => env(
        'MAPS_TILE_URL',
        'https://api.maptiler.com/maps/streets-v4/{z}/{x}/{y}.png?key='.env('MAPTILER_API_KEY', '')
    ),
    'tile_attribution' => env('MAPS_TILE_ATTRIBUTION', '&copy; MapTiler &copy; OpenStreetMap contributors'),
    'geocoding_url' => env('MAPS_GEOCODING_URL', 'https://nominatim.openstreetmap.org/reverse'),
    'geocoder_email' => env('MAPS_GEOCODER_EMAIL'),
];
