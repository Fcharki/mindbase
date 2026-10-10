<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use App\Logging\RequestContextProcessor;

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
        try {
            $log = app('log');
            if (method_exists($log, 'getLogger')) {
                $logger = $log->getLogger();
                if (method_exists($logger, 'getHandlers')) {
                    foreach ($logger->getHandlers() as $handler) {
                        if (method_exists($handler, 'pushProcessor')) {
                            $handler->pushProcessor(new RequestContextProcessor());
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // ignore
        }

        VerifyEmail::createUrlUsing(function ($notifiable) {
            return URL::temporarySignedRoute(
                'auth.verification.verify',
                now()->addMinutes(60),
                [
                    'id' => $notifiable->getKey(),
                    'hash' => sha1($notifiable->getEmailForVerification()),
                ]
            );
        });
    }
}
