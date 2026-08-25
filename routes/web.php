<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\CourierController;
use App\Http\Controllers\PsgcController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login')->name('shop.home');

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
| No auth/database middleware is applied yet so the team can preview all
| admin screens while the project is still in its front-end phase.
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
| No auth/database middleware is applied yet so the team can preview all
| courier screens while the project is still in its front-end phase.
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
