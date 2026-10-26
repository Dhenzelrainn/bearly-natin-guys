<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MobileBuyerProductResource;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\BuyerCatalogService;
use App\Services\ProductReviewService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MobileBuyerCatalogController extends Controller
{
    public function index(
        Request $request,
        BuyerCatalogService $catalog,
        ProductReviewService $reviews,
    ): JsonResponse {
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'q' => ['sometimes', 'nullable', 'string', 'max:120'],
            'category' => ['sometimes', 'nullable', 'string', 'max:140'],
            'sort' => ['sometimes', 'string', 'in:featured,newest,price_low,price_high'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search === '') {
            $search = trim((string) ($filters['q'] ?? ''));
        }

        $categoryFilter = trim((string) ($filters['category'] ?? ''));
        $sort = (string) ($filters['sort'] ?? 'featured');
        $perPage = (int) ($filters['per_page'] ?? 12);

        $query = Product::query()
            ->with([
                'category.parent',
                'store:id,name,slug',
                'images' => fn ($images) => $images->with('variant')->orderBy('position'),
                'variants' => fn ($variants) => $variants
                    ->where('is_active', true)
                    ->orderBy('position')
                    ->orderBy('id'),
            ])
            ->addSelect([
                'variants_display_price_minor' => ProductVariant::query()
                    ->select('product_variants.price_minor')
                    ->whereColumn('product_variants.product_id', 'products.id')
                    ->where('product_variants.is_active', true)
                    ->orderBy('product_variants.position')
                    ->orderBy('product_variants.id')
                    ->limit(1),
            ])
            ->visibleToBuyers()
            ->acceptsCashOnDelivery();

        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function (Builder $matches) use ($like): void {
                $matches->where('products.name', 'like', $like)
                    ->orWhere('products.description', 'like', $like)
                    ->orWhereHas('category', fn (Builder $categories) => $categories
                        ->where('name', 'like', $like)
                        ->orWhere('slug', 'like', $like))
                    ->orWhereHas('subcategory', fn (Builder $subcategories) => $subcategories
                        ->where('name', 'like', $like))
                    ->orWhereHas('store', fn (Builder $stores) => $stores->where('name', 'like', $like));
            });
        }

        if ($categoryFilter !== '' && Str::lower($categoryFilter) !== 'all') {
            $category = $catalog->categoryForSlug($categoryFilter);

            if ($category) {
                $query->where('category_id', $category->id);
            } else {
                $query->whereHas('category', fn (Builder $categories) => $categories
                    ->where('slug', $categoryFilter)
                    ->orWhere('name', $categoryFilter));
            }
        }

        match ($sort) {
            'price_low' => $query->orderBy('variants_display_price_minor')->orderByDesc('products.id'),
            'price_high' => $query->orderByDesc('variants_display_price_minor')->orderByDesc('products.id'),
            'newest' => $query->orderByDesc('products.created_at')->orderByDesc('products.id'),
            default => $query->orderByDesc('products.published_at')->orderByDesc('products.id'),
        };

        $paginator = $query->paginate($perPage)->appends($filters);
        $pageProducts = $paginator->getCollection();
        $cards = collect($catalog->cards($pageProducts))->keyBy('id');
        $ratingSummaries = $reviews->productSummaries(
            $pageProducts->pluck('id')->map(fn ($id): int => (int) $id)->all(),
        );

        $data = $pageProducts
            ->map(fn (Product $product): array => (new MobileBuyerProductResource(
                $product,
                $cards->get((int) $product->id, []),
                $ratingSummaries[(int) $product->id] ?? ['count' => 0, 'average' => 0.0],
            ))->resolve($request))
            ->values()
            ->all();

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'has_more' => $paginator->hasMorePages(),
            ],
            'filters' => [
                'search' => $search,
                'category' => $categoryFilter !== '' ? $categoryFilter : null,
                'sort' => $sort,
            ],
        ]);
    }

    public function show(
        Product $product,
        BuyerCatalogService $catalog,
        ProductReviewService $reviews,
        Request $request,
    ): JsonResponse {
        $visibleProduct = Product::query()
            ->visibleToBuyers()
            ->acceptsCashOnDelivery()
            ->with([
                'category.parent',
                'store:id,name,slug',
                'images' => fn ($images) => $images->with('variant')->orderBy('position'),
                'variants' => fn ($variants) => $variants
                    ->where('is_active', true)
                    ->orderBy('position')
                    ->orderBy('id'),
            ])
            ->whereKey($product->getKey())
            ->firstOrFail();

        $ratingSummary = $reviews->productSummary((int) $visibleProduct->id);
        $resource = new MobileBuyerProductResource(
            $visibleProduct,
            $catalog->card($visibleProduct),
            ['count' => $ratingSummary['count'], 'average' => $ratingSummary['average']],
            detailed: true,
        );

        return response()->json(['data' => $resource->resolve($request)]);
    }

    public function categories(): JsonResponse
    {
        $categories = Category::query()
            ->where('is_active', true)
            ->where(function (Builder $query): void {
                $query->whereHas('products', fn (Builder $products) => $products->visibleToBuyers())
                    ->orWhereHas('children', fn (Builder $children) => $children
                        ->where('is_active', true)
                        ->whereHas('products', fn (Builder $products) => $products->visibleToBuyers()));
            })
            ->with('parent')
            ->orderBy('position')
            ->orderBy('name')
            ->get()
            ->map(fn (Category $category): array => [
                'id' => (int) $category->id,
                'name' => (string) $category->name,
                'slug' => (string) $category->slug,
                'parent' => $category->parent && $category->parent->is_active
                    ? [
                        'id' => (int) $category->parent->id,
                        'name' => (string) $category->parent->name,
                        'slug' => (string) $category->parent->slug,
                    ]
                    : null,
            ])
            ->values();

        return response()->json(['data' => $categories]);
    }
}
