<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\BuyerCatalogService;
use App\Services\BuyerOrderConfirmationService;
use App\Services\CheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\View\View;

class BuyerCheckoutController extends Controller
{
    public function index(Request $request): View
    {
        $buyer = $request->user();
        $name = trim((string) ($buyer->name ?: trim(implode(' ', array_filter([
            $buyer->first_name,
            $buyer->middle_initial,
            $buyer->last_name,
        ])))));

        return view('buyer.checkout', [
            'buyerName' => $name ?: 'Buyer',
            'buyerPhone' => (string) ($buyer->phone ?: $buyer->contact_number ?: ''),
            'checkoutRequestKey' => (string) Str::uuid(),
        ]);
    }

    public function quote(Request $request, CheckoutService $checkout): JsonResponse
    {
        $data = $request->validate([
            'address_id' => ['required', 'integer', 'min:1'],
            'cart_item_ids' => ['required', 'array', 'min:1', 'max:100'],
            'cart_item_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'platform_voucher_id' => ['nullable', 'integer', 'min:1'],
            'store_voucher_id' => ['nullable', 'integer', 'min:1'],
            'voucher_code' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/'],
        ]);

        return response()->json([
            'data' => $checkout->quote(
                $request->user(),
                $data['cart_item_ids'],
                (int) $data['address_id'],
                $this->promotionSelection($data),
            ),
            'message' => 'Shipping estimate calculated.',
        ]);
    }

    public function place(Request $request, CheckoutService $checkout): RedirectResponse
    {
        $data = $request->validate([
            'checkout_request_key' => ['required', 'uuid'],
            'address_id' => ['required', 'integer', 'min:1'],
            'cart_item_ids' => ['required', 'array', 'min:1', 'max:100'],
            'cart_item_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'quote_token' => ['required', 'string', 'max:8192'],
            'seller_note' => ['nullable', 'string', 'max:120'],
            'payment_method' => ['required', 'in:cod'],
            'platform_voucher_id' => ['nullable', 'integer', 'min:1'],
            'store_voucher_id' => ['nullable', 'integer', 'min:1'],
            'voucher_code' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/'],
        ]);

        $order = $checkout->place(
            $request->user(),
            $data['cart_item_ids'],
            (int) $data['address_id'],
            (string) $data['checkout_request_key'],
            (string) $data['quote_token'],
            filled($data['seller_note'] ?? null) ? trim((string) $data['seller_note']) : null,
            $this->promotionSelection($data),
        );

        return redirect()->route('checkout.confirmation', $order->order_no);
    }

    public function confirmation(Request $request, string $orderNo, BuyerOrderConfirmationService $confirmation, BuyerCatalogService $catalog): View
    {
        $order = Order::query()
            ->with(['sellerOrders.store', 'sellerOrders.items.product.images.variant', 'sellerOrders.items.variant', 'sellerOrders.shipments', 'payments'])
            ->where('buyer_id', $request->user()->id)
            ->where('order_no', $orderNo)
            ->firstOrFail();

        $itemImages = [];
        foreach ($order->sellerOrders as $sellerOrder) {
            foreach ($sellerOrder->items as $item) {
                $itemImages[$item->id] = $catalog->orderItemImageUrl($item);
            }
        }

        return view('buyer.checkout-confirmation', [
            'order' => $order,
            'canConfirmReceipt' => $confirmation->canConfirm($order),
            'receiptConfirmed' => $confirmation->isConfirmed($order),
            'itemImages' => $itemImages,
            'fulfillmentLabels' => $order->sellerOrders->mapWithKeys(fn ($sellerOrder) => [
                $sellerOrder->id => $this->fulfillmentLabel($sellerOrder),
            ])->all(),
            'placedAtLabel' => $order->placed_at || $order->created_at
                ? Carbon::parse($order->placed_at ?? $order->created_at)->format('M j, Y g:i A')
                : '—',
            'shippingAddressLabel' => implode(', ', array_filter([
                $order->recipient_name,
                $order->address_line,
                $order->barangay,
                $order->city_municipality,
                $order->province,
                $order->postal_code,
            ])) ?: '—',
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

    private function fulfillmentLabel($sellerOrder): string
    {
        if ($sellerOrder->status === 'completed') {
            return 'Completed';
        }

        if ($sellerOrder->status === 'cancelled') {
            return 'Cancelled';
        }

        $statuses = $sellerOrder->shipments->pluck('status')->filter()->unique()->values();

        if ($statuses->isNotEmpty() && $statuses->every(fn (string $status): bool => $status === 'delivered')) {
            return 'Delivered · awaiting your confirmation';
        }

        if ($statuses->contains('out_for_delivery')) {
            return 'Out for delivery';
        }

        if ($statuses->contains(fn (string $status): bool => in_array($status, ['picked_up', 'at_sorting_center', 'sorted', 'dispatched'], true))) {
            return 'In transit with Bearly Logistics';
        }

        return match ($sellerOrder->status) {
            'pending', 'placed' => 'Order placed',
            'accepted' => 'Confirmed by Seller',
            'packing' => 'Being prepared',
            'ready_for_pickup' => 'Ready for pickup',
            'handed_to_logistics' => 'Handed to Logistics',
            default => str_replace('_', ' ', ucfirst($sellerOrder->status)),
        };
    }

    public function cancel(Request $request, int $order, CheckoutService $checkout): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);
        $model = $checkout->cancel(
            $request->user(),
            $order,
            trim((string) ($data['reason'] ?? 'Buyer requested cancellation')) ?: 'Buyer requested cancellation',
        );

        return redirect()->route('checkout.confirmation', $model->order_no)
            ->with('success', 'The order was cancelled and reserved stock was released.');
    }

    public function confirmReceipt(Request $request, int $order, BuyerOrderConfirmationService $confirmation): RedirectResponse
    {
        $model = $confirmation->confirmReceipt($request->user(), $order);

        return redirect()->route('checkout.confirmation', $model->order_no)
            ->with('success', 'Receipt confirmed. This order is now complete.');
    }
}
