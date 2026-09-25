<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\Wishlist;
use App\Services\CheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class BuyerController extends Controller
{
    private function hasBuyerTables(): bool
{
    try {
        return Schema::hasTable('products')
            && Schema::hasTable('stores')
            && Schema::hasTable('cart_items')
            && Schema::hasTable('wishlists');
    } catch (\Throwable $e) {
        return false;
    }
}

    public function home(): View
    {
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
        if ($request->query('category') === 'men-s-apparel') {
            return view('buyer.Category.MenApparel.mens-apparel');
        }

        if (! $this->hasBuyerTables()) {
            return view('buyer.Category.MenApparel.mens-apparel');
        }

        $query = Product::with(['store', 'category', 'variants'])->where('product_status', 'active');

        if ($request->category && $request->category !== 'All') {
            $query->whereHas('category', fn ($category) => $category->where('name', $request->category));
        }

        if ($request->search) {
            $query->where('name', 'like', '%' . $request->search . '%')
                ->orWhere('description', 'like', '%' . $request->search . '%');
        }

        $sort = $request->sort ?? 'featured';
        switch ($sort) {
            case 'newest':
                $query->orderByDesc('created_at');
                break;
            default:
                $query->orderByDesc('published_at');
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
        if (! $this->hasBuyerTables()) {
            abort(404);
        }

        $product->load(['store', 'category', 'variants', 'images']);

        $relatedProducts = Product::where('category_id', $product->category_id)
            ->where('id', '!=', $product->id)
            ->limit(4)
            ->get();

        return view('products.show', compact('product', 'relatedProducts'));
    }

    public function cart(): View
    {
        if (! $this->hasBuyerTables()) {
            return view('buyer.Dashboard.home');
        }

        $cartItems = $this->cartFor(request())->items()->with('variant.product.store')->get();

        $total = $cartItems->sum(fn (CartItem $item) => $item->quantity * $item->variant->price_minor / 100);

        return view('buyer.Dashboard.home', compact('cartItems', 'total'));
    }

    public function addToCart(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'product_variant_id' => 'nullable|exists:product_variants,id',
            'quantity' => 'required|integer|min:1',
        ]);

        $product = Product::with('variants')->findOrFail($validated['product_id']);
        $variant = $product->variants->firstWhere('id', (int) ($validated['product_variant_id'] ?? 0)) ?? $product->variants->first();
        abort_unless($variant, 422, 'This product has no purchasable variant.');
        $cart = $this->cartFor($request);
        $existingItem = $cart->items()->where('product_variant_id', $variant->id)->first();

        if ($existingItem) {
            $existingItem->quantity += $request->quantity;
            $existingItem->save();
        } else {
            CartItem::create([
                'cart_id' => $cart->id,
                'product_variant_id' => $variant->id,
                'quantity' => $request->quantity,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Product added to cart',
            'cart_count' => $cart->items()->count(),
        ]);
    }

    public function updateCart(Request $request, CartItem $cartItem): JsonResponse
    {
        $request->validate(['quantity' => 'required|integer|min:1']);

        $cartItem->quantity = $request->quantity;
        $cartItem->save();

        return response()->json([
            'success' => true,
            'message' => 'Cart updated',
            'total' => $cartItem->quantity * $cartItem->variant->price_minor / 100,
        ]);
    }

    public function removeFromCart(CartItem $cartItem): JsonResponse
    {
        $cartItem->delete();

        return response()->json([
            'success' => true,
            'message' => 'Item removed from cart',
        ]);
    }

    public function clearCart(): JsonResponse
    {
        $this->cartFor(request())->items()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Cart cleared',
        ]);
    }

    public function wishlist(): View
    {
        if (! $this->hasBuyerTables()) {
            return view('buyer.Dashboard.home');
        }

        $wishlistItems = Wishlist::where(fn ($query) => auth()->check()
                ? $query->where('user_id', auth()->id())
                : $query->where('session_token', session()->getId()))
            ->with('product')
            ->get();

        return view('buyer.Dashboard.home', compact('wishlistItems'));
    }

    public function toggleWishlist(Request $request): JsonResponse
    {
        $request->validate(['product_id' => 'required|exists:products,id']);

        $owner = $request->user() ? ['user_id' => $request->user()->id] : ['session_token' => $request->session()->getId()];
        $wishlistItem = Wishlist::where($owner)
            ->where('product_id', $request->product_id)
            ->first();

        if ($wishlistItem) {
            $wishlistItem->delete();
            $isWishlisted = false;
        } else {
            Wishlist::create([
                ...$owner,
                'product_id' => $request->product_id,
            ]);
            $isWishlisted = true;
        }

        return response()->json([
            'success' => true,
            'is_wishlisted' => $isWishlisted,
            'wishlist_count' => Wishlist::where($owner)->count(),
        ]);
    }

    public function checkout(Request $request, CheckoutService $checkout): RedirectResponse
    {
        $data = $request->validate(['recipient_name'=>['required','string','max:160'],'recipient_phone'=>['required','string','max:30'],
            'address_line'=>['required','string','max:255'],'barangay'=>['required','string','max:120'],
            'city_municipality'=>['required','string','max:120'],'province'=>['required','string','max:120'],
            'postal_code'=>['nullable','string','max:10'],'payment_method'=>['required','in:cod,gcash,maya,card']]);
        $order = $checkout->place($request->user(), $this->cartFor($request), $data);
        return redirect()->route('home')->with('success', 'Order '.$order->order_no.' was placed successfully.');
    }

    private function cartFor(Request $request): Cart
    {
        return Cart::firstOrCreate($request->user()
            ? ['user_id'=>$request->user()->id,'status'=>'active']
            : ['session_token'=>$request->session()->getId(),'status'=>'active']);
    }
}
