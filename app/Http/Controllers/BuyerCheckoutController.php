<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\CheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
            'buyerPhone' => (string) ($buyer->phone ?? ''),
            'checkoutRequestKey' => (string) Str::uuid(),
        ]);
    }

    public function quote(Request $request, CheckoutService $checkout): JsonResponse
    {
        $data = $request->validate([
            'address_id' => ['required', 'integer', 'min:1'],
            'cart_item_ids' => ['required', 'array', 'min:1', 'max:100'],
            'cart_item_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ]);

        return response()->json([
            'data' => $checkout->quote($request->user(), $data['cart_item_ids'], (int) $data['address_id']),
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
        ]);

        $order = $checkout->place(
            $request->user(),
            $data['cart_item_ids'],
            (int) $data['address_id'],
            (string) $data['checkout_request_key'],
            (string) $data['quote_token'],
            filled($data['seller_note'] ?? null) ? trim((string) $data['seller_note']) : null,
        );

        return redirect()->route('checkout.confirmation', $order->order_no);
    }

    public function confirmation(Request $request, string $orderNo): View
    {
        $order = Order::query()
            ->with(['sellerOrders.store', 'sellerOrders.items', 'payments'])
            ->where('buyer_id', $request->user()->id)
            ->where('order_no', $orderNo)
            ->firstOrFail();

        return view('buyer.checkout-confirmation', ['order' => $order]);
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
}
