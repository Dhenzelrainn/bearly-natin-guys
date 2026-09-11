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

                'resources/css/seller.css',
                'resources/js/seller.js',
                'resources/css/seller-polish.css',
                'resources/css/seller-legibility.css',
                'resources/js/seller-polish.js',

                'resources/css/logistics.css',
                'resources/js/logistics.js',

                'resources/css/rider.css',
                'resources/js/rider.js',

                'resources/css/buyer.css',
                'resources/js/buyer.js',
                'resources/js/buyer-bootstrap.js',

                'resources/css/landing.css',
                'resources/js/landing.js',

                'resources/css/about.css',
                'resources/css/contact.css',
                'resources/js/contact.js'

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
