<?php

namespace App\Services;

use App\Models\SellerPayout;
use App\Models\SellerPayoutItem;
use App\Models\SellerProfile;
use App\Models\SellerTransaction;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class SellerPayoutService
{
    public function __construct(private readonly AuditService $audit) {}

    public function eligibleBySeller(): array
    {
        return $this->eligibleTransactionsQuery()
            ->with(['sellerProfile.store', 'sellerOrder.store', 'commission'])
            ->get()
            ->groupBy('seller_profile_id')
            ->map(function ($transactions): array {
                $profile = $transactions->first()->sellerProfile;
                $grossMinor = (int) $transactions->sum(fn (SellerTransaction $transaction): int => (int) ($transaction->commission?->base_amount_minor ?? 0));
                $commissionMinor = (int) $transactions->sum(fn (SellerTransaction $transaction): int => (int) ($transaction->commission?->amount_minor ?? 0));

                return [
                    'seller_profile_id' => (int) $profile->id,
                    'seller' => $profile->store?->name ?: ($profile->legal_business_name ?: 'Seller'),
                    'orders' => $transactions->count(),
                    'gross_minor' => $grossMinor,
                    'commission_minor' => $commissionMinor,
                    'net_minor' => (int) $transactions->sum('amount_minor'),
                    'oldest_posted_at' => $transactions->min('posted_at'),
                    'latest_posted_at' => $transactions->max('posted_at'),
                ];
            })
            ->values()
            ->all();
    }

    public function generateForSeller(User $admin, int $sellerProfileId, string $reason, Request $request): SellerPayout
    {
        $this->assertAdmin($admin);
        if (mb_strlen(trim($reason)) < 5) {
            throw ValidationException::withMessages(['reason' => 'Provide a short reason for generating this Seller settlement.']);
        }

        return DB::transaction(function () use ($sellerProfileId, $reason, $request): SellerPayout {
            $seller = SellerProfile::query()->whereKey($sellerProfileId)->lockForUpdate()->firstOrFail();
            $transactions = $this->eligibleTransactionsQuery($seller->id)
                ->with(['commission', 'sellerOrder.shipments.codCashCollection'])
                ->orderBy('posted_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($transactions->isEmpty()) {
                throw ValidationException::withMessages(['seller' => 'This Seller has no COD proceeds that are ready for settlement.']);
            }

            $grossMinor = 0;
            $commissionMinor = 0;
            $netMinor = 0;
            foreach ($transactions as $transaction) {
                $commission = $transaction->commission;
                if (! $commission || (int) $transaction->amount_minor < 0) {
                    throw ValidationException::withMessages(['seller' => 'A Seller transaction is missing its finalized commission data.']);
                }
                $grossMinor += (int) $commission->base_amount_minor;
                $commissionMinor += (int) $commission->amount_minor;
                $netMinor += (int) $transaction->amount_minor;
            }
            if ($netMinor <= 0 || $grossMinor - $commissionMinor !== $netMinor) {
                throw ValidationException::withMessages(['seller' => 'The COD settlement ledger does not reconcile to the Seller net.']);
            }

            $postedAt = $transactions->pluck('posted_at')->filter();
            $payout = SellerPayout::query()->create([
                'payout_no' => 'SPY-'.Str::upper((string) Str::ulid()),
                'seller_profile_id' => $seller->id,
                'period_start' => $postedAt->min()?->toDateString() ?? now()->toDateString(),
                'period_end' => $postedAt->max()?->toDateString() ?? now()->toDateString(),
                'gross_minor' => $grossMinor,
                'commission_minor' => $commissionMinor,
                'adjustment_minor' => 0,
                'net_minor' => $netMinor,
                'status' => 'pending',
                'due_at' => now(),
            ]);

            foreach ($transactions as $transaction) {
                SellerPayoutItem::query()->create([
                    'seller_payout_id' => $payout->id,
                    'seller_transaction_id' => $transaction->id,
                    'gross_minor' => (int) $transaction->commission->base_amount_minor,
                    'commission_minor' => (int) $transaction->commission->amount_minor,
                    'net_minor' => (int) $transaction->amount_minor,
                ]);
            }

            $this->audit->record(
                action: 'seller_payout.generated',
                module: 'Finance',
                subject: $payout,
                description: "COD Seller settlement {$payout->payout_no} was generated after Finance reconciliation.",
                oldValues: [],
                newValues: [
                    'seller_profile_id' => $seller->id,
                    'transaction_count' => $transactions->count(),
                    'gross_minor' => $grossMinor,
                    'commission_minor' => $commissionMinor,
                    'net_minor' => $netMinor,
                    'reason' => trim($reason),
                ],
                severity: 'warning',
                request: $request,
            );

            return $payout->fresh(['sellerProfile.store', 'items.transaction']);
        }, attempts: 3);
    }

    public function markPaid(
        User $admin,
        int $payoutId,
        int $actualAmountMinor,
        string $method,
        string $reference,
        string $paidOn,
        string $reason,
        UploadedFile $receipt,
        Request $request,
    ): SellerPayout {
        $this->assertAdmin($admin);
        $reference = trim($reference);
        if (! in_array($method, ['gcash', 'bank_transfer', 'cash', 'other'], true)) {
            throw ValidationException::withMessages(['method' => 'Choose a valid external payout method.']);
        }
        if ($reference === '' || mb_strlen(trim($reason)) < 5) {
            throw ValidationException::withMessages([
                'reference' => $reference === '' ? 'Enter the completed Seller payout reference.' : 'Enter a reason for recording the completed payout.',
            ]);
        }
        if (! $request->boolean('external_payout_confirmed')) {
            throw ValidationException::withMessages(['external_payout_confirmed' => 'Confirm that the Seller payout has already been made outside Bearly.']);
        }
        if (! $receipt->isValid()
            || ! in_array($receipt->getMimeType(), ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'], true)) {
            throw ValidationException::withMessages(['receipt' => 'Upload a valid external payout receipt or Seller acknowledgment.']);
        }

        $existing = SellerPayout::query()->whereKey($payoutId)->firstOrFail();
        if ($existing->status === 'paid'
            && $existing->paid_at
            && (int) $existing->net_minor === $actualAmountMinor
            && hash_equals((string) $existing->reference, $reference)) {
            return $existing->fresh(['sellerProfile.store', 'items.transaction']);
        }
        if ($actualAmountMinor !== (int) $existing->net_minor) {
            throw ValidationException::withMessages(['actual_amount' => 'Record the exact Seller payout amount of ₱'.number_format((int) $existing->net_minor / 100, 2).'.']);
        }

        $receiptPath = $receipt->store('seller-payout-receipts/'.$existing->payout_no, 'local');
        if (! $receiptPath) {
            throw ValidationException::withMessages(['receipt' => 'The payout receipt could not be stored.']);
        }

        try {
            return DB::transaction(function () use ($admin, $payoutId, $actualAmountMinor, $method, $reference, $paidOn, $reason, $receipt, $receiptPath, $request): SellerPayout {
                $payout = SellerPayout::query()->whereKey($payoutId)->lockForUpdate()->firstOrFail();
                if ($payout->status !== 'pending' || $payout->paid_at !== null) {
                    throw new ConflictHttpException('Only an unpaid Seller settlement can be marked paid.');
                }
                if ($actualAmountMinor !== (int) $payout->net_minor) {
                    throw ValidationException::withMessages(['actual_amount' => 'Record the exact Seller payout amount of ₱'.number_format((int) $payout->net_minor / 100, 2).'.']);
                }
                if (! $payout->items()->exists()) {
                    throw ValidationException::withMessages(['payout' => 'This payout has no linked COD settlement items.']);
                }

                try {
                    $paidDate = Carbon::createFromFormat('!Y-m-d', $paidOn, config('app.timezone'));
                } catch (\Throwable) {
                    $paidDate = null;
                }
                if (! $paidDate || $paidDate->greaterThan(Carbon::today(config('app.timezone')))) {
                    throw ValidationException::withMessages(['paid_on' => 'Enter the actual payout date; future dates are not allowed.']);
                }

                $oldValues = ['status' => $payout->status, 'paid_at' => null, 'reference' => $payout->reference];
                $payout->update([
                    'status' => 'paid',
                    'paid_at' => $paidDate->startOfDay(),
                    'reference' => $reference,
                    'external_method' => $method,
                    'receipt_disk' => 'local',
                    'receipt_path' => $receiptPath,
                    'receipt_mime_type' => $receipt->getMimeType(),
                    'receipt_original_name' => $this->safeOriginalName($receipt->getClientOriginalName()),
                    'receipt_size_bytes' => $receipt->getSize(),
                    'paid_by' => $admin->id,
                    'payment_reason' => trim($reason),
                ]);
                $this->audit->record(
                    action: 'seller_payout.externally_paid',
                    module: 'Finance',
                    subject: $payout,
                    description: "Seller settlement {$payout->payout_no} was marked paid after external verification.",
                    oldValues: $oldValues,
                    newValues: [
                        'status' => 'paid',
                        'amount_minor' => (int) $payout->net_minor,
                        'paid_on' => $paidDate->toDateString(),
                        'reference' => $reference,
                        'method' => $method,
                        'reason' => trim($reason),
                        'confirmed_externally' => true,
                        'receipt_original_name' => $payout->receipt_original_name,
                        'receipt_size_bytes' => $payout->receipt_size_bytes,
                    ],
                    severity: 'warning',
                    request: $request,
                );

                return $payout->fresh(['sellerProfile.store', 'items.transaction']);
            }, attempts: 3);
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($receiptPath);
            throw $exception;
        }
    }

    private function eligibleTransactionsQuery(?int $sellerProfileId = null)
    {
        return SellerTransaction::query()
            ->where('type', 'sale')
            ->where('status', 'posted')
            ->whereNotNull('posted_at')
            ->where('amount_minor', '>', 0)
            ->when($sellerProfileId, fn ($query) => $query->where('seller_profile_id', $sellerProfileId))
            ->whereDoesntHave('payoutItem')
            ->whereHas('payment', fn ($query) => $query->where('method', 'cod')->where('status', 'paid')->whereNotNull('paid_at'))
            ->whereHas('sellerOrder', function ($query): void {
                $query->where('status', 'completed')
                    ->whereHas('shipments', fn ($shipments) => $shipments
                        ->where('purpose', 'outbound')
                        ->where('cod_amount_minor', '>', 0))
                    ->whereDoesntHave('shipments', fn ($shipments) => $shipments
                        ->where('purpose', 'outbound')
                        ->where('cod_amount_minor', '>', 0)
                        ->whereDoesntHave('codCashCollection', fn ($collection) => $collection->where('status', 'reconciled')));
            });
    }

    private function assertAdmin(User $admin): void
    {
        if ($admin->role !== 'admin' || $admin->status !== 'active') {
            throw new AuthorizationException('An active Admin/Finance user is required.');
        }
    }

    private function safeOriginalName(string $name): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._ -]/', '_', basename(str_replace('\\', '/', $name))) ?: 'seller-payout-receipt';

        return Str::limit($safe, 200, '');
    }
}
