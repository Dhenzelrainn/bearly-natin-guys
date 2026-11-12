<?php

namespace App\Services;

use App\Models\PolicyAcceptance;
use App\Models\PolicyVersion;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SellerPolicyAcknowledgmentService
{
    public const REQUIRED_POLICY_CODE = 'SELLER-CONDUCT-OFF-PLATFORM';

    public function currentRequiredVersion(): ?PolicyVersion
    {
        return PolicyVersion::query()
            ->where('status', 'published')
            ->whereHas('policy', fn ($query) => $query
                ->where('code', self::REQUIRED_POLICY_CODE)
                ->where('scope', 'platform')
                ->where('status', 'active'))
            ->where(fn ($query) => $query
                ->whereNull('effective_at')
                ->orWhere('effective_at', '<=', now()))
            ->with('policy')
            ->orderByDesc('effective_at')
            ->orderByDesc('id')
            ->first();
    }

    public function hasAccepted(User $user, PolicyVersion $version): bool
    {
        return PolicyAcceptance::query()
            ->where('user_id', $user->id)
            ->where('policy_version_id', $version->id)
            ->exists();
    }

    public function acknowledgeCurrent(User $user, Request $request): bool
    {
        return DB::transaction(function () use ($user, $request): bool {
            $version = $this->currentRequiredVersion();

            if (! $version) {
                return false;
            }

            $now = now();
            $inserted = PolicyAcceptance::query()->insertOrIgnore([
                'user_id' => $user->id,
                'policy_version_id' => $version->id,
                'accepted_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($inserted === 1) {
                app(AuditService::class)->record(
                    action: 'seller_policy.acknowledged',
                    module: 'policies',
                    subject: $version,
                    description: "Seller acknowledged {$version->policy?->code} version {$version->version}.",
                    oldValues: [],
                    newValues: [
                        'policy_code' => $version->policy?->code,
                        'policy_version_id' => (int) $version->id,
                        'version' => $version->version,
                    ],
                    severity: 'info',
                    request: $request,
                    actor: $user,
                );
            }

            return true;
        }, attempts: 3);
    }
}
