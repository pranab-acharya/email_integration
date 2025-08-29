<?php

namespace App\Http\Controllers;

use App\Filament\Resources\EmailAccountResource;
use App\Models\EmailAccount;
use Exception;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Laravel\Socialite\Facades\Socialite;

class EmailOAuthController extends Controller
{
    public function connect($provider)
    {
        if (! in_array($provider, ['google', 'microsoft'])) {
            return redirect()->back()->with('error', 'Invalid provider');
        }

        $scopes = $provider === 'google'
            ? ['https://www.googleapis.com/auth/gmail.send', 'https://www.googleapis.com/auth/gmail.readonly']
            : ['https://graph.microsoft.com/Mail.Send', 'https://graph.microsoft.com/Mail.ReadWrite'];

        /** @var \Laravel\Socialite\Two\AbstractProvider $driver */
        $driver = Socialite::driver($provider);

        if ($provider === 'google') {
            return $driver->scopes($scopes)->with([
                'access_type' => 'offline',
                'prompt' => 'consent',
            ])->redirect();
        }

        return $driver->scopes($scopes)->redirect();
    }

    public function callback($provider)
    {
        try {
            /** @var \Laravel\Socialite\Two\User $socialiteUser */
            $socialiteUser = Socialite::driver($provider)->stateless()->user();

            EmailAccount::updateOrCreate(
                [
                    'user_id' => Filament::auth()->user()->id,
                    'email' => $socialiteUser->getEmail(),
                    'provider' => $provider === 'microsoft' ? 'outlook' : $provider,
                ],
                [
                    'name' => $socialiteUser->getName(),
                    'access_token' => encrypt($socialiteUser->token),
                    'refresh_token' => $socialiteUser->refreshToken
                        ? encrypt($socialiteUser->refreshToken)
                        : null,
                    'expires_at' => $socialiteUser->expiresIn
                        ? now()->addSeconds($socialiteUser->expiresIn)
                        : null,
                ]
            );
            Notification::make()
                ->success()
                ->title('Email account connected successfully.')
                ->send();

            return redirect(EmailAccountResource::getUrl('index'));
        } catch (Exception $e) {
            return redirect(EmailAccountResource::getUrl('index'))->with('error', 'Connection failed: ' . $e->getMessage());
        }
    }
}
