<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\CheckoutService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class MobileBuyerCheckoutPlaceController extends Controller
{
    public function __invoke(Request $request, CheckoutService $checkout): JsonResponse
    {
        $data = $request->validate([
            'checkout_request_key' => ['required', 'uuid'],
            'quote_token' => ['required', 'string', 'max:8192'],
            'address_id' => ['required', 'integer', 'min:1'],
            'cart_item_ids' => ['required', 'array', 'min:1', 'max:100'],
            'cart_item_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'payment_method' => ['required', 'string', 'in:cod'],
            'seller_note' => ['nullable', 'string', 'max:120'],
            'platform_voucher_id' => ['nullable', 'integer', 'min:1'],
            'store_voucher_id' => ['nullable', 'integer', 'min:1'],
            'voucher_code' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/'],
        ]);

        try {
            $order = $checkout->place(
                $request->user(),
                $data['cart_item_ids'],
                (int) $data['address_id'],
                (string) $data['checkout_request_key'],
                (string) $data['quote_token'],
                filled($data['seller_note'] ?? null) ? trim((string) $data['seller_note']) : null,
                $this->promotionSelection($data),
                (string) $data['payment_method'],
            );
            $order->loadMissing(['sellerOrders.store', 'sellerOrders.items', 'payments']);
            $orderData = $this->orderResponse($order);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (HttpExceptionInterface $exception) {
            if ($exception->getStatusCode() === 409) {
                return response()->json([
                    'message' => $exception->getMessage(),
                    'error' => ['code' => 'checkout_request_conflict'],
                ], 409);
            }

            throw $exception;
        } catch (Throwable $exception) {
            Log::error('Mobile checkout placement failed.', [
                'exception_class' => $exception::class,
                'database_error' => $exception instanceof QueryException,
            ]);

            return response()->json([
                'message' => 'The order could not be placed. Retry with the same checkout request key.',
                'error' => ['code' => 'checkout_placement_failed'],
            ], 500);
        }

        return response()->json([
            'data' => $orderData,
            'message' => 'Order placed.',
        ]);
    }

    private function promotionSelection(array $data): array
    {
        return [
            'platform_voucher_id' => $data['platform_voucher_id'] ?? null,
            'store_voucher_id' => $data['store_voucher_id'] ?? null,
            'voucher_code' => filled($data['voucher_code'] ?? null)
                ? strtoupper(trim((string) $data['voucher_code']))
                : null,
        ];
    }

    /** @return array<string, mixed> */
    private function orderResponse(Order $order): array
    {
        $payment = $order->payments->first();

        return [
            'id' => (int) $order->id,
            'order_reference' => (string) $order->order_no,
            'status' => (string) $order->status,
            'payment_method' => (string) ($payment?->method ?? 'cod'),
            'payment_status' => (string) $order->payment_status,
            'initial_payment_state' => 'pending',
            'currency' => (string) $order->currency,
            'subtotal_minor' => (int) $order->subtotal_minor,
            'shipping_fee_minor' => (int) $order->shipping_fee_minor,
            'discount_minor' => (int) $order->discount_minor,
            'platform_discount_minor' => (int) ($order->platform_discount_minor ?? 0),
            'campaign_platform_discount_minor' => (int) ($order->campaign_platform_discount_minor ?? 0),
            'shipping_discount_minor' => (int) ($order->platform_shipping_discount_minor ?? 0),
            'total_minor' => (int) $order->total_minor,
            'created_at' => $order->created_at?->toIso8601String(),
            'placed_at' => $order->placed_at ? Carbon::parse($order->placed_at)->toIso8601String() : null,
            'seller_orders' => $order->sellerOrders
                ->sortBy('id')
                ->values()
                ->map(static fn ($sellerOrder): array => [
                    'id' => (int) $sellerOrder->id,
                    'seller_order_reference' => (string) $sellerOrder->seller_order_no,
                    'store_id' => (int) $sellerOrder->store_id,
                    'store_name' => (string) ($sellerOrder->store?->name ?? ''),
                    'status' => (string) $sellerOrder->status,
                    'item_count' => (int) $sellerOrder->items->sum('quantity'),
                ])->all(),
        ];
    }
}
