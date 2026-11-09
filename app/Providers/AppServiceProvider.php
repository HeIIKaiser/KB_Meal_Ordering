<?php

namespace App\Providers;

use App\Http\Middleware\EnsureEmployeeIsActive;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Middleware\TrustProxies;
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

        if ($proxies = config('jedalen.trusted_proxies')) {
            TrustProxies::at($proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }

        if ($this->app->isProduction()) {
            URL::forceHttps();
        }
    }
}
