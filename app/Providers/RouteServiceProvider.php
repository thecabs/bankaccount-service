<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    public const HOME = '/home';

    public function boot(): void
    {
        $this->configureRateLimiting();

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }

    protected function configureRateLimiting(): void
    {
        // Limite générique API
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->ip());
        });

        // Limiteur nommé utilisé pour les écritures bankaccount (claim/store/link)
        RateLimiter::for('bank-write', function (Request $request) {
            // Clé : utilisateur si connu, sinon IP
            $key = $request->attributes->get('external_id')
                ?? (string) $request->user()?->id
                ?? $request->ip();

            return Limit::perMinute(10)->by($key)->response(function () {
                return response()->json(['error' => 'Too Many Requests'], 429);
            });
        });
    }
}
