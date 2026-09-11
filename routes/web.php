<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\BuyerController;
use App\Http\Controllers\CourierController;
use App\Http\Controllers\PsgcController;
use App\Http\Controllers\SellerController;
use App\Http\Controllers\SellerProductController;
use App\Http\Controllers\SellerWorkflowController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public Landing / Authentication Routes
|--------------------------------------------------------------------------
*/
Route::view('/', 'landing-page.landingpage')->name('shop.home');
Route::redirect('/landing', '/')->name('landing');
Route::view('/about', 'landing-page.about.about')->name('about');
Route::view('/contact', 'landing-page.contact.contact')->name('contact');
Route::view('/login', 'auth.login')->name('login');
Route::view('/register', 'auth.register')->name('register');
Route::get('/forgot-password', fn () => redirect()->route('login'))->name('password.request');

/*
|--------------------------------------------------------------------------
| Buyer Front-End Routes
|--------------------------------------------------------------------------
*/
Route::get('/home', [BuyerController::class, 'home'])->name('home');
Route::get('/products', [BuyerController::class, 'products'])->name('products.index');
Route::get('/wishlist', [BuyerController::class, 'wishlist'])->name('wishlist.index');
Route::post('/wishlist/toggle', [BuyerController::class, 'toggleWishlist'])->name('wishlist.toggle');
Route::get('/cart', [BuyerController::class, 'cart'])->name('cart.view');
Route::post('/cart/add', [BuyerController::class, 'addToCart'])->name('cart.add');
Route::patch('/cart/{cartItem}', [BuyerController::class, 'updateCart'])->name('cart.update');
Route::delete('/cart/{cartItem}', [BuyerController::class, 'removeFromCart'])->name('cart.remove');
Route::delete('/cart', [BuyerController::class, 'clearCart'])->name('cart.clear');

Route::prefix('api/psgc')
    ->middleware('throttle:60,1')
    ->group(function () {
        Route::get('/provinces', [PsgcController::class, 'provinces'])->name('psgc.provinces');
        Route::get('/provinces/{provinceCode}/cities', [PsgcController::class, 'cities'])->name('psgc.cities');
        Route::get('/cities/{cityCode}/barangays', [PsgcController::class, 'barangays'])->name('psgc.barangays');
    });

/*
|--------------------------------------------------------------------------
| Admin Front-End Routes
|--------------------------------------------------------------------------
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
| Courier Front-End Routes
|--------------------------------------------------------------------------
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
| Seller Front-End Routes
|--------------------------------------------------------------------------
| Workflow-heavy pages use SellerWorkflowController so orders, fulfillment,
| returns, and seller earnings all read from the same preview data model.
| Product publishing uses SellerProductController so the seller's approved
| business category is enforced even before the real database is connected.
*/
Route::prefix('seller')->name('seller.')->group(function () {
    Route::redirect('/', '/seller/dashboard');

    Route::get('/dashboard', [SellerWorkflowController::class, 'dashboard'])->name('dashboard');

    Route::get('/orders', [SellerWorkflowController::class, 'orders'])->name('orders');
    Route::get('/orders/returns-refunds', [SellerWorkflowController::class, 'returns'])->name('orders.returns');
    Route::get('/orders/returns-refunds/{caseId}', [SellerWorkflowController::class, 'returnDetails'])->name('orders.returns.show');

    // Legacy URLs stay valid while the seller-facing tabs use simpler buckets.
    Route::redirect('/orders/new', '/seller/orders?status=new')->name('orders.new');
    Route::redirect('/orders/to-prepare', '/seller/orders?status=to-prepare')->name('orders.prepare');
    Route::redirect('/orders/ready-for-pickup', '/seller/orders?status=ready-pickup')->name('orders.ready');
    Route::redirect('/orders/history', '/seller/orders?status=completed')->name('orders.history');

    Route::get('/fulfillment/waybills', [SellerWorkflowController::class, 'waybills'])->name('fulfillment.waybills');
    Route::get('/fulfillment/pickups', [SellerWorkflowController::class, 'pickupRequests'])->name('fulfillment.pickups');
    Route::get('/fulfillment/tracking', [SellerWorkflowController::class, 'shipmentTracking'])->name('fulfillment.tracking');

    Route::get('/finance/earnings', [SellerWorkflowController::class, 'financeEarnings'])->name('finance.earnings');
    Route::get('/finance/transactions', [SellerWorkflowController::class, 'financeTransactions'])->name('finance.transactions');

    Route::get('/store', [SellerController::class, 'store'])->name('store');
    Route::post('/store', [SellerController::class, 'saveStore'])->name('store.save');
    Route::get('/store/appearance', [SellerController::class, 'storeAppearance'])->name('store.appearance');
    Route::get('/store/publication', [SellerController::class, 'publicationSettings'])->name('store.publication');

    Route::get('/products', [SellerController::class, 'products'])->name('products');
    Route::get('/inventory', [SellerController::class, 'inventory'])->name('inventory');
    Route::get('/products/pricing', [SellerController::class, 'pricing'])->name('products.pricing');

    Route::get('/products/create', [SellerProductController::class, 'createProduct'])->name('products.create');
    Route::post('/products', [SellerProductController::class, 'addProduct'])->name('products.add');
    Route::get('/products/{product}/edit', [SellerProductController::class, 'editProduct'])->name('products.edit');
    Route::put('/products/{product}', [SellerProductController::class, 'updateProduct'])->name('products.update');
    Route::patch('/products/{product}/archive', [SellerProductController::class, 'toggleProductArchive'])->name('products.archive');

    Route::get('/reports', [SellerWorkflowController::class, 'reportsOverview'])->name('reports');
    Route::get('/reports/sales', [SellerController::class, 'salesReport'])->name('reports.sales');
    Route::get('/reports/financial', [SellerWorkflowController::class, 'financialReport'])->name('reports.financial');

    Route::get('/support/messages', [SellerController::class, 'messages'])->name('support.messages');
    Route::get('/support/feedback', [SellerController::class, 'customerFeedback'])->name('support.feedback');

    Route::get('/settings/account', [SellerController::class, 'account'])->name('settings.account');
    Route::get('/settings/security', [SellerController::class, 'security'])->name('settings.security');
    Route::get('/settings/notifications', [SellerController::class, 'notificationSettings'])->name('settings.notifications');
});
