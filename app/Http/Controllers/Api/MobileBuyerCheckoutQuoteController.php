<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MobileBuyerCheckoutQuoteController extends Controller
{
    public function __invoke(Request $request, CheckoutService $checkout): JsonResponse
    {
        $data = $request->validate([
            'address_id' => ['required', 'integer', 'min:1'],
            'cart_item_ids' => ['required', 'array', 'min:1', 'max:100'],
            'cart_item_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'platform_voucher_id' => ['nullable', 'integer', 'min:1'],
            'store_voucher_id' => ['nullable', 'integer', 'min:1'],
            'voucher_code' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/'],
            'payment_method' => ['sometimes', 'string', 'in:cod'],
        ]);

        $quote = $checkout->quote(
            $request->user(),
            $data['cart_item_ids'],
            (int) $data['address_id'],
            [
                'platform_voucher_id' => $data['platform_voucher_id'] ?? null,
                'store_voucher_id' => $data['store_voucher_id'] ?? null,
                'voucher_code' => filled($data['voucher_code'] ?? null)
                    ? strtoupper(trim((string) $data['voucher_code']))
                    : null,
            ],
        );

        $items = array_map(static function (array $item): array {
            $sellerDiscount = (int) ($item['discount_minor'] ?? 0);
            $platformDiscount = (int) ($item['platform_discount_minor'] ?? 0);
            $subtotal = (int) $item['subtotal_minor'];

            return [
                'cart_item_id' => (int) $item['cart_item_id'],
                'product_id' => (int) $item['product_id'],
                'product_name' => (string) $item['product_name'],
                'variant_id' => (int) $item['variant_id'],
                'variant_name' => (string) $item['variant_name'],
                'options' => is_array($item['options'] ?? null) ? $item['options'] : [],
                'quantity' => (int) $item['quantity'],
                'unit_price_minor' => (int) $item['unit_price_minor'],
                'subtotal_minor' => $subtotal,
                'seller_discount_minor' => $sellerDiscount,
                'platform_discount_minor' => $platformDiscount,
                'total_discount_minor' => $sellerDiscount + $platformDiscount,
                'total_minor' => max(0, $subtotal - $sellerDiscount - $platformDiscount),
            ];
        }, $quote['items']);

        $itemsByStore = [];
        foreach ($quote['items'] as $item) {
            $storeId = (int) $item['store_id'];
            $itemsByStore[$storeId] ??= 0;
            $itemsByStore[$storeId] += (int) $item['subtotal_minor'];
        }

        $sellerOrders = array_map(static function (array $sellerOrder) use ($itemsByStore): array {
            $sellerDiscount = (int) ($sellerOrder['discount_minor'] ?? 0);
            $platformDiscount = (int) ($sellerOrder['platform_discount_minor'] ?? 0);
            $shippingDiscount = (int) ($sellerOrder['platform_shipping_discount_minor'] ?? 0);

            return [
                'store_id' => (int) $sellerOrder['store_id'],
                'store_name' => (string) $sellerOrder['store_name'],
                'item_count' => (int) $sellerOrder['item_count'],
                'subtotal_minor' => (int) ($itemsByStore[(int) $sellerOrder['store_id']] ?? 0),
                'seller_discount_minor' => $sellerDiscount,
                'platform_discount_minor' => $platformDiscount,
                'shipping_fee_minor' => (int) $sellerOrder['shipping_fee_minor'],
                'shipping_discount_minor' => $shippingDiscount,
                'total_discount_minor' => $sellerDiscount + $platformDiscount + $shippingDiscount,
                'total_minor' => (int) $sellerOrder['buyer_total_minor'],
            ];
        }, $quote['seller_orders']);

        return response()->json([
            'data' => [
                'currency' => 'PHP',
                'items' => $items,
                'seller_orders' => $sellerOrders,
                'delivery_address' => $quote['delivery_address'],
                'subtotal_minor' => (int) $quote['subtotal_minor'],
                'shipping_fee_minor' => (int) $quote['shipping_fee_minor'],
                'seller_discount_minor' => (int) ($quote['seller_discount_minor'] ?? 0),
                'platform_discount_minor' => (int) ($quote['platform_discount_minor'] ?? 0),
                'shipping_discount_minor' => (int) ($quote['shipping_discount_minor'] ?? 0),
                'discount_minor' => (int) ($quote['discount_minor'] ?? 0),
                'total_discount_minor' => (int) ($quote['total_discount_minor'] ?? 0),
                'total_minor' => (int) $quote['total_minor'],
                'payment_method' => 'cod',
                'available_payment_methods' => ['cod'],
                'promotion_selection' => $quote['promotion_selection'] ?? [],
                'applied_promotions' => array_map(static fn (array $promotion): array => [
                    'source' => (string) $promotion['source'],
                    'name' => (string) $promotion['name'],
                    'discount_minor' => (int) $promotion['discount_minor'],
                    'shipping_discount_minor' => (int) $promotion['shipping_discount_minor'],
                ], $quote['applied_promotions'] ?? []),
                'quote_token' => (string) $quote['quote_token'],
                'quote_expires_at' => (string) $quote['quote_expires_at'],
            ],
            'message' => 'Checkout quote generated.',
        ]);
    }
}
