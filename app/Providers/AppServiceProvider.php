<?php

namespace App\Providers;

use App\Enums\SellerOrderStatus;
use App\Enums\UserRole;
use App\Models\AccountApplication;
use App\Models\Address;
use App\Models\Cart;
use App\Models\Order;
use App\Models\User;
use App\Observers\AccountApplicationObserver;
use App\Observers\AddressObserver;
use App\Services\NavigationTaskCountService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
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
        // Keep repeated registration attempts bounded without making a low
        // shared-IP threshold block unrelated visitors behind the same NAT.
        RateLimiter::for('registration-submit', function (Request $request): array {
            $appKey = (string) config('app.key');
            $sessionId = $request->hasSession()
                ? $request->session()->getId()
                : 'no-session:'.$request->ip();
            $email = strtolower(trim((string) $request->input('email', '')));

            $limits = [
                Limit::perMinute(10)->by('session:'.hash_hmac('sha256', $sessionId, $appKey)),
                Limit::perMinute(60)->by('ip:'.$request->ip()),
            ];

            if ($email !== '') {
                $limits[] = Limit::perMinute(5)
                    ->by('email:'.hash_hmac('sha256', $email, $appKey));
            }

            return $limits;
        });

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
            $buyerInitials = collect(preg_split('/\s+/u', $buyerName) ?: [])
                ->filter()
                ->take(2)
                ->map(static fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))
                ->implode('');
            $buyerInitials = $buyerInitials !== '' ? $buyerInitials : 'BY';
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
                $activeCart = Cart::query()
                    ->where('user_id', $buyer->id)
                    ->where('status', 'active')
                    ->withCount('items')
                    ->latest('id')
                    ->first();
                $buyerCartCount = (int) ($activeCart?->items_count ?? 0);
            }

            $buyerProfilePayload = [
                'id' => $buyer->id,
                'username' => $buyerUsername,
                'fullName' => $buyerName,
                'initials' => $buyerInitials,
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

        View::composer('layouts.admin', function ($view): void {
            $user = Auth::user();

            if (! $user instanceof User) {
                return;
            }

            $admin = $view->getData()['admin'] ?? [];

            if (! is_array($admin)) {
                return;
            }

            $admin['photo_url'] = $user->profile_photo_path
                ? Storage::disk('public')->url($user->profile_photo_path)
                : '';

            $view->with('admin', $admin);
        });

        View::composer([
            'layouts.admin',
            'layouts.seller',
            'logistics.layouts.app',
            'rider.layouts.app',
        ], function ($view): void {
            $view->with(
                'navigationTaskCounts',
                app(NavigationTaskCountService::class)->forUser(Auth::user() instanceof User ? Auth::user() : null),
            );
        });

        View::composer('buyer.partials.buyer-header', function ($view): void {
            $buyer = Auth::user();
            $buyerActiveOrderCount = 0;

            if ($buyer instanceof User
                && $buyer->role === UserRole::Buyer->value
                && Schema::hasTable('orders')
                && Schema::hasTable('seller_orders')) {
                $buyerActiveOrderCount = Order::query()
                    ->where('buyer_id', $buyer->id)
                    ->where('status', '!=', 'cancelled')
                    ->whereHas('sellerOrders', fn ($sellerOrders) => $sellerOrders
                        ->whereNotIn('status', [
                            SellerOrderStatus::Completed->value,
                            SellerOrderStatus::Cancelled->value,
                        ]))
                    ->count();
            }

            $view->with('buyerActiveOrderCount', $buyerActiveOrderCount);
        });
    }
}
