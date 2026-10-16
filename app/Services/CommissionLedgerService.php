<?php

namespace App\Services;

use App\Enums\SellerOrderStatus;
use App\Models\Payment;
use App\Models\PlatformCommission;
use App\Models\SellerTransaction;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CommissionLedgerService
{
    public function __construct(private readonly SellerWalletService $wallets) {}

    public function finalize(Payment $payment): void
    {
        DB::transaction(function () use ($payment): void {
            $payment = Payment::query()
                ->with('order.sellerOrders.store.sellerProfile')
                ->lockForUpdate()
                ->findOrFail($payment->id);

            abort_unless($payment->status === 'paid', 422, 'Only paid payments can be commissioned.');

            $rate = (float) (SystemSetting::query()->where('key', 'commission_rate')->value('value') ?? 10);
            $rateBps = (int) round($rate * 100);

            foreach ($payment->order->sellerOrders as $sellerOrder) {
                if ($sellerOrder->status === SellerOrderStatus::Cancelled->value) {
                    continue;
                }

                $commission = PlatformCommission::query()->firstOrCreate(
                    [
                        'seller_order_id' => $sellerOrder->id,
                        'refund_id' => null,
                        'type' => 'sale',
                    ],
                    [
                        'base_amount_minor' => $sellerOrder->total_minor,
                        'rate_bps' => $rateBps,
                        'amount_minor' => (int) round($sellerOrder->total_minor * $rateBps / 10000),
                        'status' => 'finalized',
                        'calculated_at' => now(),
                        'finalized_at' => now(),
                    ],
                );

                $sellerTransaction = SellerTransaction::query()->firstOrCreate(
                    [
                        'seller_order_id' => $sellerOrder->id,
                        'payment_id' => $payment->id,
                        'type' => 'sale',
                    ],
                    [
                        'transaction_no' => 'STX-'.Str::upper(Str::random(12)),
                        'seller_profile_id' => $sellerOrder->store->seller_profile_id,
                        'commission_id' => $commission->id,
                        'amount_minor' => $sellerOrder->total_minor - $commission->amount_minor,
                        'status' => 'posted',
                        'available_at' => now(),
                        'posted_at' => now(),
                        'description' => 'Net proceeds for '.$sellerOrder->seller_order_no,
                    ],
                );

                $this->wallets->recordPendingEarning($sellerTransaction);
            }
        }, attempts: 3);
    }
}
