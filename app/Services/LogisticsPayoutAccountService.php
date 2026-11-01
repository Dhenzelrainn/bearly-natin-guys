<?php

namespace App\Services;

use App\Models\LogisticsPayoutAccount;
use App\Models\LogisticsProfile;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class LogisticsPayoutAccountService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly InternationalPhone $phones,
    ) {}

    /** @param array{channel:string,provider_name?:?string,account_name:string,destination_value:string} $data */
    public function create(User $logisticsUser, array $data, Request $request): LogisticsPayoutAccount
    {
        $profile = $this->logisticsProfile($logisticsUser);
        $channel = $data['channel'];
        $destination = trim($data['destination_value']);

        if ($channel === 'bank_transfer') {
            $destination = preg_replace('/\D/', '', $destination) ?? '';
            if (strlen($destination) < 6 || strlen($destination) > 34) {
                throw ValidationException::withMessages([
                    'destination_value' => 'Enter a bank account number containing 6 to 34 digits.',
                ]);
            }
            $providerName = trim((string) ($data['provider_name'] ?? ''));
        } elseif ($channel === 'gcash') {
            try {
                $destination = $this->phones->normalize($destination, 'PH');
            } catch (ValidationException) {
                throw ValidationException::withMessages([
                    'destination_value' => 'Enter a valid Philippine GCash mobile number.',
                ]);
            }
            $providerName = 'GCash';
        } else {
            throw ValidationException::withMessages(['channel' => 'Choose Bank transfer or GCash.']);
        }

        $digits = preg_replace('/\D/', '', $destination) ?? '';

        return DB::transaction(function () use ($logisticsUser, $profile, $channel, $providerName, $data, $destination, $digits, $request): LogisticsPayoutAccount {
            LogisticsProfile::query()->whereKey($profile->id)->lockForUpdate()->firstOrFail();

            $account = LogisticsPayoutAccount::query()->create([
                'logistics_profile_id' => $profile->id,
                'channel' => $channel,
                'provider_name' => $providerName,
                'account_name' => trim($data['account_name']),
                'destination_value' => $destination,
                'destination_last4' => substr($digits, -4),
                'status' => 'pending_review',
                'created_by' => $logisticsUser->id,
            ]);

            $this->audit->record(
                action: 'logistics_payout_account.submitted',
                module: 'Logistics Finance',
                subject: $account,
                description: 'Logistics submitted a payout destination for Finance verification.',
                newValues: [
                    'logistics_profile_id' => (int) $profile->id,
                    'channel' => $account->channel,
                    'provider_name' => $account->provider_name,
                    'destination_last4' => $account->destination_last4,
                    'status' => $account->status,
                ],
                request: $request,
            );

            return $account;
        }, attempts: 3);
    }

    public function deactivate(User $logisticsUser, int $accountId, Request $request): LogisticsPayoutAccount
    {
        $profile = $this->logisticsProfile($logisticsUser);

        return DB::transaction(function () use ($profile, $accountId, $request): LogisticsPayoutAccount {
            $account = LogisticsPayoutAccount::query()
                ->where('logistics_profile_id', $profile->id)
                ->whereKey($accountId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($account->status === 'inactive') {
                return $account;
            }
            if (! in_array($account->status, ['pending_review', 'verified'], true)) {
                throw new ConflictHttpException('Only a pending or verified payout destination can be deactivated.');
            }

            $oldStatus = $account->status;
            $account->update(['status' => 'inactive', 'deactivated_at' => now()]);
            $this->audit->record(
                action: 'logistics_payout_account.deactivated',
                module: 'Logistics Finance',
                subject: $account,
                description: 'Logistics deactivated a payout destination; existing settlement history remains linked.',
                oldValues: ['status' => $oldStatus],
                newValues: [
                    'status' => 'inactive',
                    'channel' => $account->channel,
                    'provider_name' => $account->provider_name,
                    'destination_last4' => $account->destination_last4,
                ],
                request: $request,
            );

            return $account->fresh();
        }, attempts: 3);
    }

    public function verify(User $admin, int $accountId, string $note, Request $request): LogisticsPayoutAccount
    {
        $this->assertFinance($admin);
        $note = trim($note);

        return DB::transaction(function () use ($admin, $accountId, $note, $request): LogisticsPayoutAccount {
            $account = LogisticsPayoutAccount::query()->whereKey($accountId)->lockForUpdate()->firstOrFail();
            if ($account->status === 'verified') {
                if (trim((string) $account->review_note) === $note) {
                    return $account;
                }
                throw new ConflictHttpException('This payout destination has already been verified.');
            }
            if ($account->status !== 'pending_review') {
                throw new ConflictHttpException('Only a pending payout destination can be verified.');
            }

            $account->update([
                'status' => 'verified',
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
                'review_note' => $note,
            ]);
            $this->audit->record(
                action: 'logistics_payout_account.verified',
                module: 'Finance',
                subject: $account,
                description: 'Finance verified a Logistics payout destination for manual remittance use.',
                oldValues: ['status' => 'pending_review'],
                newValues: [
                    'status' => 'verified',
                    'logistics_profile_id' => (int) $account->logistics_profile_id,
                    'channel' => $account->channel,
                    'provider_name' => $account->provider_name,
                    'destination_last4' => $account->destination_last4,
                ],
                severity: 'warning',
                request: $request,
            );

            return $account->fresh(['logisticsProfile', 'reviewer']);
        }, attempts: 3);
    }

    public function reject(User $admin, int $accountId, string $reason, Request $request): LogisticsPayoutAccount
    {
        $this->assertFinance($admin);
        $reason = trim($reason);

        return DB::transaction(function () use ($admin, $accountId, $reason, $request): LogisticsPayoutAccount {
            $account = LogisticsPayoutAccount::query()->whereKey($accountId)->lockForUpdate()->firstOrFail();
            if ($account->status === 'rejected') {
                if (trim((string) $account->review_note) === $reason) {
                    return $account;
                }
                throw new ConflictHttpException('This payout destination has already been rejected.');
            }
            if ($account->status !== 'pending_review') {
                throw new ConflictHttpException('Only a pending payout destination can be rejected.');
            }

            $account->update([
                'status' => 'rejected',
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
                'review_note' => $reason,
            ]);
            $this->audit->record(
                action: 'logistics_payout_account.rejected',
                module: 'Finance',
                subject: $account,
                description: 'Finance rejected a Logistics payout destination.',
                oldValues: ['status' => 'pending_review'],
                newValues: [
                    'status' => 'rejected',
                    'logistics_profile_id' => (int) $account->logistics_profile_id,
                    'channel' => $account->channel,
                    'provider_name' => $account->provider_name,
                    'destination_last4' => $account->destination_last4,
                ],
                severity: 'warning',
                request: $request,
            );

            return $account->fresh(['logisticsProfile', 'reviewer']);
        }, attempts: 3);
    }

    public function financeDetails(User $admin, int $accountId, Request $request): LogisticsPayoutAccount
    {
        $this->assertFinance($admin);
        $account = LogisticsPayoutAccount::query()
            ->with(['logisticsProfile', 'reviewer'])
            ->findOrFail($accountId);

        $this->audit->record(
            action: 'logistics_payout_account.details_viewed',
            module: 'Finance',
            subject: $account,
            description: 'Finance viewed the full Logistics payout destination details.',
            newValues: [
                'logistics_profile_id' => (int) $account->logistics_profile_id,
                'channel' => $account->channel,
                'provider_name' => $account->provider_name,
                'destination_last4' => $account->destination_last4,
                'status' => $account->status,
                'full_destination_viewed' => true,
            ],
            request: $request,
        );

        return $account;
    }

    private function logisticsProfile(User $user): LogisticsProfile
    {
        if ($user->role !== 'logistics' || $user->status !== 'active') {
            throw new AuthorizationException('An active Logistics account is required.');
        }

        $profile = $user->logisticsProfile()->firstOrFail();
        if ($profile->status !== 'active') {
            throw new AuthorizationException('An active Logistics profile is required.');
        }

        return $profile;
    }

    private function assertFinance(User $user): void
    {
        if ($user->role !== 'admin' || $user->status !== 'active') {
            throw new AuthorizationException('An active Admin/Finance user is required.');
        }
    }
}
