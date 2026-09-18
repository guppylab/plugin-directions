<?php

namespace Guppylab\Directions;

use Illuminate\Support\ServiceProvider;

class DirectionsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/directions.php', 'directions');

        $this->app->singleton(Directions::class, fn () => new Directions);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/directions.php' => config_path('directions.php'),
            ], 'directions-config');
        }
    }
}
