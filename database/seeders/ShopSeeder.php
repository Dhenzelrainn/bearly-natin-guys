<?php

namespace Database\Seeders;

use App\Models\Shop;
use Illuminate\Database\Seeder;

class ShopSeeder extends Seeder
{
    public function run(): void
    {
        $shops = [
            [
                'name' => 'TechHub Official',
                'logo' => '🔧',
                'rating' => 4.9,
                'followers' => 12400,
                'products_count' => 245,
                'verified' => true,
                'location' => 'Kuala Lumpur',
                'description' => 'Official store for tech products',
            ],
            [
                'name' => 'SneakerVault',
                'logo' => '👟',
                'rating' => 4.8,
                'followers' => 8900,
                'products_count' => 87,
                'verified' => true,
                'location' => 'Penang',
                'description' => 'Premium sneaker collection',
            ],
            [
                'name' => 'HomeNest Co.',
                'logo' => '🏠',
                'rating' => 4.7,
                'followers' => 5600,
                'products_count' => 132,
                'verified' => true,
                'location' => 'Johor Bahru',
                'description' => 'Home and living essentials',
            ],
            [
                'name' => 'BeautyBox MY',
                'logo' => '💄',
                'rating' => 4.6,
                'followers' => 22000,
                'products_count' => 310,
                'verified' => true,
                'location' => 'Kuala Lumpur',
                'description' => 'Beauty and cosmetics paradise',
            ],
            [
                'name' => 'SportZone',
                'logo' => '⚽',
                'rating' => 4.5,
                'followers' => 6800,
                'products_count' => 156,
                'verified' => true,
                'location' => 'Shah Alam',
                'description' => 'Sports equipment and gear',
            ],
        ];

        foreach ($shops as $shop) {
            Shop::create($shop);
        }
    }
}
