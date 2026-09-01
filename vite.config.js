import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/homepage.css',
                'resources/js/homepage.js',
            ],
            refresh: true,
        }),
    ],
});