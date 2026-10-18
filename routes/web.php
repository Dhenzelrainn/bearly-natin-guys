<?php

use App\Http\Controllers\AccountApprovalController;
use App\Http\Controllers\AdminAnnouncementController;
use App\Http\Controllers\AdminAuditLogController;
use App\Http\Controllers\AdminCommerceController;
use App\Http\Controllers\AdminComplianceController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AdminDashboardController;
use App\Http\Controllers\AdminDisputeController;
use App\Http\Controllers\AdminFinanceController;
use App\Http\Controllers\AdminFulfillmentController;
use App\Http\Controllers\AdminMessageController;
use App\Http\Controllers\AdminPolicyController;
use App\Http\Controllers\AdminRegistrationController;
use App\Http\Controllers\AdminReturnRefundController;
use App\Http\Controllers\AdminRoleApplicationController;
use App\Http\Controllers\AdminSellerPayoutController;
use App\Http\Controllers\AdminSupportTicketController;
use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\ApplicationDocumentController;
use App\Http\Controllers\Auth\BearlyAuthController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\BuyerCheckoutController;
use App\Http\Controllers\BuyerController;
use App\Http\Controllers\BuyerDisputeController;
use App\Http\Controllers\BuyerMessageController;
use App\Http\Controllers\BuyerReturnController;
use App\Http\Controllers\BuyerReviewController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\DisputeParticipantController;
use App\Http\Controllers\LogisticsCashController;
use App\Http\Controllers\LogisticsController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PostalCodeController;
use App\Http\Controllers\ProductComplianceController;
use App\Http\Controllers\PsgcController;
use App\Http\Controllers\MapLocationController;
use App\Http\Controllers\RiderCashController;
use App\Http\Controllers\RiderController;
use App\Http\Controllers\SellerController;
use App\Http\Controllers\SellerMessageController;
use App\Http\Controllers\SellerProductController;
use App\Http\Controllers\SellerWalletController;
use App\Http\Controllers\SellerWaybillController;
use App\Http\Controllers\SellerWorkflowController;
use App\Http\Controllers\ShippingRateController;
use App\Http\Controllers\WaybillScanController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Landing
|--------------------------------------------------------------------------
*/

Route::view('/', 'landing-page.landingpage')->name('shop.home');

Route::redirect('/landing', '/')->name('landing');

Route::view('/about', 'landing-page.about.about')
    ->name('about');

Route::view('/contact', 'landing-page.contact.contact')
    ->name('contact');

Route::post('/contact', [ContactController::class, 'store'])
    ->middleware('throttle:10,1')
    ->name('contact.store');

Route::middleware('auth')->get(
    '/disputes/evidence/{evidence}/download',
    [DisputeParticipantController::class, 'downloadEvidence']
)->whereNumber('evidence')
    ->name('disputes.evidence.download');

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
*/

Route::view('/terms', 'legal.terms')->name('terms');
Route::view('/privacy', 'legal.privacy')->name('privacy');

Route::middleware('guest')->group(function () {
    Route::post('/register/email/send', [EmailVerificationController::class, 'send'])
        ->middleware('throttle:10,1')->block(30, 5)->name('register.email.send');
    Route::post('/register/email/check', [EmailVerificationController::class, 'check'])
        ->middleware('throttle:15,1')->block(30, 5)->name('register.email.check');
    Route::get('/login', [BearlyAuthController::class, 'showLogin'])
        ->name('login');

    Route::post('/login', [BearlyAuthController::class, 'login'])
        ->name('login.submit');

    Route::get('/register', [BearlyAuthController::class, 'showRegister'])
        ->name('register');

    Route::post('/register', [BearlyAuthController::class, 'register'])
        ->middleware('throttle:10,1')->block(30, 5)->name('register.submit');

    Route::get('/auth/google', [BearlyAuthController::class, 'redirectToGoogle'])
        ->name('google.redirect');

    Route::get('/auth/google/callback', [BearlyAuthController::class, 'handleGoogleCallback'])
        ->name('google.callback');
});

Route::post('/logout', [BearlyAuthController::class, 'logout'])
    ->middleware('auth')
    ->name('logout');

Route::view('/application/pending', 'auth.pending')
    ->name('application.pending');

Route::middleware('guest')->group(function () {
    Route::get('/forgot-password', [BearlyAuthController::class, 'showForgotPassword'])
        ->name('password.request');

    Route::post('/forgot-password', [BearlyAuthController::class, 'sendPasswordResetLink'])
        ->middleware('throttle:5,1')
        ->name('password.email');

    Route::get('/reset-password/{token}', [BearlyAuthController::class, 'showResetPassword'])
        ->name('password.reset');

    Route::post('/reset-password', [BearlyAuthController::class, 'resetPassword'])
        ->middleware('throttle:10,1')
        ->name('password.update');
});

/*
|--------------------------------------------------------------------------
| PSGC Address API
|--------------------------------------------------------------------------
*/

Route::get('/api/postal-codes', PostalCodeController::class)
    ->middleware('throttle:60,1')->name('postal.lookup');

Route::prefix('api/psgc')
    ->middleware('throttle:60,1')
    ->group(function () {
        Route::get('/provinces', [PsgcController::class, 'provinces'])
            ->name('psgc.provinces');

        Route::get('/provinces/{provinceCode}/cities', [PsgcController::class, 'cities'])
            ->name('psgc.cities');

        Route::get('/cities/{cityCode}/barangays', [PsgcController::class, 'barangays'])
            ->name('psgc.barangays');
    });

Route::get('/api/maps/reverse-geocode', [MapLocationController::class, 'reverseGeocode'])
    ->middleware(['auth', 'throttle:12,1'])
    ->name('maps.reverse-geocode');

/*
|--------------------------------------------------------------------------
| Notifications
|--------------------------------------------------------------------------
|
| Shared, user-scoped notification history and preferences. Every route
| reads and writes only the authenticated user's own records.
|
*/

Route::middleware('auth')->prefix('notifications')->name('notifications.')->group(function () {
    Route::get('/', [NotificationController::class, 'index'])->name('index');
    Route::get('/center', [NotificationController::class, 'center'])
        ->middleware('role:buyer,seller,admin,logistics,rider')
        ->name('center');
    Route::get('/preferences', [NotificationController::class, 'preferences'])->name('preferences');
    Route::post('/preferences', [NotificationController::class, 'updatePreferences'])->name('preferences.update');
    Route::post('/read-all', [NotificationController::class, 'readAll'])->name('read-all');
    Route::post('/{notification}/read', [NotificationController::class, 'read'])->name('read');
});

Route::get('/announcements/{announcement}', [AnnouncementController::class, 'show'])
    ->middleware('auth')
    ->whereNumber('announcement')
    ->name('announcements.show');

Route::get('/announcements/{announcement}/media/{media}', [AnnouncementController::class, 'media'])
    ->middleware('auth')
    ->whereNumber('announcement')
    ->whereNumber('media')
    ->name('announcements.media');

/*
|--------------------------------------------------------------------------
| Buyer
|--------------------------------------------------------------------------
|
| Buyer marketplace/account pages require an authenticated active Buyer.
|
*/

Route::middleware(['auth', 'role:buyer'])->group(function () {
    Route::get('/home', [BuyerController::class, 'home'])
        ->name('home');

    Route::get('/products', [BuyerController::class, 'products'])
        ->name('products.index');

    Route::get('/products/{product}', [BuyerController::class, 'showProduct'])
        ->name('products.show');

    Route::get('/stores/{store:slug}', [BuyerController::class, 'showStore'])
        ->name('stores.show');

    Route::get('/profile', [BuyerController::class, 'profile'])
        ->name('buyer.profile');

    Route::patch('/profile', [BuyerController::class, 'updateProfile'])
        ->name('buyer.profile.update');

    Route::get('/addresses', [BuyerController::class, 'addresses'])
        ->name('buyer.addresses');

    Route::get('/addresses/data', [BuyerController::class, 'addressData'])
        ->name('buyer.addresses.data');

    Route::get('/profile/purchases/data', [BuyerController::class, 'purchaseData'])
        ->name('buyer.purchases.data');

    Route::get('/profile/seller-orders/{sellerOrder}/dispute', [BuyerDisputeController::class, 'create'])
        ->whereNumber('sellerOrder')
        ->name('buyer.disputes.create');

    Route::post('/profile/seller-orders/{sellerOrder}/dispute', [BuyerDisputeController::class, 'store'])
        ->whereNumber('sellerOrder')
        ->name('buyer.disputes.store');

    Route::get('/profile/disputes/{dispute}', [BuyerDisputeController::class, 'show'])
        ->whereNumber('dispute')
        ->name('buyer.disputes.show');

    Route::get('/profile/seller-orders/{sellerOrder}/return', [BuyerReturnController::class, 'create'])
        ->whereNumber('sellerOrder')
        ->name('buyer.returns.create');

    Route::post('/profile/seller-orders/{sellerOrder}/return', [BuyerReturnController::class, 'store'])
        ->whereNumber('sellerOrder')
        ->name('buyer.returns.store');

    Route::get('/profile/returns/{returnRequest}', [BuyerReturnController::class, 'show'])
        ->whereNumber('returnRequest')
        ->name('buyer.returns.show');

    Route::post('/profile/returns/{returnRequest}/evidence', [BuyerReturnController::class, 'addEvidence'])
        ->whereNumber('returnRequest')
        ->name('buyer.returns.evidence.store');

    Route::get('/profile/returns/evidence/{evidence}', [BuyerReturnController::class, 'downloadEvidence'])
        ->whereNumber('evidence')
        ->name('buyer.returns.evidence.download');

    Route::get('/profile/returns/refunds/{refund}/receipt', [BuyerReturnController::class, 'downloadRefundReceipt'])
        ->whereNumber('refund')
        ->name('buyer.returns.refund-receipt');

    Route::get('/profile/reviews/data', [BuyerReviewController::class, 'index'])
        ->name('buyer.reviews.data');

    Route::post('/profile/reviews', [BuyerReviewController::class, 'store'])
        ->name('buyer.reviews.store');

    Route::get('/products/{product}/reviews/data', [BuyerController::class, 'productReviewsData'])
        ->name('products.reviews.data');

    Route::post('/addresses', [BuyerController::class, 'storeAddress'])
        ->name('buyer.addresses.store');

    Route::patch('/addresses/{address}', [BuyerController::class, 'updateAddress'])
        ->name('buyer.addresses.update');

    Route::patch('/addresses/{address}/default', [BuyerController::class, 'setDefaultAddress'])
        ->name('buyer.addresses.default');

    Route::delete('/addresses/{address}', [BuyerController::class, 'deleteAddress'])
        ->name('buyer.addresses.delete');

    Route::get('/checkout', [BuyerCheckoutController::class, 'index'])
        ->name('checkout');

    Route::post('/checkout/quote', [BuyerCheckoutController::class, 'quote'])
        ->name('checkout.quote');

    Route::post('/checkout', [BuyerCheckoutController::class, 'place'])
        ->name('checkout.place');

    Route::get('/checkout/confirmation/{orderNo}', [BuyerCheckoutController::class, 'confirmation'])
        ->where('orderNo', '[A-Za-z0-9-]+')
        ->name('checkout.confirmation');

    Route::post('/checkout/orders/{order}/cancel', [BuyerCheckoutController::class, 'cancel'])
        ->whereNumber('order')
        ->name('checkout.cancel');

    Route::post('/checkout/orders/{order}/confirm-receipt', [BuyerCheckoutController::class, 'confirmReceipt'])
        ->whereNumber('order')
        ->name('checkout.confirm-receipt');

    Route::get('/chat', [BuyerController::class, 'chat'])
        ->name('buyer.chat');

    Route::get('/chat/conversations', [BuyerMessageController::class, 'inbox'])
        ->name('buyer.chat.inbox');

    Route::post('/chat/product', [BuyerMessageController::class, 'startProduct'])
        ->name('buyer.chat.product');

    Route::post('/chat/store', [BuyerMessageController::class, 'startStore'])
        ->name('buyer.chat.store');

    Route::get('/chat/conversations/{conversation}/messages', [BuyerMessageController::class, 'thread'])
        ->whereNumber('conversation')
        ->name('buyer.chat.thread');

    Route::post('/chat/conversations/{conversation}/messages', [BuyerMessageController::class, 'send'])
        ->whereNumber('conversation')
        ->name('buyer.chat.send');

    Route::post('/chat/conversations/{conversation}/read', [BuyerMessageController::class, 'markRead'])
        ->whereNumber('conversation')
        ->name('buyer.chat.read');

    Route::get('/chat/attachments/{attachment}', [BuyerMessageController::class, 'attachment'])
        ->whereNumber('attachment')
        ->name('buyer.chat.attachment');

    Route::view('/flash-deals', 'buyer.flash-deals')
        ->name('flash-deals');

    Route::get('/vouchers', function () {
        $filter = request()->query('filter');
        $allowedFilters = ['shipping', 'discount', 'category', 'new', 'claimed'];
        $query = in_array($filter, $allowedFilters, true)
            ? '?voucher='.rawurlencode($filter)
            : '';

        return redirect('/profile'.$query.'#vouchers');
    })->name('vouchers');

    Route::view('/top-products', 'buyer.top-products')
        ->name('top-products');

    Route::view('/top-sales', 'buyer.top-sales')
        ->name('top-sales');

    Route::redirect('/cart/preview', '/cart')->name('cart.preview');

    Route::redirect('/wishlist/preview', '/wishlist')->name('wishlist.preview');

    Route::get('/wishlist', [BuyerController::class, 'wishlist'])
        ->name('wishlist.index');

    Route::post('/wishlist/toggle', [BuyerController::class, 'toggleWishlist'])
        ->name('wishlist.toggle');

    Route::get('/cart', [BuyerController::class, 'cart'])
        ->name('cart.view');

    Route::get('/cart/data', [BuyerController::class, 'cartData'])
        ->name('cart.data');

    Route::post('/cart/add', [BuyerController::class, 'addToCart'])
        ->name('cart.add');

    Route::post('/cart/buy-now', [BuyerController::class, 'buyNow'])
        ->name('cart.buy-now');

    Route::patch('/cart/{cartItem}', [BuyerController::class, 'updateCart'])
        ->name('cart.update');

    Route::delete('/cart/{cartItem}', [BuyerController::class, 'removeFromCart'])
        ->name('cart.remove');

    Route::delete('/cart', [BuyerController::class, 'clearCart'])
        ->name('cart.clear');
});

/*
|--------------------------------------------------------------------------
| Admin
|--------------------------------------------------------------------------
*/

Route::prefix('admin')
    ->name('admin.')
    ->middleware(['auth', 'role:admin'])
    ->group(function () {
        Route::redirect('/', '/admin/dashboard');

        /*
        |--------------------------------------------------------------------------
        | Dashboard
        |--------------------------------------------------------------------------
        */

        Route::get('/dashboard', AdminDashboardController::class)
            ->name('dashboard');

        /*
        |--------------------------------------------------------------------------
        | Registration Management
        |--------------------------------------------------------------------------
        | Keep the current Fusion registration lifecycle for now.
        */

        Route::get('/registrations', [AdminController::class, 'registrations'])
            ->name('registrations');

        Route::get('/registrations/buyers', [AdminRegistrationController::class, 'buyers'])
            ->name('registrations.buyers');

        Route::get('/registrations/sellers', [AdminRegistrationController::class, 'sellers'])
            ->name('registrations.sellers');

        Route::get('/registrations/logistics', [AdminRegistrationController::class, 'logistics'])
            ->name('registrations.logistics');

        Route::get(
            '/applications/{user}/documents/{type}',
            [AdminRoleApplicationController::class, 'document']
        )
            ->where('type', 'valid-id|business-permit')
            ->name('applications.document');

        Route::get(
            '/application-documents/{application}/{document}',
            [ApplicationDocumentController::class, 'show']
        )->name('application-documents.show');

        Route::patch(
            '/application-documents/{application}/{document}',
            [ApplicationDocumentController::class, 'update']
        )->name('application-documents.update');

        /*
        |--------------------------------------------------------------------------
        | User Management
        |--------------------------------------------------------------------------
        | Keep Fusion implementation until account lifecycle reconciliation.
        */

        Route::get('/users', [AdminUserController::class, 'index'])
            ->name('users');

        Route::get('/users/buyers', [AdminUserController::class, 'buyers'])
            ->name('users.buyers');

        Route::get('/users/sellers', [AdminUserController::class, 'sellers'])
            ->name('users.sellers');

        Route::get('/users/logistics', [AdminUserController::class, 'logistics'])
            ->name('users.logistics');

        Route::get('/users/riders', [AdminUserController::class, 'riders'])
            ->name('users.riders');

        Route::get('/users/{user}', [AdminUserController::class, 'show'])
            ->name('users.show');

        Route::patch('/users/{user}/status', [AdminUserController::class, 'updateStatus'])
            ->name('users.status');

        /*
        |--------------------------------------------------------------------------
        | Compliance
        |--------------------------------------------------------------------------
        */

        Route::get('/compliance', [AdminComplianceController::class, 'index'])
            ->name('compliance');

        Route::get('/compliance/violations', [AdminController::class, 'productViolations'])
            ->name('compliance.violations');

        Route::post(
            '/compliance/violations/{violation}/decide',
            [ProductComplianceController::class, 'decide']
        )->name('compliance.decide');

        // Preserve the existing endpoint for compatibility.
        Route::post(
            '/violations/{violation}/decision',
            [ProductComplianceController::class, 'decide']
        )->name('violations.decision');

        /*
        |--------------------------------------------------------------------------
        | Disputes
        |--------------------------------------------------------------------------
        */

        Route::get('/disputes', [AdminController::class, 'disputes'])
            ->name('disputes');

        Route::post(
            '/disputes/{dispute}/notes',
            [AdminDisputeController::class, 'saveNote']
        )->name('disputes.notes.store');

        Route::post(
            '/disputes/{dispute}/resolve',
            [AdminDisputeController::class, 'resolve']
        )->name('disputes.resolve');

        Route::post(
            '/disputes/{dispute}/update',
            [AdminDisputeController::class, 'sendUpdate']
        )->name('disputes.update');

        Route::get(
            '/disputes/evidence/{evidence}/download',
            [AdminDisputeController::class, 'downloadEvidence']
        )->name('disputes.evidence.download');

        Route::get(
            '/disputes/evidence/{evidence}/preview',
            [AdminDisputeController::class, 'previewEvidence']
        )->name('disputes.evidence.preview');

        /*
        |--------------------------------------------------------------------------
        | Returns & Refunds
        |--------------------------------------------------------------------------
        */

        Route::get(
            '/compliance/returns-refunds',
            [AdminController::class, 'returnsRefunds']
        )->name('compliance.returns-refunds');

        Route::post(
            '/compliance/returns-refunds/{returnRequest}/approve',
            [AdminReturnRefundController::class, 'approve']
        )->name('compliance.returns-refunds.approve');

        Route::post(
            '/compliance/returns-refunds/{returnRequest}/reject',
            [AdminReturnRefundController::class, 'reject']
        )->name('compliance.returns-refunds.reject');

        Route::post(
            '/compliance/returns-refunds/{returnRequest}/request-evidence',
            [AdminReturnRefundController::class, 'requestEvidence']
        )->name('compliance.returns-refunds.request-evidence');

        Route::post(
            '/compliance/returns-refunds/{refund}/record-payout',
            [AdminReturnRefundController::class, 'recordPayout']
        )->whereNumber('refund')
            ->name('compliance.returns-refunds.record-payout');

        Route::post(
            '/compliance/returns-refunds/{refund}/responsibility',
            [AdminReturnRefundController::class, 'recordResponsibility']
        )->whereNumber('refund')
            ->name('compliance.returns-refunds.responsibility');

        Route::post(
            '/compliance/returns-refunds/{refund}/seller-recovery',
            [AdminReturnRefundController::class, 'recordSellerRecovery']
        )->whereNumber('refund')
            ->name('compliance.returns-refunds.seller-recovery');

        Route::get(
            '/compliance/returns-refunds/{refund}/payout-receipt',
            [AdminReturnRefundController::class, 'downloadPayoutReceipt']
        )->whereNumber('refund')
            ->name('compliance.returns-refunds.payout-receipt');

        Route::get(
            '/compliance/returns-refunds/evidence/{evidence}',
            [AdminReturnRefundController::class, 'downloadEvidence']
        )->whereNumber('evidence')
            ->name('compliance.returns-refunds.evidence.download');

        Route::get(
            '/compliance/returns-refunds/evidence/{evidence}/preview',
            [AdminReturnRefundController::class, 'previewEvidence']
        )->whereNumber('evidence')
            ->name('compliance.returns-refunds.evidence.preview');

        /*
        |--------------------------------------------------------------------------
        | Commerce / Finance
        |--------------------------------------------------------------------------
        */

        Route::get('/orders', [AdminCommerceController::class, 'orders'])
            ->name('orders');

        Route::post(
            '/orders/payments/{payment}/confirm',
            [AdminCommerceController::class, 'confirmPayment']
        )->name('orders.payments.confirm');

        Route::get('/commissions', [AdminFinanceController::class, 'commissions'])
            ->name('commissions');

        Route::get('/transactions', [AdminFinanceController::class, 'transactions'])
            ->name('transactions');

        Route::get('/payments', [AdminFinanceController::class, 'payments'])
            ->name('payments');

        Route::get('/seller-payouts', [AdminSellerPayoutController::class, 'index'])
            ->name('seller-payouts.index');

        Route::get('/seller-payouts/{withdrawal}', [AdminSellerPayoutController::class, 'show'])
            ->whereNumber('withdrawal')
            ->name('seller-payouts.show');

        Route::post('/seller-payouts/{withdrawal}/approve', [AdminSellerPayoutController::class, 'approve'])
            ->whereNumber('withdrawal')
            ->name('seller-payouts.approve');

        Route::post('/seller-payouts/{withdrawal}/reject', [AdminSellerPayoutController::class, 'reject'])
            ->whereNumber('withdrawal')
            ->name('seller-payouts.reject');

        Route::post('/seller-payouts/{withdrawal}/processing', [AdminSellerPayoutController::class, 'startProcessing'])
            ->whereNumber('withdrawal')
            ->name('seller-payouts.processing');

        Route::post('/seller-payouts/{withdrawal}/transfer', [AdminSellerPayoutController::class, 'recordTransfer'])
            ->whereNumber('withdrawal')
            ->name('seller-payouts.transfer');

        Route::post('/seller-payouts/{withdrawal}/fail-transfer', [AdminSellerPayoutController::class, 'failTransfer'])
            ->whereNumber('withdrawal')
            ->name('seller-payouts.fail-transfer');

        Route::post('/seller-payouts/{withdrawal}/complete', [AdminSellerPayoutController::class, 'complete'])
            ->whereNumber('withdrawal')
            ->name('seller-payouts.complete');

        Route::get('/seller-payouts/{withdrawal}/proof', [AdminSellerPayoutController::class, 'downloadProof'])
            ->whereNumber('withdrawal')
            ->name('seller-payouts.proof');

        Route::post('/payments/cod/{collection}/reconcile', [AdminFinanceController::class, 'reconcileCodCash'])
            ->whereNumber('collection')
            ->name('payments.cod.reconcile');

        Route::get('/payments/cod/{collection}/receipt', [AdminFinanceController::class, 'printCodCashReceipt'])
            ->whereNumber('collection')
            ->name('payments.cod.receipt');

        Route::post('/payments/seller-settlements', [AdminFinanceController::class, 'generateSellerPayout'])
            ->name('payments.seller-settlements.store');

        Route::get('/payments/seller-settlements/{payout}/cash-acknowledgment', [AdminFinanceController::class, 'printSellerCashAcknowledgment'])
            ->whereNumber('payout')
            ->name('payments.seller-settlements.cash-acknowledgment');

        Route::post('/payments/seller-settlements/{payout}/record-paid', [AdminFinanceController::class, 'recordSellerPayout'])
            ->whereNumber('payout')
            ->name('payments.seller-settlements.record-paid');

        Route::get('/payments/seller-settlements/{payout}/receipt', [AdminFinanceController::class, 'downloadSellerPayoutReceipt'])
            ->whereNumber('payout')
            ->name('payments.seller-settlements.receipt');

        Route::post('/payments/refunds/{refund}/issue-cash', [AdminFinanceController::class, 'issueRefundCash'])
            ->whereNumber('refund')
            ->name('payments.refunds.issue-cash');

        Route::post('/payments/refund-cash/{payout}/complete', [AdminFinanceController::class, 'completeRefundCash'])
            ->whereNumber('payout')
            ->name('payments.refund-cash.complete');

        Route::get('/payments/refund-cash/{payout}/acknowledgment', [AdminFinanceController::class, 'downloadRefundCashAcknowledgment'])
            ->whereNumber('payout')
            ->name('payments.refund-cash.acknowledgment');

        Route::get('/reports', [AdminFinanceController::class, 'reports'])
            ->name('reports');

        Route::get('/reports/export', [AdminFinanceController::class, 'exportReports'])
            ->name('reports.export');

        /*
        |--------------------------------------------------------------------------
        | Fulfillment
        |--------------------------------------------------------------------------
        */

        Route::get('/fulfillment', [AdminFulfillmentController::class, 'index'])
            ->name('fulfillment');

        Route::patch(
            '/fulfillment/{shipment}/override',
            [AdminFulfillmentController::class, 'override']
        )->name('fulfillment.override');

        /*
        |--------------------------------------------------------------------------
        | Messaging
        |--------------------------------------------------------------------------
        */

        Route::get('/messages', [AdminController::class, 'messages'])
            ->name('messages');

        Route::get(
            '/messages/inbox',
            [AdminMessageController::class, 'inbox']
        )->name('messages.inbox');

        Route::get(
            '/messages/{conversation}/messages',
            [AdminMessageController::class, 'thread']
        )->whereNumber('conversation')
            ->name('messages.thread');

        Route::post(
            '/messages/conversations',
            [AdminMessageController::class, 'storeConversation']
        )->name('messages.conversations.store');

        Route::post(
            '/messages/{conversation}',
            [AdminMessageController::class, 'send']
        )->name('messages.send');

        Route::post(
            '/messages/{conversation}/read',
            [AdminMessageController::class, 'markRead']
        )->name('messages.read');

        Route::post(
            '/messages/{conversation}/unread',
            [AdminMessageController::class, 'markUnread']
        )->name('messages.unread');

        Route::get(
            '/messages/attachments/{attachment}',
            [AdminMessageController::class, 'downloadAttachment']
        )->name('messages.attachments.download');

        Route::post(
            '/messages/disputes/{dispute}/conversation',
            [AdminMessageController::class, 'openDisputeConversation']
        )->name('messages.disputes.open');

        /*
        |--------------------------------------------------------------------------
        | Announcements
        |--------------------------------------------------------------------------
        */

        Route::get('/announcements', [AdminAnnouncementController::class, 'index'])
            ->name('announcements');

        Route::post('/announcements', [AdminAnnouncementController::class, 'store'])
            ->name('announcements.store');

        Route::patch(
            '/announcements/{announcement}',
            [AdminAnnouncementController::class, 'update']
        )->name('announcements.update');

        Route::delete(
            '/announcements/{announcement}',
            [AdminAnnouncementController::class, 'destroy']
        )->name('announcements.destroy');

        Route::get(
            '/announcements/{announcement}/media/{media}',
            [AnnouncementController::class, 'media']
        )->whereNumber('announcement')
            ->whereNumber('media')
            ->name('announcements.media');

        /*
        |--------------------------------------------------------------------------
        | Support Tickets
        |--------------------------------------------------------------------------
        */

        Route::get('/support-tickets', [AdminSupportTicketController::class, 'index'])
            ->name('support-tickets');

        /*
        |--------------------------------------------------------------------------
        | Settings / Policies
        |--------------------------------------------------------------------------
        | Keep current Fusion implementations for now.
        */

        Route::get('/settings', [AdminController::class, 'settings'])
            ->name('settings');

        Route::get('/shipping-rates', [ShippingRateController::class, 'index'])
            ->name('shipping-rates');

        Route::put('/shipping-rates', [ShippingRateController::class, 'update'])
            ->name('shipping-rates.update');

        Route::get('/policies', [AdminPolicyController::class, 'index'])
            ->name('policies');

        Route::post('/policies', [AdminPolicyController::class, 'store'])
            ->name('policies.store');

        Route::patch('/policies/{policy}', [AdminPolicyController::class, 'update'])
            ->name('policies.update');

        Route::post('/policies/{policy}/publish', [AdminPolicyController::class, 'publish'])
            ->name('policies.publish');

        Route::post('/policies/{policy}/archive', [AdminPolicyController::class, 'archive'])
            ->name('policies.archive');

        /*
        |--------------------------------------------------------------------------
        | Audit Logs
        |--------------------------------------------------------------------------
        */

        Route::get('/audit-logs', [AdminAuditLogController::class, 'index'])
            ->name('audit-logs');

        /*
        |--------------------------------------------------------------------------
        | Admin Account
        |--------------------------------------------------------------------------
        | Keep current Fusion implementation until account integration phase.
        */

        Route::get('/account', [AdminController::class, 'account'])
            ->name('account');

        Route::patch('/account/password', [AdminController::class, 'updatePassword'])
            ->name('account.password');

        /*
        |--------------------------------------------------------------------------
        | Account Applications
        |--------------------------------------------------------------------------
        | IMPORTANT:
        | Keep Fusion's user-based approval contract for now.
        */

        Route::post(
            '/applications/{user}/approve',
            [AccountApprovalController::class, 'approveByAdmin']
        )->name('applications.approve');

        Route::post(
            '/applications/{user}/request-revision',
            [AccountApprovalController::class, 'requestRevisionByAdmin']
        )->name('applications.request-revision');

        Route::post(
            '/applications/{user}/reject',
            [AccountApprovalController::class, 'rejectByAdmin']
        )->name('applications.reject');
    });

/*
|--------------------------------------------------------------------------
| Seller
|--------------------------------------------------------------------------
*/

Route::prefix('seller')
    ->name('seller.')
    ->middleware(['auth', 'role:seller'])
    ->group(function () {
        Route::redirect('/', '/seller/dashboard');

        Route::get('/dashboard', [SellerWorkflowController::class, 'dashboard'])
            ->name('dashboard');

        Route::get('/orders', [SellerWorkflowController::class, 'orders'])
            ->name('orders');

        Route::get('/orders/{sellerOrder}/tracking', [SellerWorkflowController::class, 'orderTracking'])
            ->whereNumber('sellerOrder')
            ->name('orders.tracking');

        Route::patch('/orders/{sellerOrder}/status', [SellerWorkflowController::class, 'updateOrderStatus'])
            ->whereNumber('sellerOrder')
            ->name('orders.status');

        Route::get('/orders/returns-refunds', [SellerWorkflowController::class, 'returns'])
            ->name('orders.returns');

        Route::get('/orders/returns-refunds/{caseId}', [SellerWorkflowController::class, 'returnDetails'])
            ->name('orders.returns.show');

        Route::post('/orders/returns-refunds/{returnRequest}/respond', [SellerWorkflowController::class, 'respondToReturn'])
            ->whereNumber('returnRequest')
            ->name('orders.returns.respond');

        Route::post('/orders/returns-refunds/{returnRequest}/inspect', [SellerWorkflowController::class, 'inspectReturn'])
            ->whereNumber('returnRequest')
            ->name('orders.returns.inspect');

        Route::post('/orders/returns-refunds/{returnRequest}/evidence', [SellerWorkflowController::class, 'addReturnEvidence'])
            ->whereNumber('returnRequest')
            ->name('orders.returns.evidence.store');

        Route::get('/orders/returns-refunds/evidence/{evidence}', [SellerWorkflowController::class, 'downloadReturnEvidence'])
            ->whereNumber('evidence')
            ->name('orders.returns.evidence.download');

        Route::get('/orders/returns-refunds/evidence/{evidence}/view', [SellerWorkflowController::class, 'viewReturnEvidence'])
            ->whereNumber('evidence')
            ->name('orders.returns.evidence.view');

        Route::redirect('/orders/new', '/seller/orders?status=new')
            ->name('orders.new');

        Route::redirect('/orders/to-prepare', '/seller/orders?status=to-prepare')
            ->name('orders.prepare');

        Route::redirect('/orders/ready-for-pickup', '/seller/orders?status=ready-pickup')
            ->name('orders.ready');

        Route::redirect('/orders/history', '/seller/orders?status=completed')
            ->name('orders.history');

        Route::get('/fulfillment/waybills', [SellerWaybillController::class, 'index'])
            ->name('fulfillment.waybills');

        Route::post('/fulfillment/waybills/pdf', [SellerWaybillController::class, 'downloadPdf'])
            ->name('fulfillment.waybills.pdf');

        Route::post('/fulfillment/waybills/{waybill}/printed', [SellerWaybillController::class, 'confirmPrinted'])
            ->whereNumber('waybill')
            ->name('fulfillment.waybills.printed');

        Route::get('/fulfillment/pickups', [SellerWorkflowController::class, 'pickupRequests'])
            ->name('fulfillment.pickups');

        Route::post('/fulfillment/pickups', [SellerWorkflowController::class, 'storePickupRequest'])
            ->name('fulfillment.pickups.store');

        Route::get('/fulfillment/tracking', [SellerWorkflowController::class, 'shipmentTracking'])
            ->name('fulfillment.tracking');

        Route::get('/finance/earnings', [SellerWorkflowController::class, 'financeEarnings'])
            ->name('finance.earnings');

        Route::get('/finance/wallet', [SellerWalletController::class, 'index'])
            ->name('finance.wallet');

        Route::get('/finance/bank-accounts', [SellerWalletController::class, 'bankAccounts'])
            ->name('finance.bank-accounts');

        Route::post('/finance/bank-accounts', [SellerWalletController::class, 'storeBankAccount'])
            ->name('finance.bank-accounts.store');

        Route::patch('/finance/bank-accounts/{account}/deactivate', [SellerWalletController::class, 'deactivateBankAccount'])
            ->whereNumber('account')
            ->name('finance.bank-accounts.deactivate');

        Route::post('/finance/withdrawals', [SellerWalletController::class, 'requestWithdrawal'])
            ->name('finance.withdrawals.store');

        Route::get('/finance/withdrawals/{withdrawal}/proof', [SellerWalletController::class, 'downloadTransferProof'])
            ->whereNumber('withdrawal')
            ->name('finance.withdrawals.proof');

        Route::get('/finance/transactions', [SellerWorkflowController::class, 'financeTransactions'])
            ->name('finance.transactions');

        Route::get('/finance/settlements/{payout}/receipt', [SellerWorkflowController::class, 'downloadSellerPayoutReceipt'])
            ->whereNumber('payout')
            ->name('finance.settlements.receipt');

        Route::get('/store', [SellerController::class, 'store'])
            ->name('store');

        Route::get('/store/documents/{type}', [SellerController::class, 'storeDocument'])
            ->where('type', 'valid_id|business_permit')
            ->name('store.documents.preview');

        Route::post('/store', [SellerController::class, 'saveStore'])
            ->name('store.save');

        Route::patch('/store/pickup-address/coordinates', [SellerController::class, 'updatePickupCoordinates'])
            ->name('store.pickup.coordinates');

        Route::get('/store/appearance', [SellerController::class, 'storeAppearance'])
            ->name('store.appearance');

        Route::post('/store/appearance', [SellerController::class, 'saveStoreAppearance'])
            ->name('store.appearance.save');

        Route::get('/store/publication', [SellerController::class, 'publicationSettings'])
            ->name('store.publication');

        Route::post('/store/publication', [SellerController::class, 'updatePublication'])
            ->name('store.publication.update');

        Route::get('/products', [SellerController::class, 'products'])
            ->name('products');

        Route::get('/inventory', [SellerController::class, 'inventory'])
            ->name('inventory');

        Route::post('/inventory/adjustments', [SellerController::class, 'adjustInventory'])
            ->name('inventory.adjustments');

        Route::post('/inventory/thresholds', [SellerController::class, 'setInventoryThreshold'])
            ->name('inventory.thresholds');

        Route::get('/products/pricing', [SellerController::class, 'pricing'])
            ->name('products.pricing');

        Route::get('/products/pricing/create', [SellerController::class, 'createPromotion'])
            ->name('products.pricing.create');

        Route::post('/products/pricing', [SellerController::class, 'storePromotion'])
            ->name('products.pricing.store');

        Route::patch('/products/pricing/{product}', [SellerController::class, 'updateProductPricing'])
            ->name('products.pricing.update');

        Route::patch('/products/pricing/{product}/voucher', [SellerController::class, 'updateVoucherEligibility'])
            ->name('products.pricing.voucher');

        Route::get('/products/create', [SellerProductController::class, 'createProduct'])
            ->name('products.create');

        Route::post('/products', [SellerProductController::class, 'addProduct'])
            ->name('products.add');

        Route::get('/products/{product}/edit', [SellerProductController::class, 'editProduct'])
            ->name('products.edit');

        Route::put('/products/{product}', [SellerProductController::class, 'updateProduct'])
            ->name('products.update');

        Route::patch('/products/{product}/archive', [SellerProductController::class, 'toggleProductArchive'])
            ->name('products.archive');

        Route::get('/reports', [SellerWorkflowController::class, 'reportsOverview'])
            ->name('reports');

        Route::get('/reports/export', [SellerWorkflowController::class, 'exportReportsOverview'])
            ->name('reports.export');

        Route::get('/reports/sales', [SellerController::class, 'salesReport'])
            ->name('reports.sales');

        Route::get('/reports/sales/export', [SellerWorkflowController::class, 'exportSalesReport'])
            ->name('reports.sales.export');

        Route::get('/reports/financial', [SellerWorkflowController::class, 'financialReport'])
            ->name('reports.financial');

        Route::get('/reports/financial/export', [SellerWorkflowController::class, 'exportFinancialReport'])
            ->name('reports.financial.export');

        Route::get('/support/messages', [SellerController::class, 'messages'])
            ->name('support.messages');

        Route::get('/support/messages/conversations', [SellerMessageController::class, 'inbox'])
            ->name('support.messages.inbox');

        Route::get('/support/messages/conversations/{conversation}/messages', [SellerMessageController::class, 'thread'])
            ->whereNumber('conversation')
            ->name('support.messages.thread');

        Route::post('/support/messages/conversations/{conversation}/messages', [SellerMessageController::class, 'send'])
            ->whereNumber('conversation')
            ->name('support.messages.send');

        Route::post('/support/messages/conversations/{conversation}/read', [SellerMessageController::class, 'markRead'])
            ->whereNumber('conversation')
            ->name('support.messages.read');

        Route::get('/support/messages/attachments/{attachment}', [SellerMessageController::class, 'attachment'])
            ->whereNumber('attachment')
            ->name('support.messages.attachment');

        Route::get('/support/feedback', [SellerController::class, 'customerFeedback'])
            ->name('support.feedback');

        Route::post('/support/feedback/{review}/reply', [SellerController::class, 'replyFeedback'])
            ->whereNumber('review')
            ->name('support.feedback.reply');

        Route::get('/settings/account', [SellerController::class, 'account'])
            ->name('settings.account');

        Route::patch('/settings/account', [SellerController::class, 'updateAccount'])
            ->name('settings.account.update');

        Route::get('/settings/security', [SellerController::class, 'security'])
            ->name('settings.security');

        Route::patch('/settings/security/password', [SellerController::class, 'updatePassword'])
            ->name('settings.security.password');

        Route::get('/settings/notifications', [SellerController::class, 'notificationSettings'])
            ->name('settings.notifications');
    });

/**
|--------------------------------------------------------------------------
| Logistics
|--------------------------------------------------------------------------
 */
Route::prefix('logistics')->name('logistics.')->group(function () {
    // Public Logistics pages.
    Route::get('/', [LogisticsController::class, 'landing'])
        ->name('landing');

    Route::middleware('guest')->group(function () {
        Route::get('/register', [LogisticsController::class, 'register'])
            ->name('register');

        Route::post('/register', [LogisticsController::class, 'submitRegistration'])
            ->middleware('throttle:10,1')
            ->block(30, 5)
            ->name('register.submit');
    });

    Route::redirect('/login', '/login')
        ->name('login');

    // Authenticated active Logistics accounts only.
    Route::middleware(['auth', 'role:logistics'])->group(function () {
        Route::get('/dashboard', [LogisticsController::class, 'dashboard'])
            ->name('dashboard');

        Route::get('/riders', [LogisticsController::class, 'riders'])
            ->name('riders.index');

        Route::get('/cash', [LogisticsCashController::class, 'index'])
            ->name('cash.index');

        Route::post('/cash/cod/{collection}/receive', [LogisticsCashController::class, 'receiveCod'])
            ->whereNumber('collection')
            ->name('cash.cod.receive');

        Route::post('/cash/cod/{collection}/remit', [LogisticsCashController::class, 'remitCod'])
            ->whereNumber('collection')
            ->name('cash.cod.remit');

        Route::post('/cash/refunds/{payout}/receive', [LogisticsCashController::class, 'receiveRefundCash'])
            ->whereNumber('payout')
            ->name('cash.refunds.receive');

        Route::post('/cash/refunds/{payout}/assign', [LogisticsCashController::class, 'assignRefundCash'])
            ->whereNumber('payout')
            ->name('cash.refunds.assign');

        Route::post('/cash/refunds/{payout}/acknowledgment-slip/prepare', [LogisticsCashController::class, 'prepareRefundAcknowledgmentSlip'])
            ->whereNumber('payout')
            ->name('cash.refunds.acknowledgment-slip.prepare');

        Route::get('/cash/refunds/{payout}/acknowledgment-slip', [LogisticsCashController::class, 'refundAcknowledgmentSlip'])
            ->whereNumber('payout')
            ->name('cash.refunds.acknowledgment-slip');

        Route::post('/cash/refunds/{payout}/confirm-return', [LogisticsCashController::class, 'confirmReturnedRefundCash'])
            ->whereNumber('payout')
            ->name('cash.refunds.confirm-return');

        Route::post('/cash/refunds/{payout}/verify-receipt', [LogisticsCashController::class, 'verifyRefundReceipt'])
            ->whereNumber('payout')
            ->name('cash.refunds.verify-receipt');

        Route::get('/cash/refunds/{payout}/acknowledgment', [LogisticsCashController::class, 'downloadRefundAcknowledgment'])
            ->whereNumber('payout')
            ->name('cash.refunds.acknowledgment');

        Route::get('/riders/{id}', [LogisticsController::class, 'showRider'])
            ->name('riders.show');

        Route::patch('/riders/{user}/assignment', [LogisticsController::class, 'updateRiderAssignment'])
            ->name('riders.assignment.update');

        Route::get('/pickups', [LogisticsController::class, 'pickups'])
            ->name('pickups.index');

        Route::post('/pickups/{pickupRequest}/verify', [LogisticsController::class, 'verifyPickup'])
            ->name('pickups.verify');

        Route::patch('/pickups/{pickupRequest}/cancel', [LogisticsController::class, 'cancelPickup'])
            ->name('pickups.cancel');

        Route::post('/pickups/{pickupRequest}/assign', [LogisticsController::class, 'assignPickup'])
            ->name('pickups.assign');

        Route::get('/incoming', [LogisticsController::class, 'incoming'])
            ->name('sorting.incoming');

        Route::post('/incoming/receive', [WaybillScanController::class, 'receiveForm'])
            ->name('sorting.incoming.receive');

        Route::get('/transfers', [LogisticsController::class, 'transfers'])
            ->name('transfers.index');

        Route::post('/transfers/{shipment}/send', [LogisticsController::class, 'sendTransfer'])
            ->whereNumber('shipment')
            ->name('transfers.send');

        Route::post('/transfers/accept', [LogisticsController::class, 'acceptTransfer'])
            ->name('transfers.accept');

        Route::get('/sorting', [LogisticsController::class, 'sorting'])
            ->name('sorting.center');

        Route::post('/sorting/zones', [LogisticsController::class, 'storeSortingZone'])
            ->name('sorting.zones.store');

        Route::patch('/sorting/zones/{sortingZone}', [LogisticsController::class, 'updateSortingZone'])
            ->name('sorting.zones.update');

        Route::patch('/sorting/zones/{sortingZone}/status', [LogisticsController::class, 'updateSortingZoneStatus'])
            ->name('sorting.zones.status');

        Route::patch('/sorting/zones/{sortingZone}/archive', [LogisticsController::class, 'archiveSortingZone'])
            ->whereNumber('sortingZone')
            ->name('sorting.zones.archive');

        Route::patch('/sorting/zones/{sortingZone}/restore', [LogisticsController::class, 'restoreSortingZone'])
            ->whereNumber('sortingZone')
            ->name('sorting.zones.restore');

        Route::delete('/sorting/zones/{sortingZone}', [LogisticsController::class, 'forceDeleteSortingZone'])
            ->whereNumber('sortingZone')
            ->name('sorting.zones.destroy');

        Route::patch('/sorting/parcels/{parcel}', [LogisticsController::class, 'sortParcel'])
            ->name('sorting.parcels.update');

        Route::get('/dispatch', [LogisticsController::class, 'dispatch'])
            ->name('dispatch.index');

        Route::post('/dispatch/{zone}', [LogisticsController::class, 'dispatchZone'])
            ->name('dispatch.store');

        Route::get('/monitoring', [LogisticsController::class, 'monitoring'])
            ->name('dispatch.monitoring');

        Route::get('/reports', [LogisticsController::class, 'reports'])
            ->name('reports.index');

        Route::get('/messages', [LogisticsController::class, 'messages'])
            ->name('messages.index');

        Route::post('/messages/conversations', [LogisticsController::class, 'storeConversation'])
            ->name('messages.conversations.store');

        Route::get('/messages/attachments/{attachment}', [LogisticsController::class, 'downloadMessageAttachment'])
            ->name('messages.attachments.download');

        Route::get('/messages/{conversation}/messages', [LogisticsController::class, 'messageThread'])
            ->name('messages.thread');

        Route::post('/messages/{conversation}/read', [LogisticsController::class, 'markMessageConversationRead'])
            ->name('messages.read');

        Route::post('/messages/{conversation}', [LogisticsController::class, 'sendMessage'])
            ->name('messages.send');

        Route::patch('/account/profile', [LogisticsController::class, 'updateProfile'])
            ->name('profile.update');

        Route::patch('/account/password', [LogisticsController::class, 'updatePassword'])
            ->name('profile.password.update');

        Route::patch('/account/facility', [LogisticsController::class, 'updateFacility'])
            ->name('profile.facility.update');

        Route::get('/account', [LogisticsController::class, 'account'])
            ->name('profile.index');

        Route::get('/api/waybills/{identifier}', [WaybillScanController::class, 'show'])
            ->name('waybills.lookup');

        Route::post('/api/waybills/{identifier}/receive', [WaybillScanController::class, 'receive'])
            ->name('waybills.receive');

        /*
        |--------------------------------------------------------------------------
        | Rider Application Documents
        |--------------------------------------------------------------------------
        */

        Route::get(
            '/rider-applications/{application}/documents/{document}',
            [ApplicationDocumentController::class, 'show']
        )->name('rider-documents.show');

        Route::patch(
            '/rider-applications/{application}/documents/{document}',
            [ApplicationDocumentController::class, 'update']
        )->name('rider-documents.update');

        /*
        |--------------------------------------------------------------------------
        | Rider Approval
        |--------------------------------------------------------------------------
        */

        Route::post(
            '/riders/{user}/approve',
            [AccountApprovalController::class, 'approveRider']
        )->name('riders.approve');

        Route::post(
            '/riders/{user}/reject',
            [AccountApprovalController::class, 'rejectRider']
        )->name('riders.reject');
    });
});

/*
|--------------------------------------------------------------------------
| Rider
|--------------------------------------------------------------------------
*/

Route::prefix('rider')->name('rider.')->group(function () {
    // Public Rider pages.
    Route::get('/', [RiderController::class, 'landing'])
        ->name('landing');

    Route::middleware('guest')->group(function () {
        Route::get('/register', [RiderController::class, 'register'])
            ->name('register');

        Route::post('/register', [RiderController::class, 'submitRegistration'])
            ->middleware('throttle:10,1')->block(30, 5)->name('register.submit');
    });

    Route::redirect('/login', '/login')
        ->name('login');

    // Authenticated active Rider accounts only.
    Route::middleware(['auth', 'role:rider'])->group(function () {
        Route::redirect('/dashboard', '/rider/dashboard/pickups')
            ->name('dashboard');

        Route::get('/dashboard/pickups', [RiderController::class, 'pickupsDashboard'])
            ->name('dashboard.pickups');

        Route::patch('/account/availability', [RiderController::class, 'updateAvailability'])
            ->name('availability.update');

        Route::get('/dashboard/deliveries', [RiderController::class, 'deliveriesDashboard'])
            ->name('dashboard.deliveries');

        Route::get('/refund-cash', [RiderCashController::class, 'index'])
            ->name('refund-cash.index');

        Route::post('/refund-cash/{payout}/receive', [RiderCashController::class, 'receive'])
            ->whereNumber('payout')
            ->name('refund-cash.receive');

        Route::post('/refund-cash/{payout}/start', [RiderCashController::class, 'start'])
            ->whereNumber('payout')
            ->name('refund-cash.start');

        Route::post('/refund-cash/{payout}/deliver', [RiderCashController::class, 'deliver'])
            ->whereNumber('payout')
            ->name('refund-cash.deliver');

        Route::post('/refund-cash/{payout}/fail', [RiderCashController::class, 'fail'])
            ->whereNumber('payout')
            ->name('refund-cash.fail');

        Route::post('/refund-cash/{payout}/return-cash', [RiderCashController::class, 'returnCash'])
            ->whereNumber('payout')
            ->name('refund-cash.return-cash');

        Route::get('/pickup/{id}', [RiderController::class, 'pickup'])
            ->name('orders.pickup');

        Route::post('/pickup/{id}/accept', [RiderController::class, 'acceptPickup'])
            ->name('orders.pickup.accept');

        Route::post('/pickup/{id}/confirm', [RiderController::class, 'confirmPickup'])
            ->name('orders.pickup.confirm');

        Route::get('/deliver/{id}', [RiderController::class, 'deliver'])
            ->name('orders.delivery');

        Route::post('/deliver/{id}/start', [RiderController::class, 'startDelivery'])
            ->name('orders.delivery.start');

        Route::post('/deliver/{id}/confirm', [RiderController::class, 'confirmDelivery'])
            ->name('orders.delivery.confirm');

        Route::post('/deliver/{id}/fail', [RiderController::class, 'failDelivery'])
            ->name('orders.delivery.fail');

        Route::post('/deliver/{id}/retry', [RiderController::class, 'retryDelivery'])
            ->name('orders.delivery.retry');

        Route::get('/earnings', [RiderController::class, 'earnings'])
            ->name('earnings.index');

        Route::get('/history/export', [RiderController::class, 'exportHistory'])
            ->name('history.export');

        Route::get('/history', [RiderController::class, 'history'])
            ->name('history.index');

        Route::get('/messages', [RiderController::class, 'messages'])
            ->name('messages.index');

        Route::get('/messages/attachments/{attachment}', [RiderController::class, 'downloadMessageAttachment'])
            ->whereNumber('attachment')
            ->name('messages.attachments.download');

        Route::get('/messages/{conversation}/messages', [RiderController::class, 'messageThread'])
            ->whereNumber('conversation')
            ->name('messages.thread');

        Route::post('/messages/{conversation}/read', [RiderController::class, 'markMessageConversationRead'])
            ->whereNumber('conversation')
            ->name('messages.read');

        Route::post('/messages/{conversation}', [RiderController::class, 'sendMessage'])
            ->whereNumber('conversation')
            ->name('messages.send');

        Route::patch('/account/profile', [RiderController::class, 'updateProfile'])
            ->name('profile.update');

        Route::patch('/account/password', [RiderController::class, 'updatePassword'])
            ->name('profile.password.update');

        Route::patch('/account/address/home', [RiderController::class, 'updateHomeAddress'])
            ->name('profile.address.home.update');

        Route::post('/account/addresses', [RiderController::class, 'storeAddress'])
            ->name('profile.addresses.store');

        Route::patch('/account/vehicle', [RiderController::class, 'updateVehicle'])
            ->name('profile.vehicle.update');

        Route::get('/account', [RiderController::class, 'account'])
            ->name('profile.index');
    });
});
