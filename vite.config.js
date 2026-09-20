import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                // Auth
                'resources/css/bearly-auth.css',
                'resources/js/bearly-auth.js',

                // Admin
                'resources/css/admin.css',
                'resources/js/admin.js',

                // Buyer
                'resources/css/buyer.css',
                'resources/js/buyer.js',
                // Buyer promo slider
                'resources/css/bearly-promo-slider.css',
                'resources/js/bearly-promo-slider.js',

                // Seller
                'resources/css/seller.css',
                'resources/css/seller-polish.css',
                'resources/css/seller-legibility.css',
                'resources/js/seller.js',
                'resources/js/seller-polish.js',

                // Logistics
                'resources/css/logistics.css',
                'resources/js/logistics.js',

                // Rider
                'resources/css/rider.css',
                'resources/js/rider.js',

                // Landing
                'resources/css/landing.css',
                'resources/js/landing.js',

                // About
                'resources/css/about.css',
                'resources/js/landing-about.js',

                // Contact
                'resources/css/contact.css',
                'resources/js/contact.js',
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