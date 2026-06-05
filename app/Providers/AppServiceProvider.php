<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        ResetPassword::createUrlUsing(function ($user, string $token) {

            $frontendUrl = config('app.frontend_url');

            return "{$frontendUrl}/reset-password?token={$token}&email={$user->email}";
        });

        VerifyEmail::toMailUsing(function ($notifiable, string $url) {
            return (new MailMessage)
                ->subject('Verifica tu correo electrónico')
                ->view('emails.email-verification', [
                    'user' => $notifiable,
                    'verificationUrl' => $url,
                    'logoUrl' => config('app.company_logo_url') ?: asset('server-icon.png'),
                ]);
        });
    }
}
