<?php

namespace App\Http\Controllers;

use App\Filament\Resources\EmailAccountResource;
use App\Models\EmailAccount;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;

class EmailOAuthController extends Controller
{
    public function connect($provider)
    {
        if (!in_array($provider, ['google', 'microsoft'])) {
            return redirect()->back()->with('error', 'Invalid provider');
        }

        $scopes = $provider === 'google'
            ? ['https://www.googleapis.com/auth/gmail.send', 'https://www.googleapis.com/auth/gmail.readonly']
            : ['https://graph.microsoft.com/Mail.Send', 'https://graph.microsoft.com/Mail.ReadWrite'];

        /** @var \Laravel\Socialite\Two\AbstractProvider $driver */
        $driver = Socialite::driver($provider);

        return $driver->scopes($scopes)->redirect();
    }

    public function callback($provider)
    {
        try {
            /** @var \Laravel\Socialite\Two\User $socialiteUser */
            $socialiteUser = Socialite::driver($provider)->stateless()->user();

            EmailAccount::updateOrCreate(
                [
                    'user_id'  => Filament::auth()->user()->id,
                    'email'    => $socialiteUser->getEmail(),
                    'provider' => $provider === 'microsoft' ? 'outlook' : $provider,
                ],
                [
                    'name'          => $socialiteUser->getName(),
                    'access_token'  => encrypt($socialiteUser->token),
                    'refresh_token' => $socialiteUser->refreshToken
                        ? encrypt($socialiteUser->refreshToken)
                        : null,
                    'expires_at'    => $socialiteUser->expiresIn
                        ? now()->addSeconds($socialiteUser->expiresIn)
                        : null,
                ]
            );

            return redirect(EmailAccountResource::getUrl('index'))
                ->with('success', 'Email account connected!');
        } catch (\Exception $e) {
            return redirect(EmailAccountResource::getUrl('index'))->with('error', 'Connection failed: ' . $e->getMessage());
        }
    }
}
