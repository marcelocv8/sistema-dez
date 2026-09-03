<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        Model::preventLazyLoading(! app()->isProduction());

        RateLimiter::for('public-api', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));
        RateLimiter::for('patient-management', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));
        RateLimiter::for('team-login', fn (Request $request) => Limit::perMinute(5)->by($request->ip().'|'.$request->string('email')));
        RateLimiter::for('team-api', fn (Request $request) => Limit::perMinute(120)->by((string) ($request->user()?->id ?? $request->ip())));
    }
}
