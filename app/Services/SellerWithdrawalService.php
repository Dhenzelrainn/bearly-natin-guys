<?php

namespace App\Services;

use App\Enums\SellerWithdrawalStatus;
use App\Models\SellerBankAccount;
use App\Models\SellerProfile;
use App\Models\SellerWallet;
use App\Models\SellerWithdrawal;
use App\Models\SellerWithdrawalStatusHistory;
use App\Models\SellerWithdrawalTransfer;
use App\Models\User;
use App\Notifications\SellerWithdrawalStatusNotification;
use App\Notifications\WorkflowNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class SellerWithdrawalService
{
    public function __construct(
        private readonly SellerWalletService $wallets,
        private readonly AuditService $audit,
        private readonly NotificationService $notifications,
    ) {}

    /** @param array{bank_name:string,account_name:string,account_number:string} $data */
    public function addBankAccount(User $seller, array $data, Request $request): SellerBankAccount
    {
        $profile = $this->sellerProfile($seller);
        $accountNumber = preg_replace('/\D/', '', $data['account_number']);
        if (! is_string($accountNumber) || strlen($accountNumber) < 6 || strlen($accountNumber) > 34) {
            throw ValidationException::withMessages(['account_number' => 'Enter a bank account number with 6 to 34 digits.']);
        }

        return DB::transaction(function () use ($seller, $profile, $data, $accountNumber, $request): SellerBankAccount {
            $profile = SellerProfile::query()->whereKey($profile->id)->lockForUpdate()->firstOrFail();
            $isFirst = ! $profile->bankAccounts()->where('status', 'active')->exists();
            $account = SellerBankAccount::query()->create([
                'seller_profile_id' => $profile->id,
                'bank_name' => trim($data['bank_name']),
                'account_name' => trim($data['account_name']),
                'account_number' => $accountNumber,
                'account_number_last4' => substr($accountNumber, -4),
                'status' => 'active',
                'is_default' => $isFirst,
                'created_by' => $seller->id,
            ]);

            $this->audit->record(
                action: 'seller_bank_account.added',
                module: 'Seller Wallet',
                subject: $account,
                description: 'Seller linked a bank account for manual withdrawal processing.',
                newValues: [
                    'seller_profile_id' => (int) $profile->id,
                    'bank_name' => $account->bank_name,
                    'account_number_last4' => $account->account_number_last4,
                    'is_default' => $account->is_default,
                ],
                request: $request,
            );

            return $account;
        }, attempts: 3);
    }

    public function deactivateBankAccount(User $seller, int $accountId, Request $request): SellerBankAccount
    {
        $profile = $this->sellerProfile($seller);

        return DB::transaction(function () use ($profile, $accountId, $request): SellerBankAccount {
            SellerProfile::query()->whereKey($profile->id)->lockForUpdate()->firstOrFail();
            $account = SellerBankAccount::query()
                ->where('seller_profile_id', $profile->id)
                ->whereKey($accountId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($account->status === 'inactive') {
                return $account;
            }

            $wasDefault = (bool) $account->is_default;
            $account->update(['status' => 'inactive', 'is_default' => false]);

            if (! $profile->bankAccounts()->where('status', 'active')->where('is_default', true)->exists()) {
                $profile->bankAccounts()
                    ->where('status', 'active')
                    ->orderBy('id')
                    ->limit(1)
                    ->update(['is_default' => true]);
            }

            $this->audit->record(
                action: 'seller_bank_account.deactivated',
                module: 'Seller Wallet',
                subject: $account,
                description: 'Seller deactivated a linked bank account; the record remains for withdrawal history.',
                oldValues: ['status' => 'active', 'is_default' => $wasDefault],
                newValues: ['status' => 'inactive', 'account_number_last4' => $account->account_number_last4],
                request: $request,
            );

            return $account->fresh();
        }, attempts: 3);
    }

    public function requestWithdrawal(
        User $seller,
        int $amountMinor,
        int $bankAccountId,
        string $idempotencyKey,
        Request $request,
    ): SellerWithdrawal {
        $profile = $this->sellerProfile($seller);
        $this->wallets->refreshForSeller($profile);

        return DB::transaction(function () use ($seller, $profile, $amountMinor, $bankAccountId, $idempotencyKey, $request): SellerWithdrawal {
            $profile = SellerProfile::query()->whereKey($profile->id)->lockForUpdate()->firstOrFail();
            $existing = SellerWithdrawal::query()
                ->where('seller_profile_id', $profile->id)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if ((int) $existing->amount_minor !== $amountMinor
                    || (int) $existing->seller_bank_account_id !== $bankAccountId) {
                    throw new ConflictHttpException('This withdrawal submission key was already used for different details. Refresh the page and submit again.');
                }

                return $existing->fresh(['bankAccount', 'transfer']);
            }

            $wallet = SellerWallet::query()
                ->where('seller_profile_id', $profile->id)
                ->lockForUpdate()
                ->firstOrFail();
            $account = SellerBankAccount::query()
                ->where('seller_profile_id', $profile->id)
                ->where('status', 'active')
                ->whereKey($bankAccountId)
                ->lockForUpdate()
                ->first();

            if (! $account) {
                throw ValidationException::withMessages(['seller_bank_account_id' => 'Choose one of your active linked bank accounts.']);
            }
            if ($amountMinor < 1) {
                throw ValidationException::withMessages(['amount' => 'Enter a withdrawal amount greater than zero.']);
            }
            if ((int) $wallet->available_balance_minor < $amountMinor) {
                throw ValidationException::withMessages(['amount' => 'The withdrawal amount cannot exceed your available balance.']);
            }

            $withdrawal = SellerWithdrawal::query()->create([
                'withdrawal_no' => 'WDR-'.Str::upper((string) Str::ulid()),
                'seller_profile_id' => $profile->id,
                'seller_wallet_id' => $wallet->id,
                'seller_bank_account_id' => $account->id,
                'amount_minor' => $amountMinor,
                'bank_name_snapshot' => $account->bank_name,
                'account_name_snapshot' => $account->account_name,
                'account_number_last4' => $account->account_number_last4,
                'status' => SellerWithdrawalStatus::PendingReview->value,
                'idempotency_key' => $idempotencyKey,
                'requested_at' => now(),
            ]);

            $this->wallets->reserveForWithdrawal($withdrawal, $seller);
            $this->recordStatus($withdrawal, null, SellerWithdrawalStatus::PendingReview, $seller, 'Seller submitted a withdrawal request.');
            $this->notifySeller($withdrawal, SellerWithdrawalStatus::PendingReview, 'Seller submitted a withdrawal request.');
            $this->notifyFinanceReview($withdrawal);
            $this->audit->record(
                action: 'seller_withdrawal.requested',
                module: 'Seller Wallet',
                subject: $withdrawal,
                description: "Seller requested withdrawal {$withdrawal->withdrawal_no}.",
                newValues: [
                    'seller_profile_id' => (int) $profile->id,
                    'amount_minor' => $amountMinor,
                    'bank_name' => $account->bank_name,
                    'account_number_last4' => $account->account_number_last4,
                    'status' => $withdrawal->status,
                ],
                severity: 'warning',
                request: $request,
            );

            return $withdrawal->fresh(['bankAccount', 'transfer']);
        }, attempts: 3);
    }

    public function approve(User $admin, int $withdrawalId, ?string $reason, Request $request): SellerWithdrawal
    {
        $this->assertFinance($admin);

        return DB::transaction(function () use ($admin, $withdrawalId, $reason, $request): SellerWithdrawal {
            $withdrawal = SellerWithdrawal::query()->whereKey($withdrawalId)->lockForUpdate()->firstOrFail();
            if ($withdrawal->status === SellerWithdrawalStatus::Approved->value) {
                return $withdrawal->fresh();
            }
            $this->assertTransition($withdrawal, SellerWithdrawalStatus::Approved);

            $withdrawal->update(['status' => SellerWithdrawalStatus::Approved->value, 'reviewed_by' => $admin->id, 'reviewed_at' => now()]);
            $this->recordStatus($withdrawal, SellerWithdrawalStatus::PendingReview, SellerWithdrawalStatus::Approved, $admin, $reason);
            $this->notifySeller($withdrawal, SellerWithdrawalStatus::Approved, $reason);
            $this->audit->record(
                action: 'seller_withdrawal.approved',
                module: 'Finance',
                subject: $withdrawal,
                description: "Finance approved withdrawal {$withdrawal->withdrawal_no}.",
                newValues: ['status' => $withdrawal->status, 'amount_minor' => (int) $withdrawal->amount_minor, 'reason' => $reason],
                severity: 'warning',
                request: $request,
            );

            return $withdrawal->fresh();
        }, attempts: 3);
    }

    public function reject(User $admin, int $withdrawalId, string $reason, Request $request): SellerWithdrawal
    {
        $this->assertFinance($admin);
        $reason = trim($reason);

        return DB::transaction(function () use ($admin, $withdrawalId, $reason, $request): SellerWithdrawal {
            $withdrawal = SellerWithdrawal::query()->whereKey($withdrawalId)->lockForUpdate()->firstOrFail();
            if ($withdrawal->status === SellerWithdrawalStatus::Rejected->value) {
                return $withdrawal->fresh();
            }
            if (! in_array($withdrawal->status, [SellerWithdrawalStatus::PendingReview->value, SellerWithdrawalStatus::Approved->value], true)) {
                throw new ConflictHttpException('Only a request that has not started processing can be rejected.');
            }
            $from = SellerWithdrawalStatus::from($withdrawal->status);
            $this->assertTransition($withdrawal, SellerWithdrawalStatus::Rejected);

            $this->wallets->releaseWithdrawalReservation($withdrawal, $admin, 'Reserved funds returned after Finance rejected the withdrawal.');
            $withdrawal->update([
                'status' => SellerWithdrawalStatus::Rejected->value,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
                'rejection_reason' => $reason,
            ]);
            $this->recordStatus($withdrawal, $from, SellerWithdrawalStatus::Rejected, $admin, $reason);
            $this->notifySeller($withdrawal, SellerWithdrawalStatus::Rejected, $reason);
            $this->audit->record(
                action: 'seller_withdrawal.rejected',
                module: 'Finance',
                subject: $withdrawal,
                description: "Finance rejected withdrawal {$withdrawal->withdrawal_no} and released the reserved funds.",
                oldValues: ['status' => $from->value],
                newValues: ['status' => $withdrawal->status, 'amount_minor' => (int) $withdrawal->amount_minor, 'reason' => $reason],
                severity: 'warning',
                request: $request,
            );

            return $withdrawal->fresh();
        }, attempts: 3);
    }

    public function startProcessing(User $admin, int $withdrawalId, string $reason, Request $request): SellerWithdrawal
    {
        $this->assertFinance($admin);
        $reason = trim($reason);

        return DB::transaction(function () use ($admin, $withdrawalId, $reason, $request): SellerWithdrawal {
            $withdrawal = SellerWithdrawal::query()->whereKey($withdrawalId)->lockForUpdate()->firstOrFail();
            if ($withdrawal->status === SellerWithdrawalStatus::Processing->value) {
                return $withdrawal->fresh();
            }
            $this->assertTransition($withdrawal, SellerWithdrawalStatus::Processing);
            $withdrawal->update([
                'status' => SellerWithdrawalStatus::Processing->value,
                'processing_started_by' => $admin->id,
                'processing_started_at' => now(),
            ]);
            $this->recordStatus($withdrawal, SellerWithdrawalStatus::Approved, SellerWithdrawalStatus::Processing, $admin, $reason);
            $this->notifySeller($withdrawal, SellerWithdrawalStatus::Processing, $reason);
            $this->audit->record(
                action: 'seller_withdrawal.processing_started',
                module: 'Finance',
                subject: $withdrawal,
                description: "Finance started processing withdrawal {$withdrawal->withdrawal_no}.",
                newValues: ['status' => $withdrawal->status, 'reason' => $reason],
                severity: 'warning',
                request: $request,
            );

            return $withdrawal->fresh(['bankAccount', 'transfer']);
        }, attempts: 3);
    }

    public function recordTransfer(
        User $admin,
        int $withdrawalId,
        int $amountMinor,
        ?string $bankReference,
        string $transferDate,
        string $outcome,
        bool $noSuccessfulTransferConfirmed,
        ?string $failureReason,
        ?UploadedFile $proof,
        Request $request,
    ): SellerWithdrawal {
        $this->assertFinance($admin);
        if (! in_array($outcome, ['succeeded', 'failed'], true)) {
            throw ValidationException::withMessages(['transfer_outcome' => 'Choose whether the transfer succeeded or failed.']);
        }
        $bankReference = trim((string) $bankReference);
        $failureReason = trim((string) $failureReason);
        if ($outcome === 'succeeded' && $bankReference === '') {
            throw ValidationException::withMessages(['bank_reference' => 'Enter the bank transaction reference for a successful transfer.']);
        }
        if ($bankReference !== '' && preg_match('/^(?:CODH|L2F|BFR)-/i', $bankReference) === 1) {
            throw ValidationException::withMessages(['bank_reference' => 'CODH, L2F, and BFR are COD cash-custody references, not Seller bank-transfer references.']);
        }
        if ($outcome === 'failed' && ! $noSuccessfulTransferConfirmed) {
            throw ValidationException::withMessages(['no_successful_transfer_confirmed' => 'Confirm that no successful transfer occurred before releasing these funds.']);
        }
        if ($outcome === 'failed' && mb_strlen($failureReason) < 5) {
            throw ValidationException::withMessages(['failure_reason' => 'Explain why the transfer failed.']);
        }
        if ($proof && (! $proof->isValid()
            || ! in_array($proof->getMimeType(), ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'], true))) {
            throw ValidationException::withMessages(['proof' => 'Upload a valid bank transfer proof file.']);
        }

        $proofPath = null;
        $storedProofPaths = [];

        try {
            $withdrawal = DB::transaction(function () use (
                $admin,
                $withdrawalId,
                $amountMinor,
                $bankReference,
                $transferDate,
                $outcome,
                $noSuccessfulTransferConfirmed,
                $failureReason,
                $proof,
                $request,
                &$proofPath,
                &$storedProofPaths,
            ): SellerWithdrawal {
                $withdrawal = SellerWithdrawal::query()
                    ->whereKey($withdrawalId)
                    ->lockForUpdate()
                    ->firstOrFail();

                $existingTransfer = SellerWithdrawalTransfer::query()
                    ->where('seller_withdrawal_id', $withdrawal->id)
                    ->lockForUpdate()
                    ->first();
                if ($existingTransfer) {
                    $existingOutcome = $existingTransfer->status === 'failed' ? 'failed' : 'succeeded';
                    $sameFailureReason = $outcome !== 'failed'
                        || $existingTransfer->failure_reason === $failureReason;
                    if ((int) $existingTransfer->amount_minor === $amountMinor
                        && $existingTransfer->bank_reference === ($bankReference !== '' ? $bankReference : null)
                        && $existingTransfer->transfer_date->toDateString() === $transferDate
                        && $existingOutcome === $outcome
                        && $sameFailureReason) {
                        return $withdrawal->fresh(['bankAccount', 'transfer']);
                    }

                    throw new ConflictHttpException('A bank transfer record already exists for this withdrawal. Do not record or pay it again.');
                }

                if ($withdrawal->status !== SellerWithdrawalStatus::Processing->value) {
                    throw new ConflictHttpException('Only a withdrawal in Processing can have a bank transfer recorded.');
                }
                if ($amountMinor !== (int) $withdrawal->amount_minor) {
                    throw ValidationException::withMessages(['transfer_amount' => 'The transfer amount must exactly match the reserved withdrawal amount.']);
                }
                $withdrawal->load('bankAccount');
                if (! $withdrawal->bankAccount || $withdrawal->bank_name_snapshot !== $withdrawal->bankAccount->bank_name
                    || $withdrawal->account_name_snapshot !== $withdrawal->bankAccount->account_name
                    || $withdrawal->account_number_last4 !== $withdrawal->bankAccount->account_number_last4) {
                    throw new ConflictHttpException('The withdrawal bank-account details no longer match their saved snapshot. Stop and review the request.');
                }

                try {
                    $date = Carbon::createFromFormat('!Y-m-d', $transferDate, config('app.timezone'));
                } catch (\Throwable) {
                    $date = null;
                }
                if (! $date || $date->greaterThan(Carbon::today(config('app.timezone')))) {
                    throw ValidationException::withMessages(['transfer_date' => 'Enter the actual bank transfer date; future dates are not allowed.']);
                }

                if ($proof) {
                    $proofPath = $proof->store('seller-withdrawal-proofs/'.$withdrawal->withdrawal_no, 'local');
                    if (! $proofPath) {
                        throw ValidationException::withMessages(['proof' => 'The bank transfer proof could not be stored.']);
                    }
                    $storedProofPaths[] = $proofPath;
                }

                $transfer = SellerWithdrawalTransfer::query()->create([
                    'transfer_no' => 'SWT-'.Str::upper((string) Str::ulid()),
                    'seller_withdrawal_id' => $withdrawal->id,
                    'amount_minor' => $amountMinor,
                    'bank_name' => $withdrawal->bank_name_snapshot,
                    'account_number_last4' => $withdrawal->account_number_last4,
                    'bank_reference' => $bankReference !== '' ? $bankReference : null,
                    'transfer_date' => $date->toDateString(),
                    'status' => $outcome === 'succeeded' ? 'pending_verification' : 'failed',
                    'proof_disk' => $proofPath ? 'local' : null,
                    'proof_path' => $proofPath,
                    'proof_mime_type' => $proof?->getMimeType(),
                    'proof_original_name' => $proof ? $this->safeOriginalName($proof->getClientOriginalName()) : null,
                    'proof_size_bytes' => $proof?->getSize(),
                    'recorded_by' => $admin->id,
                    'recorded_at' => now(),
                    'failure_confirmed_by' => $outcome === 'failed' ? $admin->id : null,
                    'failure_confirmed_at' => $outcome === 'failed' ? now() : null,
                    'failure_reason' => $outcome === 'failed' ? $failureReason : null,
                ]);

                $this->audit->record(
                    action: $outcome === 'failed' ? 'seller_withdrawal.transfer_failed' : 'seller_withdrawal.transfer_recorded',
                    module: 'Finance',
                    subject: $withdrawal,
                    description: $outcome === 'failed'
                        ? "Finance recorded a failed bank transfer for {$withdrawal->withdrawal_no} after confirming no successful transfer occurred."
                        : "Finance recorded a bank transfer for {$withdrawal->withdrawal_no}; a separate verification is still required.",
                    newValues: [
                        'transfer_no' => $transfer->transfer_no,
                        'amount_minor' => $amountMinor,
                        'bank_name' => $transfer->bank_name,
                        'account_number_last4' => $transfer->account_number_last4,
                        'bank_reference' => $transfer->bank_reference,
                        'transfer_date' => $date->toDateString(),
                        'transfer_status' => $transfer->status,
                        'proof_original_name' => $transfer->proof_original_name,
                        'failure_reason' => $outcome === 'failed' ? $failureReason : null,
                    ],
                    severity: 'warning',
                    request: $request,
                );

                if ($outcome === 'failed') {
                    if (! $noSuccessfulTransferConfirmed) {
                        throw ValidationException::withMessages(['no_successful_transfer_confirmed' => 'Confirm that no successful transfer occurred before releasing these funds.']);
                    }
                    $this->wallets->releaseWithdrawalReservation($withdrawal, $admin, 'Reserved funds returned after Finance confirmed no successful bank transfer occurred.');
                    $withdrawal->update([
                        'status' => SellerWithdrawalStatus::Failed->value,
                        'failed_at' => now(),
                        'failure_reason' => $failureReason,
                    ]);
                    $this->recordStatus($withdrawal, SellerWithdrawalStatus::Processing, SellerWithdrawalStatus::Failed, $admin, $failureReason);
                    $this->notifySeller($withdrawal, SellerWithdrawalStatus::Failed, $failureReason);
                    $this->audit->record(
                        action: 'seller_withdrawal.failed',
                        module: 'Finance',
                        subject: $withdrawal,
                        description: "Finance failed withdrawal {$withdrawal->withdrawal_no} and released the reserved funds.",
                        oldValues: ['status' => SellerWithdrawalStatus::Processing->value],
                        newValues: ['status' => $withdrawal->status, 'failure_reason' => $failureReason, 'no_successful_transfer' => true],
                        severity: 'warning',
                        request: $request,
                    );
                }

                return $withdrawal->fresh(['bankAccount', 'transfer', 'histories.actor']);
            }, attempts: 3);

            foreach (array_unique($storedProofPaths) as $storedPath) {
                if ($storedPath !== $proofPath) {
                    Storage::disk('local')->delete($storedPath);
                }
            }

            return $withdrawal;
        } catch (\Throwable $exception) {
            foreach (array_unique($storedProofPaths) as $storedPath) {
                Storage::disk('local')->delete($storedPath);
            }

            throw $exception;
        }
    }

    public function confirmTransferFailure(
        User $admin,
        int $withdrawalId,
        bool $noSuccessfulTransferConfirmed,
        string $failureReason,
        Request $request,
    ): SellerWithdrawal {
        $this->assertFinance($admin);
        $failureReason = trim($failureReason);
        if (! $noSuccessfulTransferConfirmed) {
            throw ValidationException::withMessages([
                'no_successful_transfer_confirmed' => 'Confirm that no successful bank transfer occurred before releasing these funds.',
            ]);
        }
        if (mb_strlen($failureReason) < 5) {
            throw ValidationException::withMessages(['failure_reason' => 'Explain why the attempted bank transfer failed.']);
        }

        return DB::transaction(function () use ($admin, $withdrawalId, $failureReason, $request): SellerWithdrawal {
            $withdrawal = SellerWithdrawal::query()->whereKey($withdrawalId)->lockForUpdate()->firstOrFail();
            if ($withdrawal->status === SellerWithdrawalStatus::Failed->value) {
                return $withdrawal->fresh(['transfer']);
            }
            $this->assertTransition($withdrawal, SellerWithdrawalStatus::Failed);

            $transfer = SellerWithdrawalTransfer::query()
                ->where('seller_withdrawal_id', $withdrawal->id)
                ->lockForUpdate()
                ->first();
            if (! $transfer || $transfer->status !== 'pending_verification') {
                throw new ConflictHttpException('Only an unverified attempted transfer can be confirmed as failed.');
            }

            $transfer->update([
                'status' => 'failed',
                'failure_confirmed_by' => $admin->id,
                'failure_confirmed_at' => now(),
                'failure_reason' => $failureReason,
            ]);
            $this->wallets->releaseWithdrawalReservation(
                $withdrawal,
                $admin,
                'Reserved funds returned after Finance confirmed the attempted transfer was unsuccessful.',
            );
            $withdrawal->update([
                'status' => SellerWithdrawalStatus::Failed->value,
                'failed_at' => now(),
                'failure_reason' => $failureReason,
            ]);
            $this->recordStatus($withdrawal, SellerWithdrawalStatus::Processing, SellerWithdrawalStatus::Failed, $admin, $failureReason);
            $this->notifySeller($withdrawal, SellerWithdrawalStatus::Failed, $failureReason);
            $this->audit->record(
                action: 'seller_withdrawal.failed',
                module: 'Finance',
                subject: $withdrawal,
                description: "Finance confirmed that the recorded bank transfer for {$withdrawal->withdrawal_no} failed and released the reserved funds.",
                oldValues: ['status' => SellerWithdrawalStatus::Processing->value, 'transfer_status' => 'pending_verification'],
                newValues: [
                    'status' => $withdrawal->status,
                    'transfer_status' => $transfer->status,
                    'transfer_no' => $transfer->transfer_no,
                    'bank_reference' => $transfer->bank_reference,
                    'failure_reason' => $failureReason,
                    'no_successful_transfer' => true,
                ],
                severity: 'warning',
                request: $request,
            );

            return $withdrawal->fresh(['transfer', 'histories.actor']);
        }, attempts: 3);
    }

    public function complete(User $admin, int $withdrawalId, bool $verified, string $verificationNote, Request $request): SellerWithdrawal
    {
        $this->assertFinance($admin);
        $verificationNote = trim($verificationNote);
        if (! $verified) {
            throw ValidationException::withMessages(['transfer_verified' => 'Confirm that you independently verified the successful bank transfer.']);
        }
        if (mb_strlen($verificationNote) < 5) {
            throw ValidationException::withMessages(['verification_note' => 'Record how the successful transfer was verified.']);
        }

        return DB::transaction(function () use ($admin, $withdrawalId, $verificationNote, $request): SellerWithdrawal {
            $withdrawal = SellerWithdrawal::query()->whereKey($withdrawalId)->lockForUpdate()->firstOrFail();
            if ($withdrawal->status === SellerWithdrawalStatus::Completed->value) {
                return $withdrawal->fresh(['transfer']);
            }
            $this->assertTransition($withdrawal, SellerWithdrawalStatus::Completed);
            $transfer = SellerWithdrawalTransfer::query()
                ->where('seller_withdrawal_id', $withdrawal->id)
                ->lockForUpdate()
                ->first();
            if (! $transfer
                || $transfer->status !== 'pending_verification'
                || (int) $transfer->amount_minor !== (int) $withdrawal->amount_minor
                || ! filled($transfer->bank_reference)) {
                throw new ConflictHttpException('A successful bank transfer record with an exact amount and reference is required before completion.');
            }

            $transfer->update([
                'status' => 'verified',
                'verified_by' => $admin->id,
                'verified_at' => now(),
                'verification_note' => $verificationNote,
            ]);
            $this->wallets->completeWithdrawal($withdrawal, $admin);
            $withdrawal->update(['status' => SellerWithdrawalStatus::Completed->value, 'completed_at' => now()]);
            $this->recordStatus($withdrawal, SellerWithdrawalStatus::Processing, SellerWithdrawalStatus::Completed, $admin, $verificationNote);
            $this->notifySeller($withdrawal, SellerWithdrawalStatus::Completed, $verificationNote);
            $this->audit->record(
                action: 'seller_withdrawal.completed',
                module: 'Finance',
                subject: $withdrawal,
                description: "Finance independently verified and completed withdrawal {$withdrawal->withdrawal_no}.",
                oldValues: ['status' => SellerWithdrawalStatus::Processing->value, 'transfer_status' => 'pending_verification'],
                newValues: [
                    'status' => $withdrawal->status,
                    'transfer_status' => $transfer->status,
                    'amount_minor' => (int) $withdrawal->amount_minor,
                    'bank_reference' => $transfer->bank_reference,
                    'verified_by' => $admin->id,
                    'verification_note' => $verificationNote,
                ],
                severity: 'warning',
                request: $request,
            );

            return $withdrawal->fresh(['transfer', 'histories.actor']);
        }, attempts: 3);
    }

    public function assertBankAccountAccess(User $actor, SellerBankAccount $account): void
    {
        if ($actor->role === 'admin' && $actor->status === 'active') {
            return;
        }

        $sellerProfileId = (int) ($actor->sellerProfile?->id ?? 0);
        abort_unless($sellerProfileId > 0 && (int) $account->seller_profile_id === $sellerProfileId, 404);
    }

    private function sellerProfile(User $seller): SellerProfile
    {
        if ($seller->role !== 'seller' || $seller->status !== 'active') {
            throw new AuthorizationException('An active Seller account is required.');
        }

        $profile = $seller->sellerProfile;
        if (! $profile || ! $profile->approved_at) {
            throw new AuthorizationException('An approved Seller profile is required.');
        }

        return $profile;
    }

    private function assertFinance(User $admin): void
    {
        if ($admin->role !== 'admin' || $admin->status !== 'active') {
            throw new AuthorizationException('An active Admin/Finance user is required.');
        }
    }

    private function assertTransition(SellerWithdrawal $withdrawal, SellerWithdrawalStatus $target): void
    {
        $current = SellerWithdrawalStatus::tryFrom($withdrawal->status);
        if (! $current || ! $current->canTransitionTo($target)) {
            throw new ConflictHttpException("Withdrawal status {$withdrawal->status} cannot transition to {$target->value}.");
        }
    }

    private function recordStatus(
        SellerWithdrawal $withdrawal,
        ?SellerWithdrawalStatus $from,
        SellerWithdrawalStatus $to,
        User $actor,
        ?string $reason,
    ): void {
        SellerWithdrawalStatusHistory::query()->create([
            'seller_withdrawal_id' => $withdrawal->id,
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'actor_user_id' => $actor->id,
            'reason' => filled($reason) ? trim((string) $reason) : null,
            'occurred_at' => now(),
        ]);
    }

    private function notifySeller(SellerWithdrawal $withdrawal, SellerWithdrawalStatus $status, ?string $reason): void
    {
        $seller = $withdrawal->sellerProfile?->user;

        if (! $seller) {
            return;
        }

        $this->notifications->sendAfterCommit($seller, new SellerWithdrawalStatusNotification(
            withdrawalId: (int) $withdrawal->id,
            withdrawalNo: $withdrawal->withdrawal_no,
            amountMinor: (int) $withdrawal->amount_minor,
            status: $status->value,
            reason: filled($reason) ? trim((string) $reason) : null,
        ));
    }

    private function notifyFinanceReview(SellerWithdrawal $withdrawal): void
    {
        $amount = '₱'.number_format(((int) $withdrawal->amount_minor) / 100, 2);

        $this->notifications->sendManyAfterCommit(
            $this->notifications->admins(),
            new WorkflowNotification(
                eventKey: 'admin_withdrawal_submitted',
                title: 'Withdrawal awaiting review',
                body: "{$withdrawal->withdrawal_no} ({$amount}) was submitted by a Seller and is waiting for Finance review.",
                actionUrl: route('admin.seller-payouts.show', $withdrawal),
                type: 'warning',
                meta: ['withdrawal_id' => (int) $withdrawal->id],
            ),
        );
    }

    private function safeOriginalName(string $name): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._ -]/', '_', basename(str_replace('\\', '/', $name))) ?: 'bank-transfer-proof';

        return Str::limit($safe, 200, '');
    }
}
