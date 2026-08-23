<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PsgcController;

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