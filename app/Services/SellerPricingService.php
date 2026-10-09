<?php

namespace App\Services;

use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SellerPricingService
{
    public function __construct(private SellerProductService $products) {}

    /**
     * Apply one product-level regular price and discount to every active SKU.
     *
     * @return array{product_id: int, regular_price_minor: int, sale_price_minor: int, discount_percent: int, active_variant_count: int}
     */
    public function updateProductPricing(
        User $user,
        string|int $productId,
        string $regularPrice,
        int $discountPercent,
    ): array {
        if ($discountPercent < 0 || $discountPercent > 90) {
            throw ValidationException::withMessages([
                'discount_percent' => 'Discount must be between 0 and 90 percent.',
            ]);
        }

        $regularPriceMinor = $this->moneyToMinor($regularPrice);

        return DB::transaction(function () use ($user, $productId, $regularPriceMinor, $discountPercent): array {
            $store = $this->products->storeFor($user);
            $product = Product::query()
                ->where('store_id', $store->id)
                ->lockForUpdate()
                ->findOrFail($productId);

            if ($product->removed_at !== null) {
                throw ValidationException::withMessages([
                    'product' => 'This product was removed by platform moderation and cannot be repriced.',
                ]);
            }

            if ($product->product_status === 'archived') {
                throw ValidationException::withMessages([
                    'product' => 'Restore this product before changing its price or discount.',
                ]);
            }

            $variants = $product->variants()
                ->where('is_active', true)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($variants->isEmpty()) {
                throw ValidationException::withMessages([
                    'product' => 'This product has no active SKUs to update.',
                ]);
            }

            $salePriceMinor = $discountPercent === 0
                ? $regularPriceMinor
                : intdiv($regularPriceMinor * (100 - $discountPercent) + 50, 100);

            foreach ($variants as $variant) {
                $variant->forceFill([
                    'price_minor' => $salePriceMinor,
                    'compare_at_price_minor' => $discountPercent > 0 ? $regularPriceMinor : null,
                ])->save();
            }

            return [
                'product_id' => (int) $product->id,
                'regular_price_minor' => $regularPriceMinor,
                'sale_price_minor' => $salePriceMinor,
                'discount_percent' => $discountPercent,
                'active_variant_count' => $variants->count(),
            ];
        });
    }

    /**
     * @return array{product_id: int, voucher_eligible: bool}
     */
    public function setVoucherEligibility(User $user, string|int $productId, bool $eligible): array
    {
        return DB::transaction(function () use ($user, $productId, $eligible): array {
            $store = $this->products->storeFor($user);
            $product = Product::query()
                ->where('store_id', $store->id)
                ->lockForUpdate()
                ->findOrFail($productId);

            if ($product->removed_at !== null) {
                throw ValidationException::withMessages([
                    'product' => 'This product was removed by platform moderation and cannot be changed.',
                ]);
            }

            if ($product->product_status === 'archived') {
                throw ValidationException::withMessages([
                    'product' => 'Restore this product before changing its voucher eligibility.',
                ]);
            }

            $product->forceFill(['voucher_eligible' => $eligible])->save();

            return [
                'product_id' => (int) $product->id,
                'voucher_eligible' => (bool) $product->voucher_eligible,
            ];
        });
    }

    private function moneyToMinor(string $value): int
    {
        if (! preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D', $value)) {
            throw ValidationException::withMessages([
                'regular_price' => 'Enter a valid price with up to two decimal places.',
            ]);
        }

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }
}
