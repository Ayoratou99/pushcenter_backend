<?php

namespace App\Providers;

use App\Auth\JwtGuard;
use App\Services\JwtService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(JwtService::class);
    }

    public function boot(): void
    {
        Auth::extend('jwt', function ($app, $name, array $config) {
            $guard = new JwtGuard(
                Auth::createUserProvider($config['provider']),
                $app['request'],
                $app->make(JwtService::class),
            );

            // The guard is resolved once but the container's request is rebound
            // on every request; without this the first Bearer token would stick.
            $app->refresh('request', $guard, 'setRequest');

            return $guard;
        });
    }
}
