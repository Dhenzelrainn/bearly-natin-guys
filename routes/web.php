<?php

use App\Http\Controllers\auth\BearlyAuthController;
use App\Http\Controllers\auth\EmailVerificationController;
use App\Http\Controllers\PostalCodeController;
use App\Http\Controllers\AccountApprovalController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AdminRoleApplicationController;
use App\Http\Controllers\BuyerController;
use App\Http\Controllers\LogisticsController;
use App\Http\Controllers\ProductComplianceController;
use App\Http\Controllers\PsgcController;
use App\Http\Controllers\RiderController;
use App\Http\Controllers\SellerController;
use App\Http\Controllers\SellerProductController;
use App\Http\Controllers\SellerWorkflowController;
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

    Route::get('/wishlist', [BuyerController::class, 'wishlist'])
        ->name('wishlist.index');

    Route::post('/wishlist/toggle', [BuyerController::class, 'toggleWishlist'])
        ->name('wishlist.toggle');

    Route::get('/cart', [BuyerController::class, 'cart'])
        ->name('cart.view');

    Route::post('/cart/add', [BuyerController::class, 'addToCart'])
        ->name('cart.add');

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

        Route::get('/dashboard', [AdminController::class, 'dashboard'])
            ->name('dashboard');

        Route::get('/registrations', [AdminController::class, 'registrations'])
            ->name('registrations');

        Route::get('/registrations/buyers', [AdminRoleApplicationController::class, 'buyers'])
            ->name('registrations.buyers');

        Route::get('/registrations/sellers', [AdminRoleApplicationController::class, 'sellers'])
            ->name('registrations.sellers');

        Route::get('/registrations/logistics', [AdminRoleApplicationController::class, 'logistics'])
            ->name('registrations.logistics');

        Route::get('/applications/{user}/documents/{type}', [AdminRoleApplicationController::class, 'document'])
            ->where('type', 'valid-id|business-permit')
            ->name('applications.document');

        Route::get('/users', [AdminController::class, 'users'])
            ->name('users');

        Route::get('/users/buyers', [AdminController::class, 'buyerUsers'])
            ->name('users.buyers');

        Route::get('/users/sellers', [AdminController::class, 'sellerUsers'])
            ->name('users.sellers');

        Route::get('/users/logistics', [AdminController::class, 'logisticsUsers'])
            ->name('users.logistics');

        Route::get('/users/riders', [AdminController::class, 'riderUsers'])
            ->name('users.riders');

        Route::get('/compliance', [AdminController::class, 'compliance'])
            ->name('compliance');

        Route::get('/disputes', [AdminController::class, 'disputes'])
            ->name('disputes');

        Route::get('/compliance/violations', [AdminController::class, 'productViolations'])
            ->name('compliance.violations');

        Route::get('/compliance/returns-refunds', [AdminController::class, 'returnsRefunds'])
            ->name('compliance.returns-refunds');

        Route::get('/commissions', [AdminController::class, 'commissions'])
            ->name('commissions');

        Route::get('/transactions', [AdminController::class, 'transactions'])
            ->name('transactions');

        Route::get('/payments', [AdminController::class, 'payments'])
            ->name('payments');

        Route::get('/reports', [AdminController::class, 'reports'])
            ->name('reports');

        Route::get('/settings', [AdminController::class, 'settings'])
            ->name('settings');

        Route::get('/policies', [AdminController::class, 'policies'])
            ->name('policies');

        Route::get('/audit-logs', [AdminController::class, 'auditLogs'])
            ->name('audit-logs');

        Route::get('/messages', [AdminController::class, 'messages'])
            ->name('messages');

        Route::get('/announcements', [AdminController::class, 'announcements'])
            ->name('announcements');

        Route::get('/account', [AdminController::class, 'account'])
            ->name('account');

        Route::post('/applications/{user}/approve', [AccountApprovalController::class, 'approveByAdmin'])
            ->name('applications.approve');

        Route::post('/applications/{user}/reject', [AccountApprovalController::class, 'rejectByAdmin'])
            ->name('applications.reject');

        Route::post('/violations/{violation}/decision', [ProductComplianceController::class, 'decide'])
            ->name('violations.decision');
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

        Route::get('/orders/returns-refunds', [SellerWorkflowController::class, 'returns'])
            ->name('orders.returns');

        Route::get('/orders/returns-refunds/{caseId}', [SellerWorkflowController::class, 'returnDetails'])
            ->name('orders.returns.show');

        Route::redirect('/orders/new', '/seller/orders?status=new')
            ->name('orders.new');

        Route::redirect('/orders/to-prepare', '/seller/orders?status=to-prepare')
            ->name('orders.prepare');

        Route::redirect('/orders/ready-for-pickup', '/seller/orders?status=ready-pickup')
            ->name('orders.ready');

        Route::redirect('/orders/history', '/seller/orders?status=completed')
            ->name('orders.history');

        Route::get('/fulfillment/waybills', [SellerWorkflowController::class, 'waybills'])
            ->name('fulfillment.waybills');

        Route::get('/fulfillment/pickups', [SellerWorkflowController::class, 'pickupRequests'])
            ->name('fulfillment.pickups');

        Route::get('/fulfillment/tracking', [SellerWorkflowController::class, 'shipmentTracking'])
            ->name('fulfillment.tracking');

        Route::get('/finance/earnings', [SellerWorkflowController::class, 'financeEarnings'])
            ->name('finance.earnings');

        Route::get('/finance/transactions', [SellerWorkflowController::class, 'financeTransactions'])
            ->name('finance.transactions');

        Route::get('/store', [SellerController::class, 'store'])
            ->name('store');

        Route::post('/store', [SellerController::class, 'saveStore'])
            ->name('store.save');

        Route::get('/store/appearance', [SellerController::class, 'storeAppearance'])
            ->name('store.appearance');

        Route::get('/store/publication', [SellerController::class, 'publicationSettings'])
            ->name('store.publication');

        Route::get('/products', [SellerController::class, 'products'])
            ->name('products');

        Route::get('/inventory', [SellerController::class, 'inventory'])
            ->name('inventory');

        Route::get('/products/pricing', [SellerController::class, 'pricing'])
            ->name('products.pricing');

        Route::get('/products/pricing/create', [SellerController::class, 'createPromotion'])
            ->name('products.pricing.create');

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

        Route::get('/reports/sales', [SellerController::class, 'salesReport'])
            ->name('reports.sales');

        Route::get('/reports/financial', [SellerWorkflowController::class, 'financialReport'])
            ->name('reports.financial');

        Route::get('/support/messages', [SellerController::class, 'messages'])
            ->name('support.messages');

        Route::get('/support/feedback', [SellerController::class, 'customerFeedback'])
            ->name('support.feedback');

        Route::get('/settings/account', [SellerController::class, 'account'])
            ->name('settings.account');

        Route::get('/settings/security', [SellerController::class, 'security'])
            ->name('settings.security');

        Route::get('/settings/notifications', [SellerController::class, 'notificationSettings'])
            ->name('settings.notifications');
    });

/*
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
            ->middleware('throttle:10,1')->block(30, 5)->name('register.submit');
    });

    Route::redirect('/login', '/login')
        ->name('login');

    // Authenticated active Logistics accounts only.
    Route::middleware(['auth', 'role:logistics'])->group(function () {
        Route::get('/dashboard', [LogisticsController::class, 'dashboard'])
            ->name('dashboard');

        Route::get('/riders', [LogisticsController::class, 'riders'])
            ->name('riders.index');

        Route::get('/riders/{id}', [LogisticsController::class, 'showRider'])
            ->name('riders.show');

        Route::get('/pickups', [LogisticsController::class, 'pickups'])
            ->name('pickups.index');

        Route::get('/incoming', [LogisticsController::class, 'incoming'])
            ->name('sorting.incoming');

        Route::get('/sorting', [LogisticsController::class, 'sorting'])
            ->name('sorting.center');

        Route::get('/dispatch', [LogisticsController::class, 'dispatch'])
            ->name('dispatch.index');

        Route::get('/monitoring', [LogisticsController::class, 'monitoring'])
            ->name('dispatch.monitoring');

        Route::get('/reports', [LogisticsController::class, 'reports'])
            ->name('reports.index');

        Route::get('/messages', [LogisticsController::class, 'messages'])
            ->name('messages.index');

        Route::get('/account', [LogisticsController::class, 'account'])
            ->name('profile.index');

        Route::get('/api/waybills/{identifier}', [WaybillScanController::class, 'show'])
            ->name('waybills.lookup');

        Route::post('/api/waybills/{identifier}/receive', [WaybillScanController::class, 'receive'])
            ->name('waybills.receive');

        Route::post('/riders/{user}/approve', [AccountApprovalController::class, 'approveRider'])
            ->name('riders.approve');

        Route::post('/riders/{user}/reject', [AccountApprovalController::class, 'rejectRider'])
            ->name('riders.reject');
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

        Route::get('/dashboard/deliveries', [RiderController::class, 'deliveriesDashboard'])
            ->name('dashboard.deliveries');

        Route::get('/pickup/{id}', [RiderController::class, 'pickup'])
            ->name('orders.pickup');

        Route::post('/pickup/{id}/confirm', [RiderController::class, 'confirmPickup'])
            ->name('orders.pickup.confirm');

        Route::get('/deliver/{id}', [RiderController::class, 'deliver'])
            ->name('orders.delivery');

        Route::post('/deliver/{id}/confirm', [RiderController::class, 'confirmDelivery'])
            ->name('orders.delivery.confirm');

        Route::get('/earnings', [RiderController::class, 'earnings'])
            ->name('earnings.index');

        Route::get('/history', [RiderController::class, 'history'])
            ->name('history.index');

        Route::get('/messages', [RiderController::class, 'messages'])
            ->name('messages.index');

        Route::get('/account', [RiderController::class, 'account'])
            ->name('profile.index');
    });
});
