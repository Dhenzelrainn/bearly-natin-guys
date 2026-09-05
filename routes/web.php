<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\BuyerController;
use App\Http\Controllers\CourierController;
use App\Http\Controllers\PsgcController;
use App\Http\Controllers\SellerController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'auth.login')->name('shop.home');

Route::view('/login', 'auth.login')->name('login');
Route::view('/register', 'auth.register')->name('register');

Route::get('/home', [BuyerController::class, 'home'])->name('home');
Route::get('/products', [BuyerController::class, 'products'])->name('products.index');
Route::get('/wishlist', [BuyerController::class, 'wishlist'])->name('wishlist.index');
Route::post('/wishlist/toggle', [BuyerController::class, 'toggleWishlist'])->name('wishlist.toggle');
Route::get('/cart', [BuyerController::class, 'cart'])->name('cart.view');
Route::post('/cart/add', [BuyerController::class, 'addToCart'])->name('cart.add');
Route::patch('/cart/{cartItem}', [BuyerController::class, 'updateCart'])->name('cart.update');
Route::delete('/cart/{cartItem}', [BuyerController::class, 'removeFromCart'])->name('cart.remove');
Route::delete('/cart', [BuyerController::class, 'clearCart'])->name('cart.clear');

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

Route::middleware('auth')->prefix('admin')->name('admin.')->group(function () {
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

Route::prefix('seller')->name('seller.')->group(function () {
    Route::redirect('/', '/seller/dashboard');
    Route::get('/dashboard', [SellerController::class, 'dashboard'])->name('dashboard');
    Route::get('/store', [SellerController::class, 'store'])->name('store');
    Route::post('/store', [SellerController::class, 'saveStore'])->name('store.save');
    Route::get('/products', [SellerController::class, 'products'])->name('products');
    Route::get('/orders', [SellerController::class, 'orders'])->name('orders');
    Route::get('/inventory', [SellerController::class, 'inventory'])->name('inventory');
    Route::get('/products/create', [SellerController::class, 'createProduct'])->name('products.create');
    Route::post('/products', [SellerController::class, 'addProduct'])->name('products.add');
    Route::get('/products/{product}/edit', [SellerController::class, 'editProduct'])->name('products.edit');
    Route::put('/products/{product}', [SellerController::class, 'updateProduct'])->name('products.update');
    Route::patch('/products/{product}/archive', [SellerController::class, 'toggleProductArchive'])->name('products.archive');
});
