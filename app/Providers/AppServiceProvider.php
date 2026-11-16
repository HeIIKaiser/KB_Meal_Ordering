<?php

namespace App\Providers;

use App\Http\Middleware\EnsureEmployeeIsActive;
use App\Support\Settings;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(Settings::class);
    }

    public function boot(): void
    {
        // Catch lazy loading (N+1), silently discarded attributes etc. outside production.
        Model::shouldBeStrict(! $this->app->isProduction());

        // Re-applied on every Livewire update request of the employee screen.
        Livewire::addPersistentMiddleware([EnsureEmployeeIsActive::class]);

        // Attendance bridge: one push per month + a poll every 5 minutes; plenty of headroom.
        RateLimiter::for('attendance-sync', fn (Request $request) => Limit::perMinute(30)->by((string) $request->ip()));

        if ($proxies = config('jedalen.trusted_proxies')) {
            TrustProxies::at($proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }

        if ($this->app->isProduction()) {
            URL::forceHttps();
        }
    }
}
