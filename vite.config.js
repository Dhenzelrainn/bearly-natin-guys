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
                'resources/js/application-pending.js',
                'resources/css/announcement.css',
                'resources/js/announcement.js',

                // Admin
                'resources/css/admin.css',
                'resources/js/admin.js',

                // Buyer
                'resources/css/buyer.css',
                'resources/css/product-detail.css',
                'resources/js/buyer.js',
                'resources/js/product-detail.js',
                'resources/js/buyer-notifications.js',
                // Buyer promo slider
                'resources/css/bearly-promo-slider.css',
                'resources/js/bearly-promo-slider.js',
                // Buyer account and preview pages
                'resources/css/profile.css',
                'resources/css/addresses.css',
                'resources/css/cart.css',
                 'resources/css/checkout.css',
                 'resources/css/buyer-dispute.css',
                 'resources/css/buyer-return.css',
                 'resources/css/wishlist.css',
                'resources/css/bearly-chat.css',
                'resources/js/profile.js',
                'resources/js/account-addresses.js',
                'resources/js/addresses.js',
                'resources/js/my-likes.js',
                'resources/js/my-purchases.js',
                'resources/js/reviews-ratings.js',
                'resources/js/cart.js',
                'resources/js/checkout.js',
                'resources/js/bearly-chat.js',

                // Shared role notification center page
                'resources/css/notification-center.css',
                'resources/js/notification-center-page.js',

                // Seller
                'resources/css/maps.css',
                'resources/css/seller.css',
                'resources/css/messages-standard.css',
                'resources/js/messages-standard.js',
                'resources/js/realtime.js',
                'resources/js/waybill-scanner.js',
                'resources/js/destination-map.js',
                'resources/js/seller-store-map.js',
                'resources/css/seller-polish.css',
                'resources/css/seller-legibility.css',
                'resources/js/seller.js',
                'resources/js/seller-polish.js',

                // Shared logistics/rider waybill scanning
                'resources/css/waybill-scanner.css',

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
