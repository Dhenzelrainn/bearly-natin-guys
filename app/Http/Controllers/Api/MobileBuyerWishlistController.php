<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MobileBuyerProductResource;
use App\Models\Product;
use App\Models\Wishlist;
use App\Services\BuyerCatalogService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class MobileBuyerWishlistController extends Controller
{
    public function index(Request $request, BuyerCatalogService $catalog): JsonResponse
    {
        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $buyer = $request->user();
        $perPage = (int) ($filters['per_page'] ?? 12);
        $wishlistItems = Wishlist::query()
            ->where('user_id', $buyer->id)
            ->whereHas('product', fn (Builder $products) => $products->visibleToBuyers())
            ->with([
                'product.category.parent',
                'product.store:id,name,slug',
                'product.images' => fn ($images) => $images->with('variant')->orderBy('position'),
                'product.variants' => fn ($variants) => $variants
                    ->where('is_active', true)
                    ->orderBy('position'),
            ])
            ->orderByDesc('wishlists.id')
            ->paginate($perPage);

        $products = $wishlistItems->getCollection()
            ->map(fn (Wishlist $item) => $item->product)
            ->filter()
            ->values();
        $cards = collect($catalog->cards($products, $buyer))->keyBy('id');

        $data = $wishlistItems->getCollection()
            ->map(function (Wishlist $item) use ($cards, $request): array {
                $product = $item->product;

                return [
                    'wishlist_id' => (int) $item->id,
                    'saved_at' => $item->created_at
                        ? Carbon::parse($item->created_at)->toISOString()
                        : null,
                    'product' => (new MobileBuyerProductResource(
                        $product,
                        $cards->get((int) $product->id, []),
                    ))->resolve($request),
                ];
            })
            ->values()
            ->all();

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $wishlistItems->currentPage(),
                'per_page' => $wishlistItems->perPage(),
                'total' => $wishlistItems->total(),
                'last_page' => $wishlistItems->lastPage(),
                'has_more' => $wishlistItems->hasMorePages(),
            ],
        ]);
    }

    public function store(Request $request, int $product): JsonResponse
    {
        $visibleProduct = Product::query()
            ->visibleToBuyers()
            ->whereKey($product)
            ->firstOrFail();

        Wishlist::query()->firstOrCreate([
            'user_id' => $request->user()->id,
            'product_id' => $visibleProduct->id,
        ]);

        return response()->json([
            'data' => [
                'product_id' => (int) $visibleProduct->id,
                'is_saved' => true,
            ],
            'message' => 'Product saved to wishlist.',
        ]);
    }

    public function destroy(Request $request, int $product): JsonResponse
    {
        Wishlist::query()
            ->where('user_id', $request->user()->id)
            ->where('product_id', $product)
            ->delete();

        return response()->json([
            'data' => [
                'product_id' => $product,
                'is_saved' => false,
            ],
            'message' => 'Product removed from wishlist.',
        ]);
    }
}
