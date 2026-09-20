import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/bearly-auth.css',
                'resources/js/bearly-auth.js',

                'resources/css/seller.css',
                'resources/css/seller-polish.css',
                'resources/css/seller-legibility.css',
                'resources/js/seller.js',
                'resources/js/seller-polish.js',
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