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
