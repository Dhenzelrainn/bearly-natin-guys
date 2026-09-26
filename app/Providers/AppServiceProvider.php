<?php

namespace App\Providers;

use App\Observers\AccountApplicationObserver;

use App\Observers\AddressObserver;

use App\Models\AccountApplication;

use App\Models\Address;

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
        Address::observe(AddressObserver::class);
        AccountApplication::observe(AccountApplicationObserver::class);
        //
    }
}
