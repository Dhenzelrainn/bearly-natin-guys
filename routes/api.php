<?php

use App\Http\Controllers\Api\MobileAuthController;
use App\Http\Controllers\Api\MobileRegistrationController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/mobile/login', [
    MobileAuthController::class,
    'login',
]);

Route::post('/mobile/logout', [
    MobileAuthController::class,
    'logout',
])->middleware('auth:sanctum');

Route::post('/mobile/register/email/send', [
    MobileRegistrationController::class,
    'sendEmailCode',
]);

Route::post('/mobile/register/email/verify', [
    MobileRegistrationController::class,
    'verifyEmailCode',
]);

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');