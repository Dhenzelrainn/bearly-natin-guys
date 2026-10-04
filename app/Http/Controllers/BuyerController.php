<?php

namespace App\Http\Controllers;

use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Wishlist;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class BuyerController extends Controller
{
    private function hasBuyerTables(): bool
    {
        return Schema::hasTable('products')
            && Schema::hasTable('stores')
            && Schema::hasTable('product_variants')
            && Schema::hasTable('carts')
            && Schema::hasTable('cart_items')
            && Schema::hasTable('wishlists');
    }

    public function profile(): View
    {
        return view('buyer.profile');
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'full_name' => ['required', 'string', 'max:160'],
            'phone' => ['nullable', 'string', 'max:30'],
            'gender' => ['nullable', 'string', 'in:Male,Female,Other,male,female,prefer_not_to_say'],
            'birthday' => ['nullable', 'date', 'before:today'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'remove_photo' => ['nullable', 'boolean'],
        ]);

        $user = $request->user();
        $parts = preg_split('/\s+/', trim($validated['full_name'])) ?: [];
        $firstName = trim((string) array_shift($parts));
        $lastName = trim(implode(' ', $parts));

        if ($lastName === '') {
            $lastName = (string) ($user->last_name ?: $firstName);
        }

        $gender = match (strtolower((string) ($validated['gender'] ?? ''))) {
            'male' => 'male',
            'female' => 'female',
            'other', 'prefer_not_to_say' => 'prefer_not_to_say',
            default => null,
        };

        $phone = $this->normalizePhone($validated['phone'] ?? null);

        $oldPhoto = $user->profile_photo_path;

        if ($request->hasFile('photo') && ! $request->boolean('remove_photo')) {
            $newPhoto = $request->file('photo')->store('profile-photos', 'public');
        } else {
            $newPhoto = $oldPhoto;
        }

        if ($request->boolean('remove_photo')) {
            $newPhoto = null;
        }

        $user->forceFill([
            'name' => trim($firstName.' '.$lastName),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'sex' => $gender,
            'birthday' => $validated['birthday'] ?? null,
            'birth_date' => $validated['birthday'] ?? null,
            'phone' => $phone !== '' ? $phone : null,
            'contact_number' => $phone !== '' ? $phone : null,
            'profile_photo_path' => $newPhoto,
        ])->save();

        if ($oldPhoto && $oldPhoto !== $newPhoto) {
            Storage::disk('public')->delete($oldPhoto);
        }

        return response()->json([
            'data' => [
                'username' => $this->profileUsername($user),
                'fullName' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone ?: $user->contact_number,
                'gender' => $user->sex,
                'birthday' => optional($user->birthday)->format('Y-m-d'),
                'photo' => $user->profile_photo_path
                    ? Storage::disk('public')->url($user->profile_photo_path)
                    : '',
            ],
            'message' => 'Profile updated successfully.',
        ]);
    }

    private function profileUsername($user): string
    {
        return str_contains((string) $user->email, '@')
            ? (string) strstr((string) $user->email, '@', true)
            : strtolower(preg_replace('/\s+/', '', (string) $user->name));
    }

    public function addresses(): View
    {
        return view('buyer.addresses');
    }

    public function addressData(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $request->user()->addresses()
                ->orderByDesc('is_default_shipping')
                ->latest('id')
                ->get()
                ->map(fn (Address $address) => $this->addressPayload($address))
                ->values(),
            'message' => 'Addresses retrieved successfully.',
        ]);
    }

    public function storeAddress(Request $request): JsonResponse
    {
        $data = $this->validateAddress($request);

        $address = DB::transaction(function () use ($request, $data) {
            $user = $request->user();
            $phone = $this->normalizePhone($data['phone']);
            $makeDefault = (bool) $data['is_default'] || ! $user->addresses()->exists();

            if ($makeDefault) {
                $user->addresses()->update(['is_default_shipping' => false]);
            }

            return $user->addresses()->create([
                'label' => $data['label'],
                'recipient_name' => $data['name'],
                'phone' => $phone,
                'house_number' => null,
                'street' => $data['street'],
                'barangay' => $data['barangay'],
                'city_municipality' => $data['city'],
                'province' => $data['province'],
                'postal_code' => $data['postal'],
                'psgc_city_code' => $data['city_code'] ?? null,
                'is_default_shipping' => $makeDefault,
            ]);
        });

        return response()->json([
            'data' => $this->addressPayload($address),
            'message' => 'Address added successfully.',
        ], 201);
    }

    public function updateAddress(Request $request, Address $address): JsonResponse
    {
        abort_unless((int) $address->user_id === (int) $request->user()->id, 404);
        $data = $this->validateAddress($request);

        $address = DB::transaction(function () use ($request, $address, $data) {
            $user = $request->user();
            $makeDefault = (bool) $data['is_default']
                || $address->is_default_shipping
                || ! $user->addresses()->where('is_default_shipping', true)->exists();

            if ($makeDefault) {
                $user->addresses()
                    ->where('id', '!=', $address->id)
                    ->update(['is_default_shipping' => false]);
            }

            $phone = $this->normalizePhone($data['phone']);

            $address->update([
                'label' => $data['label'],
                'recipient_name' => $data['name'],
                'phone' => $phone,
                'street' => $data['street'],
                'barangay' => $data['barangay'],
                'city_municipality' => $data['city'],
                'province' => $data['province'],
                'postal_code' => $data['postal'],
                'psgc_city_code' => $data['city_code'] ?? null,
                'is_default_shipping' => $makeDefault,
            ]);

            return $address->fresh();
        });

        return response()->json([
            'data' => $this->addressPayload($address),
            'message' => 'Address updated successfully.',
        ]);
    }

    public function deleteAddress(Request $request, Address $address): JsonResponse
    {
        abort_unless((int) $address->user_id === (int) $request->user()->id, 404);

        DB::transaction(function () use ($request, $address) {
            $wasDefault = (bool) $address->is_default_shipping;
            $address->delete();

            if ($wasDefault) {
                $request->user()->addresses()
                    ->orderBy('id')
                    ->first()
                    ?->update(['is_default_shipping' => true]);
            }
        });

        return response()->json([
            'data' => null,
            'message' => 'Address deleted successfully.',
        ]);
    }

    public function setDefaultAddress(Request $request, Address $address): JsonResponse
    {
        abort_unless((int) $address->user_id === (int) $request->user()->id, 404);

        DB::transaction(function () use ($request, $address) {
            $request->user()->addresses()->update(['is_default_shipping' => false]);
            $address->update(['is_default_shipping' => true]);
        });

        return response()->json([
            'data' => $this->addressPayload($address->fresh()),
            'message' => 'Default address updated successfully.',
        ]);
    }

    private function validateAddress(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'phone' => ['required', 'string', 'max:30', 'regex:/^\+?[0-9\s\-()]{7,30}$/'],
            'province' => ['required', 'string', 'max:120'],
            'city' => ['required', 'string', 'max:120'],
            'barangay' => ['required', 'string', 'max:120'],
            'postal' => ['required', 'digits:4'],
            'street' => ['required', 'string', 'max:180'],
            'label' => ['required', 'string', 'in:Home,Work'],
            'is_default' => ['nullable', 'boolean'],
            'city_code' => ['nullable', 'regex:/^[0-9]{6,10}$/D'],
        ]);
    }

    private function normalizePhone(?string $phone): ?string
    {
        $phone = trim((string) $phone);

        if ($phone === '') {
            return null;
        }

        $phone = preg_replace('/[^0-9+]/', '', $phone) ?: '';

        return app(\App\Services\InternationalPhone::class)
            ->normalize($phone, 'PH');
    }

    private function addressPayload(Address $address): array
    {
        return [
            'id' => $address->id,
            'label' => $address->label,
            'name' => $address->recipient_name,
            'phone' => $address->phone,
            'province' => $address->province,
            'city' => $address->city_municipality,
            'barangay' => $address->barangay,
            'postal' => $address->postal_code,
            'street' => $address->street,
            'isDefault' => (bool) $address->is_default_shipping,
        ];
    }

    public function home(Request $request): View|RedirectResponse
    {
        $dedicatedCategories = [
            'electronics-and-gadgets',
            'books-and-media',
            'pet-supplies',
            'sports-and-outdoors',
            'jewelry-and-watches',
            'kids-and-baby',
            'home-and-garden',
            'health-and-beauty',
            'food-and-gourmet',
            'furniture-and-office-equipment',
        ];

        if (in_array($request->query('category'), $dedicatedCategories, true)) {
            return redirect()->route('products.index', ['category' => $request->query('category')]);
        }

        $categories = [
            [
                'name' => 'Electronics',
                'icon' => '💻',
                'count' => '12.4k',
            ],
            [
                'name' => 'Fashion',
                'icon' => '👟',
                'count' => '8.9k',
            ],
            [
                'name' => 'Home & Living',
                'icon' => '🛋️',
                'count' => '5.6k',
            ],
            [
                'name' => 'Beauty',
                'icon' => '✨',
                'count' => '9.9k',
            ],
            [
                'name' => 'Sports',
                'icon' => '⚽',
                'count' => '4.3k',
            ],
            [
                'name' => 'Books',
                'icon' => '📚',
                'count' => '6.5k',
            ],
            [
                'name' => 'Automotive',
                'icon' => '🚗',
                'count' => '3.2k',
            ],
            [
                'name' => 'Groceries',
                'icon' => '🛒',
                'count' => '7.9k',
            ],
        ];

        $recommendedProducts = [
            [
                'id' => 1,
                'name' => 'Sony WH-1000XM5 Wireless Noise-Canceling Headphones',
                'price' => 899,
                'old_price' => 1299,
                'discount' => 31,
                'rating' => 4.9,
                'sold' => '3,240',
                'location' => 'Kuala Lumpur',
                'badge' => 'Best Seller',
                'shipping' => true,
                'shop' => 'TechHub Official',
            ],
            [
                'id' => 2,
                'name' => 'Nike Air Max 270 Men\'s Running Shoes',
                'price' => 389,
                'old_price' => 520,
                'discount' => 25,
                'rating' => 4.8,
                'sold' => '8,750',
                'location' => 'Penang',
                'badge' => 'Flash Deal',
                'shipping' => true,
                'shop' => 'SneakerVault',
            ],
            [
                'id' => 3,
                'name' => 'Scandinavian Oak Coffee Table with Storage',
                'price' => 649,
                'old_price' => 890,
                'discount' => 27,
                'rating' => 4.7,
                'sold' => '1,820',
                'location' => 'Johor Bahru',
                'badge' => 'Voucher',
                'shipping' => false,
                'shop' => 'HomeNest Co.',
            ],
            [
                'id' => 4,
                'name' => 'Logitech MX Master 3S Wireless Mouse',
                'price' => 219,
                'old_price' => 299,
                'discount' => 27,
                'rating' => 4.9,
                'sold' => '5,600',
                'location' => 'Kuala Lumpur',
                'badge' => 'Best Seller',
                'shipping' => true,
                'shop' => 'TechHub Official',
            ],
            [
                'id' => 5,
                'name' => 'COSRX Advanced Snail 96 Mucin Power Essence',
                'price' => 55,
                'old_price' => 79,
                'discount' => 30,
                'rating' => 4.8,
                'sold' => '18,900',
                'location' => 'Shah Alam',
                'badge' => null,
                'shipping' => true,
                'shop' => 'BeautyBox MY',
            ],
            [
                'id' => 6,
                'name' => 'MacBook Pro 14" M3 Pro',
                'price' => 8499,
                'old_price' => 9499,
                'discount' => 11,
                'rating' => 4.9,
                'sold' => '2,100',
                'location' => 'Kuala Lumpur',
                'badge' => 'Official Store',
                'shipping' => true,
                'shop' => 'TechHub Official',
            ],
        ];

        $bestSellers = [
            [
                'id' => 7,
                'name' => 'Adidas Ultraboost 22 Running Shoes',
                'price' => 349,
                'old_price' => 499,
                'discount' => 30,
                'rating' => 4.7,
                'sold' => '4,320',
                'location' => 'Penang',
                'badge' => 'New',
                'shipping' => true,
                'shop' => 'SneakerVault',
            ],
            [
                'id' => 8,
                'name' => 'Luxury Velvet Throw Pillow Set (4 pcs)',
                'price' => 89,
                'old_price' => 129,
                'discount' => 31,
                'rating' => 4.5,
                'sold' => '6,700',
                'location' => 'Johor Bahru',
                'badge' => null,
                'shipping' => true,
                'shop' => 'HomeNest Co.',
            ],
            [
                'id' => 9,
                'name' => 'Minimalist Linen Sofa 3-Seater — Warm Beige',
                'price' => 2499,
                'old_price' => 3200,
                'discount' => 22,
                'rating' => 4.6,
                'sold' => '930',
                'location' => 'Johor Bahru',
                'badge' => null,
                'shipping' => false,
                'shop' => 'HomeNest Co.',
            ],
            [
                'id' => 10,
                'name' => 'Samsung Galaxy S24 Ultra',
                'price' => 4899,
                'old_price' => 5499,
                'discount' => 11,
                'rating' => 4.8,
                'sold' => '3,400',
                'location' => 'Kuala Lumpur',
                'badge' => 'Best Seller',
                'shipping' => true,
                'shop' => 'TechHub Official',
            ],
            [
                'id' => 11,
                'name' => 'SK-II Facial Treatment Essence 160ml',
                'price' => 189,
                'old_price' => 269,
                'discount' => 30,
                'rating' => 4.9,
                'sold' => '5,234',
                'location' => 'Kuala Lumpur',
                'badge' => 'Best Seller',
                'shipping' => true,
                'shop' => 'BeautyBox MY',
            ],
        ];

        $featuredShops = [
            [
                'name' => 'TechHub Official',
                'logo' => '🔧',
                'image' => 'https://images.unsplash.com/photo-1496181133206-80ce9b88a853?auto=format&fit=crop&w=160&q=80',
                'rating' => 4.9,
                'followers' => 12400,
                'products' => 245,
                'verified' => true,
            ],
            [
                'name' => 'SneakerVault',
                'logo' => '👟',
                'image' => 'https://images.unsplash.com/photo-1542291026-7eec264c27ff?auto=format&fit=crop&w=160&q=80',
                'rating' => 4.8,
                'followers' => 8900,
                'products' => 87,
                'verified' => true,
            ],
            [
                'name' => 'HomeNest Co.',
                'logo' => '🏠',
                'image' => 'https://images.unsplash.com/photo-1618221195710-dd6b41faaea6?auto=format&fit=crop&w=160&q=80',
                'rating' => 4.7,
                'followers' => 5600,
                'products' => 132,
                'verified' => true,
            ],
            [
                'name' => 'BeautyBox MY',
                'logo' => '💄',
                'image' => 'https://images.unsplash.com/photo-1596462502278-27bfdc403348?auto=format&fit=crop&w=160&q=80',
                'rating' => 4.6,
                'followers' => 22000,
                'products' => 310,
                'verified' => true,
            ],
        ];

        return view('buyer.Dashboard.home', compact(
            'categories',
            'recommendedProducts',
            'bestSellers',
            'featuredShops'
        ));
    }

    public function products(Request $request): View
    {
        $categoryViews = [
            'men-s-apparel' => 'buyer.Category.MenApparel.mens-apparel',
            'women-s-apparel' => 'buyer.Category.WomenApparel.womens-apparel',
            'electronics-and-gadgets' => 'buyer.Category.Electronics&Gadgets.electronics-gadgets',
            'books-and-media' => 'buyer.Category.Books&Media.books-media',
            'pet-supplies' => 'buyer.Category.Pet-Supplies.pet-supplies',
            'sports-and-outdoors' => 'buyer.Category.Sports&Outdoors.sports-outdoors',
            'jewelry-and-watches' => 'buyer.Category.Jewelry&Watches.jewelry-watches',
            'kids-and-baby' => 'buyer.Category.Kids&Baby.kids-baby',
            'home-and-garden' => 'buyer.Category.Home&Garden.home-garden',
            'health-and-beauty' => 'buyer.Category.Health&Beauty.health-beauty',
            'food-and-gourmet' => 'buyer.Category.Foods&Gourmet.foods-gourmet',
            'furniture-and-office-equipment' => 'buyer.Category.Furniture&OfficeEquipment.furniture-office',
        ];

        $category = $request->query('category');

        if (isset($categoryViews[$category])) {
            return view($categoryViews[$category]);
        }

        if (! $this->hasBuyerTables()) {
            return view('buyer.Category.MenApparel.mens-apparel');
        }

        $query = Product::query()
            ->with(['store', 'variants' => fn ($variants) => $variants->where('is_active', true)])
            ->withMin('variants', 'price_minor')
            ->where('product_status', 'active')
            ->where('compliance_status', 'clear')
            ->whereHas('store', fn ($stores) => $stores->where('publication_status', 'published'));

        if ($request->category && $request->category !== 'All') {
            $query->whereHas('category', function ($categories) use ($request) {
                $categories->where('name', $request->category)
                    ->orWhere('slug', Str::slug($request->category));
            });
        }

        if ($request->search) {
            $search = trim((string) $request->search);
            $query->where(function ($products) use ($search) {
                $products->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $sort = $request->sort ?? 'featured';
        switch ($sort) {
            case 'price_low':
                $query->orderBy('variants_min_price_minor', 'asc');
                break;
            case 'price_high':
                $query->orderBy('variants_min_price_minor', 'desc');
                break;
            case 'newest':
                $query->orderByDesc('created_at');
                break;
            default:
                $query->orderByDesc('published_at')->orderByDesc('id');
        }

        $products = $query->paginate(12);
        $categories = [
            'All',
            'Electronics',
            'Fashion',
            'Home & Living',
            'Beauty',
            'Sports',
            'Books',
            'Automotive',
            'Groceries',
        ];

        return view('buyer.Category.MenApparel.mens-apparel', compact('products', 'categories'));
    }

    public function showProduct(Product $product): View
    {
        $product->load('store');

        if (! $this->hasBuyerTables() ||
            $product->product_status !== 'active' ||
            $product->compliance_status !== 'clear' ||
            $product->store?->publication_status !== 'published') {
            abort(404);
        }

        $product->load(['variants', 'images']);

        $relatedProducts = Product::where('category_id', $product->category_id)
            ->where('product_status', 'active')
            ->where('compliance_status', 'clear')
            ->whereHas('store', fn ($stores) => $stores->where('publication_status', 'published'))
            ->where('id', '!=', $product->id)
            ->limit(4)
            ->get();

        return view('products.show', compact('product', 'relatedProducts'));
    }

    public function cart(Request $request): View
    {
        $cart = $this->currentCart($request->user(), false);

        return view('buyer.cart', [
            'cartItems' => $cart ? $this->cartPayload($cart) : [],
        ]);
    }

    public function cartData(Request $request): JsonResponse
    {
        $cart = $this->currentCart($request->user(), false);

        return response()->json([
            'data' => [
                'items' => $cart ? $this->cartPayload($cart) : [],
                'cart_count' => $this->cartCount($request->user()),
            ],
            'message' => 'Cart retrieved successfully.',
        ]);
    }

    public function addToCart(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'quantity' => 'required|integer|min:1',
        ]);

        if (empty($data['product_variant_id']) && empty($data['product_id'])) {
            throw ValidationException::withMessages([
                'product_variant_id' => 'Choose a product variation before adding the item to your cart.',
            ]);
        }

        $item = DB::transaction(function () use ($request, $data) {
            $variantQuery = ProductVariant::query()
                ->with('product.store')
                ->where('is_active', true);

            if (! empty($data['product_variant_id'])) {
                $variantQuery->whereKey($data['product_variant_id']);
            } else {
                $variantQuery->where('product_id', $data['product_id'])
                    ->orderBy('position');
            }

            $variant = $variantQuery->lockForUpdate()->firstOrFail();
            $product = $variant->product;

            abort_unless(
                $product &&
                $product->product_status === 'active' &&
                $product->compliance_status === 'clear' &&
                $product->store?->publication_status === 'published',
                404
            );

            $cart = $this->currentCart($request->user());
            $existingItem = $cart->items()
                ->where('product_variant_id', $variant->id)
                ->lockForUpdate()
                ->first();
            $quantity = (int) ($existingItem?->quantity ?? 0) + (int) $data['quantity'];

            if ($quantity > $variant->available_stock) {
                throw ValidationException::withMessages([
                    'quantity' => "Only {$variant->available_stock} item(s) are currently available.",
                ]);
            }

            if ($existingItem) {
                $existingItem->update(['quantity' => $quantity]);

                return $existingItem->fresh('variant.product.store');
            }

            return $cart->items()->create([
                'product_variant_id' => $variant->id,
                'quantity' => (int) $data['quantity'],
                'selected' => true,
            ])->load('variant.product.store');
        });

        return response()->json([
            'data' => [
                'item' => $this->cartItemPayload($item),
                'cart_count' => $this->cartCount($request->user()),
            ],
            'message' => 'Product added to cart',
        ]);
    }

    public function updateCart(Request $request, CartItem $cartItem): JsonResponse
    {
        $data = $request->validate(['quantity' => 'required|integer|min:1']);
        $cart = $this->currentCart($request->user(), false);
        abort_unless($cart && (int) $cartItem->cart_id === (int) $cart->id, 404);

        $cartItem = DB::transaction(function () use ($cart, $cartItem, $data) {
            $item = $cart->items()
                ->with('variant.product.store')
                ->lockForUpdate()
                ->findOrFail($cartItem->id);

            abort_unless(
                $item->variant &&
                $item->variant->is_active &&
                $item->variant->product &&
                $item->variant->product->product_status === 'active' &&
                $item->variant->product->compliance_status === 'clear' &&
                $item->variant->product->store?->publication_status === 'published',
                404
            );

            if ((int) $data['quantity'] > $item->variant->available_stock) {
                throw ValidationException::withMessages([
                    'quantity' => "Only {$item->variant->available_stock} item(s) are currently available.",
                ]);
            }
            $item->update(['quantity' => (int) $data['quantity']]);

            return $item->fresh('variant.product.store');
        });

        return response()->json([
            'data' => [
                'item' => $this->cartItemPayload($cartItem),
                'cart_count' => $this->cartCount($request->user()),
            ],
            'message' => 'Cart updated',
        ]);
    }

    public function removeFromCart(Request $request, CartItem $cartItem): JsonResponse
    {
        $cart = $this->currentCart($request->user(), false);
        abort_unless($cart && (int) $cartItem->cart_id === (int) $cart->id, 404);
        DB::transaction(function () use ($cart, $cartItem): void {
            $cart->items()->whereKey($cartItem->id)->delete();
        });

        return response()->json([
            'data' => ['cart_count' => $this->cartCount($request->user())],
            'message' => 'Item removed from cart',
        ]);
    }

    public function clearCart(Request $request): JsonResponse
    {
        DB::transaction(function () use ($request): void {
            $this->currentCart($request->user(), false)?->items()->delete();
        });

        return response()->json([
            'data' => ['cart_count' => 0],
            'message' => 'Cart cleared',
        ]);
    }

    public function wishlist(Request $request): View
    {
        return view('buyer.wishlist', [
            'wishlistItems' => $this->wishlistPayload($request->user()),
        ]);
    }

    public function toggleWishlist(Request $request): JsonResponse
    {
        $data = $request->validate(['product_id' => 'required|integer|exists:products,id']);
        Product::query()
            ->with('store')
            ->whereKey($data['product_id'])
            ->where('product_status', 'active')
            ->where('compliance_status', 'clear')
            ->whereHas('store', fn ($stores) => $stores->where('publication_status', 'published'))
            ->firstOrFail();

        $isWishlisted = DB::transaction(function () use ($request, $data): bool {
            $wishlistItem = Wishlist::where('user_id', $request->user()->id)
                ->where('product_id', $data['product_id'])
                ->lockForUpdate()
                ->first();

            if ($wishlistItem) {
                $wishlistItem->delete();

                return false;
            }

            Wishlist::create([
                'user_id' => $request->user()->id,
                'product_id' => $data['product_id'],
            ]);

            return true;
        });

        return response()->json([
            'data' => [
                'is_wishlisted' => $isWishlisted,
                'wishlist_count' => Wishlist::where('user_id', $request->user()->id)->count(),
            ],
            'message' => $isWishlisted ? 'Product saved to wishlist.' : 'Product removed from wishlist.',
        ]);
    }

    private function currentCart($user, bool $create = true): ?Cart
    {
        $query = Cart::query()
            ->where('user_id', $user->id)
            ->where('status', 'active');

        if (! $create) {
            return $query->latest('id')->first();
        }

        return $query->first() ?? Cart::firstOrCreate([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
    }

    private function cartCount($user): int
    {
        $cart = $this->currentCart($user, false);

        return $cart
            ? (int) $cart->items()->sum('quantity')
            : 0;
    }

    private function cartPayload(Cart $cart): array
    {
        return $cart->items()
            ->with(['variant.product.store', 'variant.product.images'])
            ->orderBy('id')
            ->get()
            ->map(fn (CartItem $item) => $this->cartItemPayload($item))
            ->values()
            ->all();
    }

    private function cartItemPayload(CartItem $item): array
    {
        $variant = $item->variant;
        $product = $variant?->product;
        $image = $product?->images?->firstWhere('is_primary', true)
            ?: $product?->images?->first();
        $imagePath = (string) ($image?->path ?? '');

        return [
            'id' => $item->id,
            'product_id' => $product?->id,
            'product_variant_id' => $variant?->id,
            'name' => $product?->name ?? 'Product',
            'variant_name' => $variant?->name ?? '',
            'options' => $variant?->options ?? [],
            'price_minor' => (int) ($variant?->price_minor ?? 0),
            'quantity' => (int) $item->quantity,
            'selected' => (bool) $item->selected,
            'image' => $imagePath === ''
                ? ''
                : (Str::startsWith($imagePath, ['http://', 'https://', '/'])
                    ? $imagePath
                    : Storage::disk('public')->url($imagePath)),
            'seller_name' => $product?->store?->name ?? 'Bearly seller',
        ];
    }

    private function wishlistPayload($user): array
    {
        return Wishlist::query()
            ->where('user_id', $user->id)
            ->whereHas('product', function ($products) {
                $products
                    ->where('product_status', 'active')
                    ->where('compliance_status', 'clear')
                    ->whereHas('store', fn ($stores) => $stores->where('publication_status', 'published'));
            })
            ->with(['product.store', 'product.category', 'product.images', 'product.variants' => fn ($variants) => $variants->where('is_active', true)->orderBy('position')])
            ->latest('id')
            ->get()
            ->map(function (Wishlist $item) {
                $product = $item->product;
                $image = $product?->images?->firstWhere('is_primary', true)
                    ?: $product?->images?->first();
                $variant = $product?->variants?->first();
                $imagePath = (string) ($image?->path ?? '');

                return [
                    'id' => $item->id,
                    'product_id' => $product?->id,
                    'product_variant_id' => $variant?->id,
                    'name' => $product?->name ?? 'Product',
                    'price_minor' => (int) ($variant?->price_minor ?? 0),
                    'category' => $product?->category?->name ?? 'Product',
                    'image' => $imagePath === ''
                        ? ''
                        : (Str::startsWith($imagePath, ['http://', 'https://', '/'])
                            ? $imagePath
                            : Storage::disk('public')->url($imagePath)),
                    'seller_name' => $product?->store?->name ?? 'Bearly seller',
                ];
            })
            ->values()
            ->all();
    }
}
