<?php

use App\Http\Controllers\EmailOAuthController;
use App\Http\Middleware\HandleMicrosoftWebhook;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('filament.admin.auth.login');
});

Route::middleware('auth')->group(function () {
    Route::get('auth/{provider}', [EmailOAuthController::class, 'connect']);
    Route::get('auth/{provider}/callback', [EmailOAuthController::class, 'callback']);
});

// Microsoft Graph webhook endpoints - no auth required
Route::match(['get', 'post'], 'outlook/notifications', [EmailOAuthController::class, 'handleNotification'])
    ->middleware(HandleMicrosoftWebhook::class)
    ->withoutMiddleware(['web'])
    ->name('outlook.notifications');


Route::get('/test-self-broadcast', function () {
    Notification::make()
        ->title('Self Broadcast Test')
        ->body('You just broadcast a notification to yourself!')
        ->info()
        ->broadcast(auth()->user());

    return 'Check your Filament admin - you should see a notification!';
})->middleware('auth');
