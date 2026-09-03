<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\CourierController;
use App\Http\Controllers\PsgcController;
use App\Http\Controllers\SellerController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'auth.login')->name('shop.home');

Route::view('/login', 'auth.login')->name('login');
Route::view('/register', 'auth.register')->name('register');

Route::get('/forgot-password', fn () => redirect()->route('login'))->name('password.request');

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
    Route::get('/users', [AdminController::class, 'users'])->name('users');
    Route::get('/compliance', [AdminController::class, 'compliance'])->name('compliance');
    Route::get('/disputes', [AdminController::class, 'disputes'])->name('disputes');
    Route::get('/commissions', [AdminController::class, 'commissions'])->name('commissions');
    Route::get('/reports', [AdminController::class, 'reports'])->name('reports');
    Route::get('/settings', [AdminController::class, 'settings'])->name('settings');
    Route::get('/messages', [AdminController::class, 'messages'])->name('messages');
    Route::get('/account', [AdminController::class, 'account'])->name('account');
});


/*
|--------------------------------------------------------------------------
| Static Courier Front-End Routes
|--------------------------------------------------------------------------
| These preview screens remain public while the project is still focused on
| front-end design and layout work.
*/
Route::prefix('courier')->name('courier.')->group(function () {
    Route::redirect('/', '/courier/dashboard');

    Route::get('/register', [CourierController::class, 'register'])->name('register');
    Route::get('/pending', [CourierController::class, 'pending'])->name('pending');
    Route::get('/dashboard', [CourierController::class, 'dashboard'])->name('dashboard');
    Route::get('/requests', [CourierController::class, 'requests'])->name('requests');
    Route::get('/pickup', [CourierController::class, 'pickup'])->name('pickup');
    Route::get('/transit', [CourierController::class, 'transit'])->name('transit');
    Route::get('/complete', [CourierController::class, 'complete'])->name('complete');
    Route::get('/earnings', [CourierController::class, 'earnings'])->name('earnings');
    Route::get('/history', [CourierController::class, 'history'])->name('history');
    Route::get('/messages', [CourierController::class, 'messages'])->name('messages');
    Route::get('/account', [CourierController::class, 'account'])->name('account');
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
    Route::redirect('/orders', '/seller/orders/new')->name('orders');
    Route::get('/orders/new', [SellerController::class, 'workspace'])->defaults('workspace', 'orders-new')->name('orders.new');
    Route::get('/orders/to-prepare', [SellerController::class, 'workspace'])->defaults('workspace', 'orders-prepare')->name('orders.prepare');
    Route::get('/orders/ready-for-pickup', [SellerController::class, 'workspace'])->defaults('workspace', 'orders-ready')->name('orders.ready');
    Route::get('/orders/history', [SellerController::class, 'workspace'])->defaults('workspace', 'orders-history')->name('orders.history');
    Route::get('/fulfillment/waybills', [SellerController::class, 'workspace'])->defaults('workspace', 'fulfillment-waybills')->name('fulfillment.waybills');
    Route::get('/fulfillment/pickups', [SellerController::class, 'workspace'])->defaults('workspace', 'fulfillment-pickups')->name('fulfillment.pickups');
    Route::get('/fulfillment/tracking', [SellerController::class, 'workspace'])->defaults('workspace', 'fulfillment-tracking')->name('fulfillment.tracking');
    Route::get('/inventory', [SellerController::class, 'inventory'])->name('inventory');
    Route::get('/products/pricing', [SellerController::class, 'workspace'])->defaults('workspace', 'products-pricing')->name('products.pricing');
    Route::get('/store/appearance', [SellerController::class, 'workspace'])->defaults('workspace', 'store-appearance')->name('store.appearance');
    Route::get('/store/publication', [SellerController::class, 'workspace'])->defaults('workspace', 'store-publication')->name('store.publication');
    Route::get('/reports/sales', [SellerController::class, 'workspace'])->defaults('workspace', 'reports-sales')->name('reports.sales');
    Route::get('/reports/financial', [SellerController::class, 'workspace'])->defaults('workspace', 'reports-financial')->name('reports.financial');
    Route::get('/support/messages', [SellerController::class, 'workspace'])->defaults('workspace', 'support-messages')->name('support.messages');
    Route::get('/support/feedback', [SellerController::class, 'workspace'])->defaults('workspace', 'support-feedback')->name('support.feedback');
    Route::get('/settings/account', [SellerController::class, 'account'])->name('settings.account');
    Route::get('/settings/security', [SellerController::class, 'security'])->name('settings.security');
    Route::get('/settings/notifications', [SellerController::class, 'notificationSettings'])->name('settings.notifications');
    Route::get('/products/create', [SellerController::class, 'createProduct'])->name('products.create');
    Route::post('/products', [SellerController::class, 'addProduct'])->name('products.add');
    Route::get('/products/{product}/edit', [SellerController::class, 'editProduct'])->name('products.edit');
    Route::put('/products/{product}', [SellerController::class, 'updateProduct'])->name('products.update');
    Route::patch('/products/{product}/archive', [SellerController::class, 'toggleProductArchive'])->name('products.archive');
});
