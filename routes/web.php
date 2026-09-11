<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\PsgcController;
use App\Http\Controllers\SellerController;
use App\Http\Controllers\LogisticsController;
use App\Http\Controllers\RiderController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'auth.login')->name('shop.home');
Route::view('/login', 'auth.login')->name('login');
Route::view('/register', 'auth.register')->name('register');

Route::get('/forgot-password', fn () => redirect()->route('login'))
    ->name('password.request');

/*
|--------------------------------------------------------------------------
| PSGC API Routes
|--------------------------------------------------------------------------
*/

Route::prefix('api/psgc')
    ->middleware('throttle:60,1')
    ->group(function () {
        Route::get('/provinces', [PsgcController::class, 'provinces'])
            ->name('psgc.provinces');

        Route::get(
            '/provinces/{provinceCode}/cities',
            [PsgcController::class, 'cities']
        )->name('psgc.cities');

        Route::get(
            '/cities/{cityCode}/barangays',
            [PsgcController::class, 'barangays']
        )->name('psgc.barangays');
    });

/*
|--------------------------------------------------------------------------
| Static Admin Front-End Routes
|--------------------------------------------------------------------------
| These preview screens remain publicly accessible while the UI is still in
| the front-end stage and backend authentication is not yet implemented.
*/

Route::prefix('admin')->name('admin.')->group(function () {
    Route::redirect('/', '/admin/dashboard');

    Route::get('/dashboard', [AdminController::class, 'dashboard'])->name('dashboard');
    
    Route::get('/registrations', [AdminController::class, 'registrations'])->name('registrations');
    Route::get('/registrations/buyers', [AdminController::class, 'buyerApplications'])
        ->name('registrations.buyers');
    
    Route::get('/registrations/sellers', [AdminController::class, 'sellerApplications'])
        ->name('registrations.sellers');
    
    Route::get('/registrations/logistics', [AdminController::class, 'logisticsApplications'])
        ->name('registrations.logistics');
    
    Route::get('/users', [AdminController::class, 'users'])->name('users');
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

    Route::get('/commissions', [AdminController::class, 'commissions'])->name('commissions');
    Route::get('/reports', [AdminController::class, 'reports'])->name('reports');
    
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
    
    Route::get('/account', [AdminController::class, 'account'])->name('account');
});

/*
|--------------------------------------------------------------------------
| Static Seller Front-End Routes
|--------------------------------------------------------------------------
| These preview screens remain public so the team can continue working on the
| seller interface before backend authentication is implemented.
*/

Route::prefix('seller')->name('seller.')->group(function () {
    Route::redirect('/', '/seller/dashboard');

    Route::get('/dashboard', [SellerController::class, 'dashboard'])->name('dashboard');

    Route::get('/store', [SellerController::class, 'store'])->name('store');
    Route::post('/store', [SellerController::class, 'saveStore'])->name('store.save');

    Route::get('/products', [SellerController::class, 'products'])->name('products');
    Route::get('/products/create', [SellerController::class, 'createProduct'])->name('products.create');
    Route::post('/products', [SellerController::class, 'addProduct'])->name('products.add');
    Route::get('/products/{product}/edit', [SellerController::class, 'editProduct'])->name('products.edit');
    Route::put('/products/{product}', [SellerController::class, 'updateProduct'])->name('products.update');
    Route::patch('/products/{product}/archive', [SellerController::class, 'toggleProductArchive'])->name('products.archive');
    Route::get('/products/pricing', [SellerController::class, 'pricing'])->name('products.pricing');

    Route::get('/orders', [SellerController::class, 'orders'])->name('orders');
    Route::get('/orders/returns-refunds', [SellerController::class, 'returns'])->name('orders.returns');
    Route::get('/orders/returns-refunds/{caseId}', [SellerController::class, 'returnDetails'])->name('orders.returns.show');

    // Preserve old Seller Center URLs while statuses move to horizontal tabs.
    Route::redirect('/orders/new', '/seller/orders?status=new')->name('orders.new');
    Route::redirect('/orders/to-prepare', '/seller/orders?status=to-prepare')->name('orders.prepare');
    Route::redirect('/orders/ready-for-pickup', '/seller/orders?status=ready-pickup')->name('orders.ready');
    Route::redirect('/orders/history', '/seller/orders?status=history')->name('orders.history');

    Route::get('/fulfillment/waybills', [SellerController::class, 'waybills'])->name('fulfillment.waybills');
    Route::get('/fulfillment/pickups', [SellerController::class, 'pickupRequests'])->name('fulfillment.pickups');
    Route::get('/fulfillment/tracking', [SellerController::class, 'shipmentTracking'])->name('fulfillment.tracking');

    Route::get('/inventory', [SellerController::class, 'inventory'])->name('inventory');

    Route::get('/store/appearance', [SellerController::class, 'storeAppearance'])->name('store.appearance');
    Route::get('/store/publication', [SellerController::class, 'publicationSettings'])->name('store.publication');

    Route::get('/reports', [SellerController::class, 'reports'])->name('reports');
    Route::get('/reports/sales', [SellerController::class, 'salesReport'])->name('reports.sales');
    Route::get('/reports/financial', [SellerController::class, 'financialReport'])->name('reports.financial');

    Route::get('/support/messages', [SellerController::class, 'messages'])->name('support.messages');
    Route::get('/support/feedback', [SellerController::class, 'customerFeedback'])->name('support.feedback');

    Route::get('/settings/account', [SellerController::class, 'account'])->name('settings.account');
    Route::get('/settings/security', [SellerController::class, 'security'])->name('settings.security');
    Route::get('/settings/notifications', [SellerController::class, 'notificationSettings'])->name('settings.notifications');
});

/*
|--------------------------------------------------------------------------
| Logistics / Sorting Center Front-End Routes
|--------------------------------------------------------------------------
*/

Route::prefix('logistics')->name('logistics.')->group(function () {
    Route::get('/', [LogisticsController::class, 'landing'])->name('landing');

    Route::get('/register', [LogisticsController::class, 'register'])->name('register');
    Route::post('/register', [LogisticsController::class, 'submitRegistration'])->name('register.submit');

    Route::get('/login', [LogisticsController::class, 'login'])->name('login');
    Route::post('/login', [LogisticsController::class, 'submitLogin'])->name('login.submit');

    Route::get('/dashboard', [LogisticsController::class, 'dashboard'])->name('dashboard');
    Route::get('/riders', [LogisticsController::class, 'riders'])->name('riders');
    Route::get('/pickups', [LogisticsController::class, 'pickups'])->name('pickups');
    Route::get('/sorting', [LogisticsController::class, 'sorting'])->name('sorting');
    Route::get('/dispatch', [LogisticsController::class, 'dispatch'])->name('dispatch');
    Route::get('/monitoring', [LogisticsController::class, 'monitoring'])->name('monitoring');
    Route::get('/reports', [LogisticsController::class, 'reports'])->name('reports');
    Route::get('/messages', [LogisticsController::class, 'messages'])->name('messages');
    Route::get('/account', [LogisticsController::class, 'account'])->name('account');
});

/*
|--------------------------------------------------------------------------
| Rider Front-End Routes
|--------------------------------------------------------------------------
*/

Route::prefix('rider')->name('rider.')->group(function () {
    Route::get('/', [RiderController::class, 'landing'])->name('landing');

    Route::get('/register', [RiderController::class, 'register'])->name('register');
    Route::post('/register', [RiderController::class, 'submitRegistration'])->name('register.submit');

    Route::get('/login', [RiderController::class, 'login'])->name('login');
    Route::post('/login', [RiderController::class, 'submitLogin'])->name('login.submit');

    Route::get('/dashboard/pickups', [RiderController::class, 'pickupsDashboard'])->name('dashboard.pickups');
    Route::get('/dashboard/deliveries', [RiderController::class, 'deliveriesDashboard'])->name('dashboard.deliveries');

    Route::get('/pickup/{id}', [RiderController::class, 'pickup'])->name('pickup');
    Route::post('/pickup/{id}/confirm', [RiderController::class, 'confirmPickup'])->name('pickup.confirm');

    Route::get('/deliver/{id}', [RiderController::class, 'deliver'])->name('deliver');
    Route::post('/deliver/{id}/confirm', [RiderController::class, 'confirmDelivery'])->name('deliver.confirm');

    Route::get('/earnings', [RiderController::class, 'earnings'])->name('earnings');
    Route::get('/history', [RiderController::class, 'history'])->name('history');
    Route::get('/messages', [RiderController::class, 'messages'])->name('messages');
    Route::get('/account', [RiderController::class, 'account'])->name('account');
});

