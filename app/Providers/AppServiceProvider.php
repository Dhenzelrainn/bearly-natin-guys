<?php

namespace App\Providers;

use App\Models\AccountApplication;
use App\Models\Address;
use App\Models\Cart;
use App\Models\User;
use App\Observers\AccountApplicationObserver;
use App\Observers\AddressObserver;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Vite;
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
        // For the Cloudflare development hostname, always use compiled Vite assets.
        // Localhost can keep using the normal public/hot Vite dev server.
        if (
            ! $this->app->runningInConsole()
            && request()->getHost() === 'dev.bearlymarket.com'
        ) {
            Vite::useHotFile(storage_path('framework/vite-cloudflare-disabled.hot'));
        }

        Address::observe(AddressObserver::class);
        AccountApplication::observe(AccountApplicationObserver::class);

        View::composer('buyer.*', function ($view): void {
            $buyer = Auth::user();

            if (! $buyer instanceof User) {
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
            $buyerPhotoUrl = '';

            if ($buyer->profile_photo_path) {
                /** @var FilesystemAdapter $publicDisk */
                $publicDisk = Storage::disk('public');
                $buyerPhotoUrl = $publicDisk->url($buyer->profile_photo_path);
            }

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
                'photo' => $buyerPhotoUrl,
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
