<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class HomeController extends Controller
{
    public function index(): View
    {
        $categories = [
            [
                'name' => 'Electronics',
                'icon' => '💻',
                'count' => '12.4k',
            ],
            [
                'name' => 'Fashion',
                'icon' => '👟',
                'count' => '8.9k',
            ],
            [
                'name' => 'Home & Living',
                'icon' => '🛋️',
                'count' => '5.6k',
            ],
            [
                'name' => 'Beauty',
                'icon' => '✨',
                'count' => '9.9k',
            ],
            [
                'name' => 'Sports',
                'icon' => '⚽',
                'count' => '4.3k',
            ],
            [
                'name' => 'Books',
                'icon' => '📚',
                'count' => '6.5k',
            ],
            [
                'name' => 'Automotive',
                'icon' => '🚗',
                'count' => '3.2k',
            ],
            [
                'name' => 'Groceries',
                'icon' => '🛒',
                'count' => '7.9k',
            ],
        ];

        $recommendedProducts = [
            [
                'id' => 1,
                'name' => 'Sony WH-1000XM5 Wireless Noise-Canceling Headphones',
                'price' => 899,
                'old_price' => 1299,
                'discount' => 31,
                'rating' => 4.9,
                'sold' => '3,240',
                'location' => 'Kuala Lumpur',
                'badge' => 'Best Seller',
                'shipping' => true,
                'shop' => 'TechHub Official',
            ],
            [
                'id' => 2,
                'name' => 'Nike Air Max 270 Men\'s Running Shoes',
                'price' => 389,
                'old_price' => 520,
                'discount' => 25,
                'rating' => 4.8,
                'sold' => '8,750',
                'location' => 'Penang',
                'badge' => 'Flash Deal',
                'shipping' => true,
                'shop' => 'SneakerVault',
            ],
            [
                'id' => 3,
                'name' => 'Scandinavian Oak Coffee Table with Storage',
                'price' => 649,
                'old_price' => 890,
                'discount' => 27,
                'rating' => 4.7,
                'sold' => '1,820',
                'location' => 'Johor Bahru',
                'badge' => 'Voucher',
                'shipping' => false,
                'shop' => 'HomeNest Co.',
            ],
            [
                'id' => 4,
                'name' => 'Logitech MX Master 3S Wireless Mouse',
                'price' => 219,
                'old_price' => 299,
                'discount' => 27,
                'rating' => 4.9,
                'sold' => '5,600',
                'location' => 'Kuala Lumpur',
                'badge' => 'Best Seller',
                'shipping' => true,
                'shop' => 'TechHub Official',
            ],
            [
                'id' => 5,
                'name' => 'COSRX Advanced Snail 96 Mucin Power Essence',
                'price' => 55,
                'old_price' => 79,
                'discount' => 30,
                'rating' => 4.8,
                'sold' => '18,900',
                'location' => 'Shah Alam',
                'badge' => null,
                'shipping' => true,
                'shop' => 'BeautyBox MY',
            ],
            [
                'id' => 6,
                'name' => 'MacBook Pro 14" M3 Pro',
                'price' => 8499,
                'old_price' => 9499,
                'discount' => 11,
                'rating' => 4.9,
                'sold' => '2,100',
                'location' => 'Kuala Lumpur',
                'badge' => 'Official Store',
                'shipping' => true,
                'shop' => 'TechHub Official',
            ],
        ];

        $bestSellers = [
            [
                'id' => 7,
                'name' => 'Adidas Ultraboost 22 Running Shoes',
                'price' => 349,
                'old_price' => 499,
                'discount' => 30,
                'rating' => 4.7,
                'sold' => '4,320',
                'location' => 'Penang',
                'badge' => 'New',
                'shipping' => true,
                'shop' => 'SneakerVault',
            ],
            [
                'id' => 8,
                'name' => 'Luxury Velvet Throw Pillow Set (4 pcs)',
                'price' => 89,
                'old_price' => 129,
                'discount' => 31,
                'rating' => 4.5,
                'sold' => '6,700',
                'location' => 'Johor Bahru',
                'badge' => null,
                'shipping' => true,
                'shop' => 'HomeNest Co.',
            ],
            [
                'id' => 9,
                'name' => 'Minimalist Linen Sofa 3-Seater — Warm Beige',
                'price' => 2499,
                'old_price' => 3200,
                'discount' => 22,
                'rating' => 4.6,
                'sold' => '930',
                'location' => 'Johor Bahru',
                'badge' => null,
                'shipping' => false,
                'shop' => 'HomeNest Co.',
            ],
            [
                'id' => 10,
                'name' => 'Samsung Galaxy S24 Ultra',
                'price' => 4899,
                'old_price' => 5499,
                'discount' => 11,
                'rating' => 4.8,
                'sold' => '3,400',
                'location' => 'Kuala Lumpur',
                'badge' => 'Best Seller',
                'shipping' => true,
                'shop' => 'TechHub Official',
            ],
            [
                'id' => 11,
                'name' => 'SK-II Facial Treatment Essence 160ml',
                'price' => 189,
                'old_price' => 269,
                'discount' => 30,
                'rating' => 4.9,
                'sold' => '5,234',
                'location' => 'Kuala Lumpur',
                'badge' => 'Best Seller',
                'shipping' => true,
                'shop' => 'BeautyBox MY',
            ],
        ];

        $featuredShops = [
            [
                'name' => 'TechHub Official',
                'logo' => '🔧',
                'image' => 'https://images.unsplash.com/photo-1496181133206-80ce9b88a853?auto=format&fit=crop&w=160&q=80',
                'rating' => 4.9,
                'followers' => 12400,
                'products' => 245,
                'verified' => true,
            ],
            [
                'name' => 'SneakerVault',
                'logo' => '👟',
                'image' => 'https://images.unsplash.com/photo-1542291026-7eec264c27ff?auto=format&fit=crop&w=160&q=80',
                'rating' => 4.8,
                'followers' => 8900,
                'products' => 87,
                'verified' => true,
            ],
            [
                'name' => 'HomeNest Co.',
                'logo' => '🏠',
                'image' => 'https://images.unsplash.com/photo-1618221195710-dd6b41faaea6?auto=format&fit=crop&w=160&q=80',
                'rating' => 4.7,
                'followers' => 5600,
                'products' => 132,
                'verified' => true,
            ],
            [
                'name' => 'BeautyBox MY',
                'logo' => '💄',
                'image' => 'https://images.unsplash.com/photo-1596462502278-27bfdc403348?auto=format&fit=crop&w=160&q=80',
                'rating' => 4.6,
                'followers' => 22000,
                'products' => 310,
                'verified' => true,
            ],
        ];

        return view('buyer.home', compact(
            'categories',
            'recommendedProducts',
            'bestSellers',
            'featuredShops'
        ));
    }
}