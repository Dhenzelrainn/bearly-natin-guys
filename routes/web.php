<?php

use App\Http\Controllers\PsgcController;
use App\Http\Controllers\SellerController;
use App\Http\Controllers\SellerProductController;
use App\Http\Controllers\SellerWorkflowController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
*/

Route::view('/', 'auth.login')->name('shop.home');
Route::view('/login', 'auth.login')->name('login');
Route::view('/register', 'auth.register')->name('register');

Route::get('/forgot-password', fn () => redirect()->route('login'))
    ->name('password.request');

/*
|--------------------------------------------------------------------------
| PSGC Address API
|--------------------------------------------------------------------------
*/

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
| Seller
|--------------------------------------------------------------------------
*/

Route::prefix('seller')->name('seller.')->group(function () {
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