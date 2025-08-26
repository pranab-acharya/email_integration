<?php

use App\Http\Controllers\EmailOAuthController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('/auth/{provider}', [EmailOAuthController::class, 'connect']);
    Route::get('/auth/{provider}/callback', [EmailOAuthController::class, 'callback']);
});
