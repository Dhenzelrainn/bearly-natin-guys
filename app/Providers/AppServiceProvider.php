<?php

namespace App\Providers;

use App\Observers\AccountApplicationObserver;

use App\Observers\AddressObserver;

use App\Models\AccountApplication;

use App\Models\Address;

use App\Models\Cart;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;

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

        View::composer('buyer.*', function ($view): void {
            $buyer = auth()->user();

            if (! $buyer) {
                return;
            }

            $buyerName = trim((string) ($buyer->name ?: trim(($buyer->first_name ?? '').' '.($buyer->last_name ?? ''))));
            $buyerName = $buyerName !== '' ? $buyerName : 'Buyer';
            $buyerEmail = (string) ($buyer->email ?? '');
            $buyerPhone = (string) ($buyer->phone ?: $buyer->contact_number ?: '');
            $buyerUsername = str_contains($buyerEmail, '@')
                ? (string) strstr($buyerEmail, '@', true)
                : strtolower(preg_replace('/\s+/', '', $buyerName));
            $buyerBirthday = $buyer->birthday ?? $buyer->birth_date;
            $buyerBirthdayValue = $buyerBirthday?->format('Y-m-d') ?? '';
            $buyerCartCount = 0;

            if (Schema::hasTable('carts') && Schema::hasTable('cart_items')) {
                $buyerCartCount = (int) Cart::query()
                    ->where('user_id', $buyer->id)
                    ->where('status', 'active')
                    ->withSum('items', 'quantity')
                    ->get()
                    ->sum('items_sum_quantity');
            }

            $buyerProfilePayload = [
                'id' => $buyer->id,
                'username' => $buyerUsername,
                'fullName' => $buyerName,
                'email' => $buyerEmail,
                'phone' => $buyerPhone,
                'gender' => (string) ($buyer->sex ?? ''),
                'birthday' => $buyerBirthdayValue,
                'photo' => $buyer->profile_photo_path
                    ? Storage::disk('public')->url($buyer->profile_photo_path)
                    : '',
                'cartCount' => $buyerCartCount,
                'notificationCount' => 0,
            ];

            $view->with(compact(
                'buyer',
                'buyerName',
                'buyerEmail',
                'buyerPhone',
                'buyerUsername',
                'buyerBirthdayValue',
                'buyerProfilePayload',
                'buyerCartCount'
            ));
        });
    }
}
