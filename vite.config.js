import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/bearly-auth.css',
                'resources/js/bearly-auth.js',
                'resources/css/admin.css',
                'resources/js/admin.js',
                'resources/css/courier.css',
                'resources/js/courier.js',
                'resources/css/seller.css',
                'resources/js/seller.js',
                'resources/css/buyer.css',
                'resources/css/bearly-promo-slider.css',
                'resources/css/bearly-chat.css',
                'resources/css/addresses.css',
                'resources/css/cart.css',
                'resources/css/checkout.css',
                'resources/css/profile.css',
                'resources/css/wishlist.css',
                'resources/js/buyer.js',
                'resources/js/bearly-promo-slider.js',
                'resources/js/bearly-chat.js',
                'resources/js/addresses.js',
                'resources/js/cart.js',
                'resources/js/checkout.js',
                'resources/js/profile.js',
                'resources/js/account-addresses.js',
                'resources/js/my-likes.js',
                'resources/js/my-purchases.js',
                'resources/js/reviews-ratings.js',
            ],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
