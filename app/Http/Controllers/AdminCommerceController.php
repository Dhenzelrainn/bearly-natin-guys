<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Payment;
use App\Services\AuditService;
use App\Services\BuyerCatalogService;
use App\Services\CommissionLedgerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AdminCommerceController extends Controller
{
    public function orders(Request $request, BuyerCatalogService $catalog): View
    {
        $orders = Order::with([
            'buyer',
            'sellerOrders.store',
            'sellerOrders.items.product.images.variant',
            'sellerOrders.items.variant',
            'payments',
        ])->latest()->paginate(15);

        $orders->getCollection()->each(function (Order $order) use ($catalog): void {
            $order->sellerOrders->each(function ($sellerOrder) use ($catalog): void {
                $sellerOrder->setAttribute('item_previews', $sellerOrder->items->map(fn ($item): array => [
                    'name' => $item->product_name ?: 'Unnamed product',
                    'variant' => trim((string) $item->variant_name),
                    'quantity' => (int) $item->quantity,
                    'price' => '₱'.number_format((int) $item->subtotal_minor / 100, 2),
                    'image_url' => $catalog->orderItemImageUrl($item),
                ])->values()->all());
            });
        });

        return view('admin.finance.orders', $this->base($request) + compact('orders'));
    }

    public function confirmPayment(Request $request, Payment $payment, CommissionLedgerService $ledger, AuditService $audit): RedirectResponse
    {
        $data = $request->validate(['provider_reference' => ['required', 'string', 'max:150']]);
        $confirmed = DB::transaction(function () use ($request, $payment, $data, $ledger, $audit): bool {
            $paymentStub = Payment::query()->findOrFail($payment->id);
            $order = Order::query()->whereKey($paymentStub->order_id)->lockForUpdate()->firstOrFail();
            $payment = Payment::query()->whereKey($paymentStub->id)->where('order_id', $order->id)->lockForUpdate()->firstOrFail();

            if ($payment->status === 'paid') {
                return false;
            }

            abort_if(
                $payment->method === 'cod',
                409,
                'COD can only be confirmed from the assigned Rider\'s recorded cash collection.'
            );

            abort_unless(
                in_array($payment->status, ['pending', 'authorized'], true)
                    && $order->status !== 'cancelled',
                409,
                'Only a pending or authorized payment for a non-cancelled order can be confirmed.'
            );

            $old = $payment->status;
            $payment->update(['status' => 'paid', 'provider_reference' => trim($data['provider_reference']), 'authorized_at' => $payment->authorized_at ?? now(), 'paid_at' => now()]);
            $order->update(['payment_status' => 'paid']);
            $ledger->finalize($payment);
            $audit->record('payment.confirmed', 'finance', $payment, 'Administrator confirmed an order payment.', ['status' => $old], ['status' => 'paid', 'reference' => trim($data['provider_reference'])], 'warning', $request);

            return true;
        }, 3);

        return back()->with('success', $confirmed
            ? 'Payment confirmed and commission ledger finalized.'
            : 'Payment was already confirmed; no changes were made.');
    }

    private function base(Request $request): array
    {
        $admin = $request->user();

        return ['admin' => ['name' => $admin->name, 'role' => 'Admin', 'email' => $admin->email, 'initials' => collect(explode(' ', $admin->name))->map(fn ($x) => strtoupper(substr($x, 0, 1)))->take(2)->implode('')], 'topNotifications' => []];
    }
}
