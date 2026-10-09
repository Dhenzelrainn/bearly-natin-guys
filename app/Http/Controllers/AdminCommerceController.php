<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Payment;
use App\Services\AuditService;
use App\Services\CommissionLedgerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AdminCommerceController extends Controller
{
    public function orders(Request $request): View
    {
        $orders = Order::with(['buyer', 'sellerOrders.store', 'sellerOrders.items', 'payments'])->latest()->paginate(15);

        return view('admin.finance.orders', $this->base($request) + compact('orders'));
    }

    public function confirmPayment(Request $request, Payment $payment, CommissionLedgerService $ledger, AuditService $audit): RedirectResponse
    {
        $data = $request->validate(['provider_reference' => ['required', 'string', 'max:150']]);
        DB::transaction(function () use ($request, $payment, $data, $ledger, $audit) {
            $payment = Payment::lockForUpdate()->findOrFail($payment->id);
            $old = $payment->status;
            $payment->update(['status' => 'paid', 'provider_reference' => $data['provider_reference'], 'authorized_at' => $payment->authorized_at ?? now(), 'paid_at' => now()]);
            $payment->order()->update(['payment_status' => 'paid']);
            $ledger->finalize($payment);
            $audit->record('payment.confirmed', 'finance', $payment, 'Administrator confirmed an order payment.', ['status' => $old], ['status' => 'paid', 'reference' => $data['provider_reference']], 'warning', $request);
        }, 3);

        return back()->with('success', 'Payment confirmed and commission ledger finalized.');
    }

    private function base(Request $request): array
    {
        $admin = $request->user();

        return ['admin' => ['name' => $admin->name, 'role' => 'Admin', 'email' => $admin->email, 'initials' => collect(explode(' ', $admin->name))->map(fn ($x) => strtoupper(substr($x, 0, 1)))->take(2)->implode('')], 'topNotifications' => []];
    }
}
