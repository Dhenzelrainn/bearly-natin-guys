<?php

use App\Http\Controllers\Api\MobileAuthController;
use App\Http\Controllers\Api\MobileBuyerCatalogController;
use App\Http\Controllers\Api\MobileBuyerWishlistController;
use App\Http\Controllers\Api\MobileRegistrationController;
use App\Http\Controllers\BuyerController;
use App\Http\Middleware\EnsureMobileAccountIsEligible;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/mobile/login', [
    MobileAuthController::class,
    'login',
]);

Route::post('/mobile/logout', [
    MobileAuthController::class,
    'logout',
])->middleware(['auth:sanctum', EnsureMobileAccountIsEligible::class]);

Route::get('/mobile/me', [
    MobileAuthController::class,
    'me',
])->middleware(['auth:sanctum', EnsureMobileAccountIsEligible::class]);

Route::post('/mobile/register/email/send', [
    MobileRegistrationController::class,
    'sendEmailCode',
]);

Route::post('/mobile/register/email/verify', [
    MobileRegistrationController::class,
    'verifyEmailCode',
]);

Route::post('/mobile/register/buyer', [
    MobileRegistrationController::class,
    'registerBuyer',
]);

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware(['auth:sanctum', EnsureMobileAccountIsEligible::class]);

Route::prefix('/mobile/buyer')
    ->middleware(['auth:sanctum', EnsureMobileAccountIsEligible::class, 'role:buyer'])
    ->name('mobile.buyer.')
    ->group(function (): void {
        Route::get('/products', [MobileBuyerCatalogController::class, 'index'])
            ->name('products.index');
        Route::get('/products/{product}', [MobileBuyerCatalogController::class, 'show'])
            ->name('products.show');
        Route::get('/categories', [MobileBuyerCatalogController::class, 'categories'])
            ->name('categories.index');
        Route::get('/wishlist', [MobileBuyerWishlistController::class, 'index'])
            ->middleware('throttle:60,1')
            ->name('wishlist.index');
        Route::put('/wishlist/{product}', [MobileBuyerWishlistController::class, 'store'])
            ->whereNumber('product')
            ->middleware('throttle:60,1')
            ->name('wishlist.store');
        Route::delete('/wishlist/{product}', [MobileBuyerWishlistController::class, 'destroy'])
            ->whereNumber('product')
            ->middleware('throttle:60,1')
            ->name('wishlist.destroy');
        Route::get('/addresses', [BuyerController::class, 'addressData'])
            ->middleware('throttle:60,1')
            ->name('addresses.index');
        Route::post('/addresses', [BuyerController::class, 'storeAddress'])
            ->middleware('throttle:60,1')
            ->name('addresses.store');
        Route::patch('/addresses/{address}', [BuyerController::class, 'updateMobileAddress'])
            ->whereNumber('address')
            ->middleware('throttle:60,1')
            ->name('addresses.update');
        Route::delete('/addresses/{address}', [BuyerController::class, 'deleteMobileAddress'])
            ->whereNumber('address')
            ->middleware('throttle:60,1')
            ->name('addresses.destroy');
        Route::patch('/addresses/{address}/default', [BuyerController::class, 'setMobileDefaultAddress'])
            ->whereNumber('address')
            ->middleware('throttle:60,1')
            ->name('addresses.default');
        Route::get('/cart', [BuyerController::class, 'cartData'])
            ->middleware('throttle:60,1')
            ->name('cart.index');
        Route::post('/cart/items', [BuyerController::class, 'addToCart'])
            ->middleware('throttle:60,1')
            ->name('cart.items.store');
        Route::patch('/cart/items/{cartItem}', [BuyerController::class, 'updateCart'])
            ->whereNumber('cartItem')
            ->middleware('throttle:60,1')
            ->name('cart.items.update');
        Route::delete('/cart/items/{cartItem}', [BuyerController::class, 'removeFromCart'])
            ->whereNumber('cartItem')
            ->middleware('throttle:60,1')
            ->name('cart.items.destroy');
    });
