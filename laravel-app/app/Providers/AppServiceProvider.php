<?php

namespace App\Providers;

use App\Queue\SafeDatabaseUuidFailedJobProvider;
use Illuminate\Queue\Failed\DatabaseUuidFailedJobProvider;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->extend('queue.failer', function ($provider, $app) {
            if (! $provider instanceof DatabaseUuidFailedJobProvider) {
                return $provider;
            }

            return new SafeDatabaseUuidFailedJobProvider(
                $app['db'],
                $app['config']['queue.failed.database'],
                $app['config']['queue.failed.table'],
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
