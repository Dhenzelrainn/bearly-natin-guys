<?php

namespace App\Services;

use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SellerInventoryService
{
    public const ADJUSTMENT_REASONS = [
        'new_stock_received' => 'New stock received',
        'stock_count' => 'Manual stock count',
        'damaged_item' => 'Damaged item',
        'returned_order' => 'Returned order',
        'other_adjustment' => 'Other adjustment',
    ];

    private const MAX_STOCK_BALANCE = 4_294_967_295;

    private const MAX_MOVEMENT_DELTA = 2_147_483_647;

    public function __construct(private SellerProductService $products) {}

    /**
     * Build the Seller's inventory workspace entirely from persisted Store data.
     *
     * @return array{
     *     inventoryTotal: int,
     *     inventorySummary: array<int, array{label: string, value: int, note: string}>,
     *     inventoryAttention: array{total: int, low: int, out: int},
     *     inventoryTabs: array<int, array{key: string, label: string, count: int}>,
     *     inventorySubcategories: array<int, array{id: int, name: string}>,
     *     inventoryHasUncategorized: bool,
     *     inventoryItems: Collection<int, array<string, mixed>>,
     *     stockMovements: Collection<int, array<string, mixed>>,
     *     adjustmentReasons: array<string, string>
     * }
     */
    public function inventoryFor(User $user): array
    {
        $store = $this->products->storeFor($user);

        $variants = ProductVariant::query()
            ->where('is_active', true)
            ->whereHas('product', fn ($products) => $products->where('store_id', $store->id))
            ->with([
                'product.subcategory:id,store_id,name',
                'product.images' => fn ($images) => $images
                    ->select(['id', 'product_id', 'variant_id', 'path', 'disk', 'position', 'is_primary'])
                    ->orderBy('position'),
            ])
            ->orderBy('product_id')
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        $seenProducts = [];
        $items = $variants->map(function (ProductVariant $variant) use (&$seenProducts, $store): array {
            $product = $variant->product;
            $productImages = $product->images->whereNull('variant_id');
            $image = $productImages->firstWhere('is_primary', true) ?? $productImages->first();
            $subcategory = $product->subcategory;
            $hasOwnedSubcategory = $subcategory !== null
                && (int) $subcategory->store_id === (int) $store->id;
            $available = max(0, (int) $variant->stock_on_hand - (int) $variant->stock_reserved);
            $threshold = (int) $variant->low_stock_threshold;
            $productStatus = (string) $product->product_status;
            $statusKey = $productStatus === 'archived'
                ? 'archived'
                : ($available === 0
                    ? 'out-of-stock'
                    : ($available <= $threshold ? 'low-stock' : 'in-stock'));
            $isVariationRow = isset($seenProducts[$product->id]);
            $seenProducts[$product->id] = true;

            return [
                'id' => (int) $variant->id,
                'parent' => (string) $product->id,
                'variation_row' => $isVariationRow,
                'product' => (string) $product->name,
                'sku' => (string) $variant->sku,
                'variation' => Str::lower((string) $variant->name) === 'default'
                    ? 'Standard'
                    : (string) $variant->name,
                'subcategory' => $hasOwnedSubcategory ? (string) $subcategory->name : null,
                'subcategory_id' => $hasOwnedSubcategory ? (int) $subcategory->id : null,
                'image' => $image
                    ? Storage::disk($image->disk ?: 'public')->url($image->path)
                    : null,
                'icon' => 'package',
                'on_hand' => (int) $variant->stock_on_hand,
                'reserved' => (int) $variant->stock_reserved,
                'available' => $available,
                'threshold' => $threshold,
                'status' => match ($statusKey) {
                    'archived' => 'Archived',
                    'out-of-stock' => 'Out of Stock',
                    'low-stock' => 'Low Stock',
                    default => 'In Stock',
                },
                'status_key' => $statusKey,
            ];
        })->values();

        $activeItems = $items->reject(fn (array $item): bool => $item['status_key'] === 'archived');
        $lowStockCount = $activeItems->where('status_key', 'low-stock')->count();
        $outOfStockCount = $activeItems->where('status_key', 'out-of-stock')->count();
        $availableUnits = (int) $activeItems->sum('available');
        $nonArchivedProductCount = $activeItems->pluck('parent')->unique()->count();

        $subcategories = $items
            ->filter(fn (array $item): bool => $item['subcategory_id'] !== null)
            ->map(fn (array $item): array => [
                'id' => $item['subcategory_id'],
                'name' => $item['subcategory'],
            ])
            ->unique('id')
            ->sortBy(fn (array $subcategory): string => Str::lower($subcategory['name']))
            ->values()
            ->all();
        $hasUncategorizedItems = $items->contains(fn (array $item): bool => $item['subcategory_id'] === null);

        return [
            'inventoryTotal' => $activeItems->count(),
            'inventorySummary' => [
                ['label' => 'Total SKUs', 'value' => $activeItems->count(), 'note' => $nonArchivedProductCount.' products'],
                ['label' => 'Available Units', 'value' => $availableUnits, 'note' => 'Across active inventory'],
                ['label' => 'Low Stock', 'value' => $lowStockCount, 'note' => 'At or below threshold'],
                ['label' => 'Out of Stock', 'value' => $outOfStockCount, 'note' => 'No available units'],
            ],
            'inventoryAttention' => [
                'total' => $lowStockCount + $outOfStockCount,
                'low' => $lowStockCount,
                'out' => $outOfStockCount,
            ],
            'inventoryTabs' => [
                ['key' => 'all', 'label' => 'All Inventory', 'count' => $activeItems->count()],
                ['key' => 'needs-restocking', 'label' => 'Needs Restocking', 'count' => $lowStockCount + $outOfStockCount],
                ['key' => 'low-stock', 'label' => 'Low Stock', 'count' => $lowStockCount],
                ['key' => 'out-of-stock', 'label' => 'Out of Stock', 'count' => $outOfStockCount],
                ['key' => 'archived', 'label' => 'Archived', 'count' => $items->where('status_key', 'archived')->count()],
            ],
            'inventorySubcategories' => $subcategories,
            'inventoryHasUncategorized' => $hasUncategorizedItems,
            'inventoryItems' => $items,
            'stockMovements' => $this->recentMovementsFor($store),
            'adjustmentReasons' => self::ADJUSTMENT_REASONS,
        ];
    }

    public function adjustStock(
        User $user,
        array $variantIds,
        string $mode,
        int $quantity,
        string $reason,
        ?string $reasonNote = null,
    ): int {
        if (! in_array($mode, ['add', 'remove', 'set'], true)) {
            throw ValidationException::withMessages(['mode' => 'Choose a valid stock adjustment.']);
        }

        if (in_array($mode, ['add', 'remove'], true) && $quantity < 1) {
            throw ValidationException::withMessages(['quantity' => 'Enter a quantity greater than zero.']);
        }

        if ($quantity < 0 || ($mode !== 'set' && $quantity > self::MAX_MOVEMENT_DELTA)) {
            throw ValidationException::withMessages(['quantity' => 'Enter a quantity within the supported stock range.']);
        }

        if ($mode === 'set' && $quantity > self::MAX_STOCK_BALANCE) {
            throw ValidationException::withMessages(['quantity' => 'Enter a quantity within the supported stock range.']);
        }

        if (! array_key_exists($reason, self::ADJUSTMENT_REASONS)) {
            throw ValidationException::withMessages(['reason' => 'Choose a valid reason for this adjustment.']);
        }

        $store = $this->products->storeFor($user);
        $reasonText = self::ADJUSTMENT_REASONS[$reason];
        $reasonNote = Str::squish((string) $reasonNote);

        if ($reasonNote !== '') {
            $reasonText .= ' — '.$reasonNote;
        }

        return DB::transaction(function () use ($store, $user, $variantIds, $mode, $quantity, $reasonText): int {
            $variants = $this->lockOwnedVariants($store, $variantIds);
            $changes = [];

            foreach ($variants as $variant) {
                $current = (int) $variant->stock_on_hand;
                $reserved = (int) $variant->stock_reserved;
                $next = match ($mode) {
                    'add' => $current + $quantity,
                    'remove' => $current - $quantity,
                    default => $quantity,
                };

                if ($next < $reserved) {
                    throw ValidationException::withMessages([
                        'quantity' => "The adjustment would reduce {$variant->sku} below its {$reserved} reserved unit(s).",
                    ]);
                }

                if ($next > self::MAX_STOCK_BALANCE) {
                    throw ValidationException::withMessages([
                        'quantity' => "The adjustment would exceed the supported stock balance for {$variant->sku}.",
                    ]);
                }

                $delta = $next - $current;

                if ($delta < -self::MAX_MOVEMENT_DELTA || $delta > self::MAX_MOVEMENT_DELTA) {
                    throw ValidationException::withMessages([
                        'quantity' => "The adjustment is too large to record for {$variant->sku}.",
                    ]);
                }

                $changes[] = ['variant' => $variant, 'next' => $next, 'delta' => $delta];
            }

            $changed = 0;
            $occurredAt = now();

            foreach ($changes as $change) {
                /** @var ProductVariant $variant */
                $variant = $change['variant'];
                $delta = (int) $change['delta'];

                if ($delta === 0) {
                    continue;
                }

                $variant->forceFill(['stock_on_hand' => $change['next']])->save();

                InventoryMovement::query()->create([
                    'variant_id' => $variant->id,
                    'type' => 'adjustment',
                    'quantity_delta' => $delta,
                    'balance_after' => $change['next'],
                    'reference_type' => Product::class,
                    'reference_id' => $variant->product_id,
                    'reason' => $reasonText,
                    'created_by' => $user->id,
                    'occurred_at' => $occurredAt,
                ]);

                $changed++;
            }

            return $changed;
        });
    }

    public function setThreshold(User $user, array $variantIds, int $threshold): int
    {
        if ($threshold < 0 || $threshold > self::MAX_STOCK_BALANCE) {
            throw ValidationException::withMessages(['threshold' => 'Enter a valid low-stock threshold.']);
        }

        $store = $this->products->storeFor($user);

        return DB::transaction(function () use ($store, $variantIds, $threshold): int {
            $variants = $this->lockOwnedVariants($store, $variantIds);

            foreach ($variants as $variant) {
                if ((int) $variant->low_stock_threshold !== $threshold) {
                    $variant->forceFill(['low_stock_threshold' => $threshold])->save();
                }
            }

            return $variants->count();
        });
    }

    private function lockOwnedVariants(Store $store, array $variantIds): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $variantIds)));

        if ($ids === [] || count($ids) !== count($variantIds)) {
            throw ValidationException::withMessages(['variant_ids' => 'Select one or more unique inventory items.']);
        }

        $variants = ProductVariant::query()
            ->whereIn('id', $ids)
            ->where('is_active', true)
            ->whereHas('product', fn ($products) => $products->where('store_id', $store->id))
            ->with('product:id,store_id')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($variants->count() !== count($ids)) {
            throw (new ModelNotFoundException)->setModel(ProductVariant::class, $ids);
        }

        return $variants;
    }

    /** @return Collection<int, array<string, mixed>> */
    private function recentMovementsFor(Store $store): Collection
    {
        return InventoryMovement::query()
            ->whereHas('variant', fn ($variants) => $variants
                ->withTrashed()
                ->whereHas('product', fn ($products) => $products
                    ->withTrashed()
                    ->where('store_id', $store->id)))
            ->with([
                'variant' => fn ($variants) => $variants->withTrashed(),
                'variant.product' => fn ($products) => $products->withTrashed(),
                'createdBy',
            ])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(15)
            ->get()
            ->map(function (InventoryMovement $movement): array {
                $variant = $movement->variant;
                $delta = (int) $movement->quantity_delta;
                $reservedDelta = (int) $movement->reserved_delta;
                $isReservationMovement = in_array((string) $movement->type, ['order_reserved', 'order_reservation_released'], true);

                return [
                    'date' => $movement->occurred_at?->format('M d, Y · g:i A') ?? '—',
                    'product' => (string) ($variant?->product?->name ?? 'Product unavailable'),
                    'sku' => (string) ($variant?->sku ?? '—'),
                    'type' => match ((string) $movement->type) {
                        'initial_stock' => 'Initial stock',
                        'adjustment' => 'Stock adjustment',
                        'order_reserved' => 'Stock reserved',
                        'order_reservation_released' => 'Reservation released',
                        default => Str::headline((string) $movement->type),
                    },
                    'reason' => (string) ($movement->reason ?: '—'),
                    'quantity' => $isReservationMovement
                        ? ($reservedDelta > 0 ? '+'.$reservedDelta.' reserved' : '−'.abs($reservedDelta).' released')
                        : ($delta > 0 ? '+'.$delta : ($delta < 0 ? '−'.abs($delta) : '0')),
                    'direction' => $isReservationMovement ? 'neutral' : ($delta > 0 ? 'positive' : ($delta < 0 ? 'negative' : 'neutral')),
                    'balance' => (int) $movement->balance_after,
                    'actor' => (string) ($movement->createdBy?->name ?: 'System'),
                ];
            })
            ->values();
    }
}
