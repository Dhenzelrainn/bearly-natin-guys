<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\LogisticsProfile;
use App\Models\Policy;
use App\Models\PolicyVersion;
use App\Models\ShippingPolicySetting;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ShippingPolicyService
{
    public const CATEGORIES = [
        'Restricted goods',
        'Packaging and combinations',
        'Rider operations',
    ];

    public function providerKey(): string
    {
        return (string) config('shipping.provider_key');
    }

    public function managerProfile(): ?LogisticsProfile
    {
        return ShippingPolicySetting::query()
            ->where('provider_key', $this->providerKey())
            ->with('managerLogisticsProfile.user')
            ->first()
            ?->managerLogisticsProfile;
    }

    public function managerAssignment(): ?ShippingPolicySetting
    {
        return ShippingPolicySetting::query()
            ->where('provider_key', $this->providerKey())
            ->with([
                'managerLogisticsProfile' => fn ($query) => $query
                    ->withTrashed()
                    ->with(['user' => fn ($userQuery) => $userQuery->withTrashed()]),
            ])
            ->first();
    }

    public function isManager(User $user): bool
    {
        if ($user->role !== 'logistics' || $user->status !== 'active') {
            return false;
        }

        $profile = $user->logisticsProfile;
        $manager = $this->managerProfile();

        return $profile !== null
            && $profile->status === 'active'
            && $manager !== null
            && (int) $manager->id === (int) $profile->id;
    }

    /** @return list<array<string, mixed>> */
    public function publishedPolicies(): array
    {
        return Policy::query()
            ->forProvider($this->providerKey())
            ->where('status', 'active')
            ->whereHas('versions', fn ($query) => $query
                ->where('status', 'published')
                ->whereNotNull('effective_at')
                ->where('effective_at', '<=', now()))
            ->with(['versions' => fn ($query) => $query
                ->with('creator')
                ->orderByDesc('id')])
            ->orderBy('title')
            ->get()
            ->map(function (Policy $policy): ?array {
                $data = $this->policyPayload($policy);

                return $data['published'] === null ? null : [
                    'id' => $data['id'],
                    'code' => $data['code'],
                    'title' => $data['title'],
                    'category' => $data['category'],
                    'published' => $data['published'],
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    public function policiesForManagement(bool $manager): array
    {
        $query = Policy::query()
            ->forProvider($this->providerKey())
            ->with(['versions' => fn ($query) => $query
                ->with('creator')
                ->orderByDesc('id')])
            ->orderBy('title');

        if (! $manager) {
            $query->where('status', 'active')
                ->whereHas('versions', fn ($versions) => $versions
                    ->where('status', 'published')
                    ->whereNotNull('effective_at')
                    ->where('effective_at', '<=', now()));
        }

        $policies = $query->get();
        $versions = $manager
            ? $policies->flatMap(fn (Policy $policy) => $policy->versions)
            : collect();
        $reviewReasons = $manager
            ? $this->auditDescriptionsForVersions($versions, 'shipping_policy.changes_requested')
            : [];

        return $policies
            ->map(function (Policy $policy) use ($manager, $reviewReasons): array {
                $payload = $this->policyPayload($policy, $reviewReasons);

                if ($manager) {
                    return $payload;
                }

                // Never expose draft, pending, or returned version metadata to
                // Logistics users who are not the appointed policy manager.
                $payload['latest'] = $payload['published'];
                $payload['versions'] = $payload['published'] === null
                    ? []
                    : [$payload['published']];
                $payload['next_version'] = '';

                return $payload;
            })
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    public function pendingApprovals(): array
    {
        $versions = PolicyVersion::query()
            ->where('status', 'pending_approval')
            ->whereHas('policy', fn ($query) => $query
                ->forProvider($this->providerKey())
                ->where('status', '!=', 'archived'))
            ->with(['policy', 'creator'])
            ->orderBy('updated_at')
            ->get();
        $submissionReasons = $this->auditDescriptionsForVersions($versions, 'shipping_policy.submitted_for_approval');

        return $versions
            ->map(fn (PolicyVersion $version): array => [
                'id' => (int) $version->id,
                'policy_id' => (int) $version->policy_id,
                'code' => $version->policy->code,
                'title' => $version->title ?: $version->policy->title,
                'category' => $version->category ?: $version->policy->category,
                'version' => $version->version,
                'summary' => $version->summary,
                'body' => $version->body,
                'source_reference' => $version->source_reference,
                'author' => $version->creator?->name ?? 'Former Logistics manager',
                'submitted_at' => $version->updated_at?->format('M j, Y g:i A'),
                'submission_reason' => $submissionReasons[$version->id] ?? 'Submitted for Admin review.',
            ])
            ->all();
    }

    /** @return LengthAwarePaginator<int, array<string, mixed>> */
    public function approvalHistory(): LengthAwarePaginator
    {
        $approvals = AuditLog::query()
            ->with('actor')
            ->where('module', 'shipping_policy')
            ->where('action', 'shipping_policy.approved_and_published')
            ->where('auditable_type', PolicyVersion::class)
            ->whereIn('auditable_id', PolicyVersion::query()
                ->whereHas('policy', fn ($query) => $query->forProvider($this->providerKey()))
                ->select('id'))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(10, ['*'], 'approved_page');

        $versionIds = $approvals->getCollection()
            ->pluck('auditable_id')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->all();
        $versions = $versionIds === []
            ? collect()
            : PolicyVersion::query()
                ->with('policy')
                ->whereKey($versionIds)
                ->get()
                ->keyBy('id');

        return $approvals->through(function (AuditLog $log) use ($versions): array {
            $values = is_array($log->new_values) ? $log->new_values : [];
            $version = $versions->get((int) $log->auditable_id);
            $policy = $version?->policy;
            $policyId = (int) ($values['policy_id'] ?? $version?->policy_id ?? 0);

            return [
                'title' => (string) ($values['title'] ?? $version?->title ?? $policy?->title ?? 'Shipping policy'),
                'category' => (string) ($values['category'] ?? $version?->category ?? $policy?->category ?? '—'),
                'code' => (string) ($policy?->code ?? ($policyId > 0 ? 'Policy #'.$policyId : 'Policy version #'.$log->auditable_id)),
                'version' => (string) ($values['version'] ?? $version?->version ?? '—'),
                'body' => $version?->body,
                'source_reference' => (string) ($values['source_reference'] ?? $version?->source_reference ?? '—'),
                'source_reviewed' => (bool) ($values['source_reviewed'] ?? false),
                'approved_by' => $log->actor?->name ?: 'Former Admin',
                'approved_at' => $log->created_at?->format('M j, Y g:i A') ?? '—',
                'reason' => $log->description ?: 'No approval note recorded.',
            ];
        });
    }

    public function assignManager(
        User $actor,
        ?int $profileId,
        string $reason,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): ?LogisticsProfile {
        return DB::transaction(function () use ($actor, $profileId, $reason, $ipAddress, $userAgent): ?LogisticsProfile {
            DB::table('shipping_policy_settings')->insertOrIgnore([
                'provider_key' => $this->providerKey(),
                'manager_logistics_profile_id' => null,
                'updated_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $setting = ShippingPolicySetting::query()
                ->where('provider_key', $this->providerKey())
                ->lockForUpdate()
                ->firstOrFail();

            $newProfile = null;

            if ($profileId !== null) {
                $newProfile = LogisticsProfile::query()
                    ->whereKey($profileId)
                    ->lockForUpdate()
                    ->first();

                if ($newProfile === null || $newProfile->status !== 'active') {
                    throw ValidationException::withMessages([
                        'logistics_profile_id' => 'Choose an active Logistics profile.',
                    ]);
                }

                $managerUser = User::query()
                    ->whereKey($newProfile->user_id)
                    ->lockForUpdate()
                    ->first();

                if ($managerUser === null
                    || $managerUser->role !== 'logistics'
                    || $managerUser->status !== 'active') {
                    throw ValidationException::withMessages([
                        'logistics_profile_id' => 'The selected Logistics account must be active.',
                    ]);
                }
            }

            $oldProfileId = $setting->manager_logistics_profile_id === null
                ? null
                : (int) $setting->manager_logistics_profile_id;

            if ($oldProfileId === $profileId) {
                return $newProfile;
            }

            $setting->update([
                'manager_logistics_profile_id' => $profileId,
                'updated_by' => $actor->id,
            ]);

            $this->audit(
                actor: $actor,
                action: 'shipping_policy_manager.updated',
                model: $setting,
                reason: $reason,
                oldValues: ['manager_logistics_profile_id' => $oldProfileId],
                newValues: ['manager_logistics_profile_id' => $profileId],
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            return $newProfile;
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function createPolicy(
        User $actor,
        array $data,
        ?string $reason = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Policy {
        $reason = filled($reason) ? trim($reason) : null;

        return DB::transaction(function () use ($actor, $data, $reason, $ipAddress, $userAgent): Policy {
            $this->lockManagerForWrite($actor);
            $policy = Policy::query()->create([
                'code' => 'SHP-'.now()->format('Ymd').'-'.Str::upper(Str::random(8)),
                'title' => $data['title'],
                'category' => $data['category'],
                'scope' => 'shipping_provider',
                'scope_key' => $this->providerKey(),
                'status' => 'draft',
            ]);

            $version = $policy->versions()->create([
                'version' => $data['version'],
                'body' => $data['body'],
                'summary' => $data['summary'] ?? null,
                'title' => $data['title'],
                'category' => $data['category'],
                'source_reference' => $data['source_reference'] ?? null,
                'status' => 'draft',
                'created_by' => $actor->id,
                'published_at' => null,
                'effective_at' => null,
            ]);

            $this->audit(
                actor: $actor,
                action: 'shipping_policy.created',
                model: $policy,
                reason: $reason,
                oldValues: null,
                newValues: [
                    'code' => $policy->code,
                    'title' => $policy->title,
                    'category' => $policy->category,
                    'status' => $policy->status,
                    'version' => $version->version,
                    'version_status' => $version->status,
                    'source_reference' => $version->source_reference,
                ],
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            return $policy;
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function createDraftVersion(
        User $actor,
        Policy $policy,
        array $data,
        ?string $reason = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): PolicyVersion {
        $reason = filled($reason) ? trim($reason) : null;

        return DB::transaction(function () use ($actor, $policy, $data, $reason, $ipAddress, $userAgent): PolicyVersion {
            $this->lockManagerForWrite($actor);
            $policy = $this->lockProviderPolicy($policy->id);

            if ($policy->status === 'archived') {
                throw new ConflictHttpException('Archived policies cannot be edited.');
            }

            if ($policy->versions()->where('status', 'pending_approval')->exists()) {
                throw new ConflictHttpException('A policy cannot be changed while a version is awaiting Admin review.');
            }

            $latest = $policy->versions()->orderByDesc('id')->lockForUpdate()->first();

            if ($latest?->status === 'draft') {
                throw new ConflictHttpException('Edit the existing draft instead of creating another draft version.');
            }

            if ($policy->versions()->where('version', $data['version'])->exists()) {
                throw ValidationException::withMessages([
                    'version' => 'This version label already exists for this policy. Choose a new version label.',
                ]);
            }

            $before = $policy->only(['title', 'category', 'status']);

            $version = $policy->versions()->create([
                'version' => $data['version'],
                'body' => $data['body'],
                'summary' => $data['summary'] ?? null,
                'title' => $data['title'],
                'category' => $data['category'],
                'source_reference' => $data['source_reference'] ?? null,
                'status' => 'draft',
                'created_by' => $actor->id,
            ]);

            $this->audit(
                actor: $actor,
                action: 'shipping_policy.updated',
                model: $policy,
                reason: $reason,
                oldValues: $before,
                newValues: [
                    'policy' => $policy->fresh()?->only(['title', 'category', 'status']),
                    'version' => $version->only([
                        'version',
                        'title',
                        'category',
                        'status',
                        'source_reference',
                    ]),
                ],
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            return $version;
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function updateDraftVersion(
        User $actor,
        Policy $policy,
        PolicyVersion $draftVersion,
        array $data,
        string $reason,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): PolicyVersion {
        return DB::transaction(function () use ($actor, $policy, $draftVersion, $data, $reason, $ipAddress, $userAgent): PolicyVersion {
            $this->lockManagerForWrite($actor);
            $policy = $this->lockProviderPolicy($policy->id);

            if ($policy->status === 'archived') {
                throw new ConflictHttpException('Archived policies cannot be edited.');
            }

            if ($policy->versions()->where('status', 'pending_approval')->exists()) {
                throw new ConflictHttpException('A policy cannot be changed while a version is awaiting Admin review.');
            }

            $version = $policy->versions()
                ->whereKey($draftVersion->id)
                ->lockForUpdate()
                ->firstOrFail();
            $latest = $policy->versions()->orderByDesc('id')->lockForUpdate()->first();

            if ($version->status !== 'draft' || $latest?->id !== $version->id) {
                throw new ConflictHttpException('Only the latest unsent draft can be edited.');
            }

            $mutableFields = ['title', 'category', 'summary', 'body', 'source_reference'];
            $oldValues = $version->only($mutableFields);
            $version->update([
                'title' => $data['title'],
                'category' => $data['category'],
                'summary' => $data['summary'] ?? null,
                'body' => $data['body'],
                'source_reference' => filled($data['source_reference'] ?? null)
                    ? trim((string) $data['source_reference'])
                    : null,
            ]);
            $newValues = $version->fresh()?->only($mutableFields) ?? [];

            $this->audit(
                actor: $actor,
                action: 'shipping_policy.draft_updated',
                model: $version,
                reason: $reason,
                oldValues: $oldValues,
                newValues: $newValues,
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            return $version->fresh();
        }, 3);
    }

    public function submitForApproval(
        User $actor,
        Policy $policy,
        string $sourceReference,
        string $reason,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): PolicyVersion {
        return DB::transaction(function () use ($actor, $policy, $sourceReference, $reason, $ipAddress, $userAgent): PolicyVersion {
            $this->lockManagerForWrite($actor);
            $policy = $this->lockProviderPolicy($policy->id);

            if ($policy->status === 'archived') {
                throw new ConflictHttpException('Archived policies cannot be submitted for approval.');
            }

            $latest = $policy->versions()->orderByDesc('id')->lockForUpdate()->first();

            if ($latest === null || $latest->status !== 'draft') {
                throw new ConflictHttpException('The latest policy version is not an editable draft. Save a new draft before submitting it.');
            }

            if (! filled(trim($sourceReference))) {
                throw ValidationException::withMessages([
                    'source_reference' => 'A source or approval reference is required before submitting this policy for Admin review.',
                ]);
            }

            $latest->update([
                'source_reference' => trim($sourceReference),
                'status' => 'pending_approval',
            ]);

            $this->audit(
                actor: $actor,
                action: 'shipping_policy.submitted_for_approval',
                model: $latest,
                reason: $reason,
                oldValues: ['version' => $latest->version, 'status' => 'draft'],
                newValues: [
                    'policy_id' => $policy->id,
                    'version' => $latest->version,
                    'status' => 'pending_approval',
                    'source_reference' => trim($sourceReference),
                ],
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            return $latest->fresh();
        }, 3);
    }

    public function approveAndPublish(
        User $actor,
        Policy $policy,
        PolicyVersion $submittedVersion,
        string $reason,
        bool $sourceReviewed,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): PolicyVersion {
        $this->assertActiveAdmin($actor);

        if (! $sourceReviewed) {
            throw ValidationException::withMessages([
                'source_reviewed' => 'Confirm that you reviewed the cited source and policy before publishing.',
            ]);
        }

        return DB::transaction(function () use ($actor, $policy, $submittedVersion, $reason, $sourceReviewed, $ipAddress, $userAgent): PolicyVersion {
            $policy = $this->lockProviderPolicy($policy->id);

            if ($policy->status === 'archived') {
                throw new ConflictHttpException('Archived policies cannot be approved or published.');
            }

            $version = $policy->versions()
                ->whereKey($submittedVersion->id)
                ->lockForUpdate()
                ->firstOrFail();
            $latest = $policy->versions()->orderByDesc('id')->lockForUpdate()->first();

            if ($version->status !== 'pending_approval' || $latest?->id !== $version->id) {
                throw new ConflictHttpException('This policy version is no longer awaiting Admin approval.');
            }

            if (! filled(trim((string) $version->source_reference))) {
                throw ValidationException::withMessages([
                    'source_reference' => 'A source or approval reference must be recorded before publication.',
                ]);
            }

            $previousPublished = $policy->versions()
                ->whereKeyNot($version->id)
                ->where('status', 'published')
                ->lockForUpdate()
                ->pluck('version')
                ->all();
            $previousPolicyMetadata = $policy->only(['title', 'category', 'status']);

            $policy->versions()
                ->whereKeyNot($version->id)
                ->where('status', 'published')
                ->update(['status' => 'archived']);

            $version->update([
                'status' => 'published',
                'published_at' => now(),
                'effective_at' => now(),
            ]);
            $policy->update([
                'title' => $version->title ?: $policy->title,
                'category' => $version->category ?: $policy->category,
                'status' => 'active',
            ]);

            $this->audit(
                actor: $actor,
                action: 'shipping_policy.approved_and_published',
                model: $version,
                reason: $reason,
                oldValues: [
                    'version' => $version->version,
                    'status' => 'pending_approval',
                    'policy' => $previousPolicyMetadata,
                ],
                newValues: [
                    'policy_id' => $policy->id,
                    'version' => $version->version,
                    'status' => 'published',
                    'title' => $policy->title,
                    'category' => $policy->category,
                    'published_at' => $version->fresh()?->published_at?->toIso8601String(),
                    'source_reference' => $version->source_reference,
                    'source_reviewed' => $sourceReviewed,
                    'superseded_versions' => $previousPublished,
                ],
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            return $version->fresh();
        }, 3);
    }

    public function requestChanges(
        User $actor,
        Policy $policy,
        PolicyVersion $submittedVersion,
        string $reason,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): PolicyVersion {
        $this->assertActiveAdmin($actor);

        return DB::transaction(function () use ($actor, $policy, $submittedVersion, $reason, $ipAddress, $userAgent): PolicyVersion {
            $policy = $this->lockProviderPolicy($policy->id);

            if ($policy->status === 'archived') {
                throw new ConflictHttpException('Archived policies cannot be returned for changes.');
            }

            $version = $policy->versions()
                ->whereKey($submittedVersion->id)
                ->lockForUpdate()
                ->firstOrFail();
            $latest = $policy->versions()->orderByDesc('id')->lockForUpdate()->first();

            if ($version->status !== 'pending_approval' || $latest?->id !== $version->id) {
                throw new ConflictHttpException('This policy version is no longer awaiting Admin review.');
            }

            $version->update(['status' => 'changes_requested']);

            $this->audit(
                actor: $actor,
                action: 'shipping_policy.changes_requested',
                model: $version,
                reason: $reason,
                oldValues: ['version' => $version->version, 'status' => 'pending_approval'],
                newValues: [
                    'policy_id' => $policy->id,
                    'version' => $version->version,
                    'status' => 'changes_requested',
                ],
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            return $version->fresh();
        }, 3);
    }

    public function archivePolicy(
        User $actor,
        Policy $policy,
        string $reason,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): void {
        DB::transaction(function () use ($actor, $policy, $reason, $ipAddress, $userAgent): void {
            $this->lockManagerForWrite($actor);
            $policy = $this->lockProviderPolicy($policy->id);

            if ($policy->status === 'archived') {
                throw new ConflictHttpException('This policy is already archived.');
            }

            if ($policy->versions()->where('status', 'pending_approval')->exists()) {
                throw new ConflictHttpException('A policy with a pending Admin review cannot be archived.');
            }

            $before = $policy->only(['code', 'title', 'category', 'status']);
            $policy->update(['status' => 'archived']);

            $this->audit(
                actor: $actor,
                action: 'shipping_policy.archived',
                model: $policy,
                reason: $reason,
                oldValues: $before,
                newValues: $policy->fresh()?->only(['code', 'title', 'category', 'status']) ?? [],
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );
        }, 3);
    }

    private function lockProviderPolicy(int $policyId): Policy
    {
        return Policy::query()
            ->forProvider($this->providerKey())
            ->whereKey($policyId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function lockManagerForWrite(User $actor): void
    {
        $setting = ShippingPolicySetting::query()
            ->where('provider_key', $this->providerKey())
            ->lockForUpdate()
            ->first();

        if ($setting === null || $setting->manager_logistics_profile_id === null) {
            throw new AuthorizationException('No active Bearly Logistics policy manager is assigned.');
        }

        $profile = LogisticsProfile::query()
            ->whereKey($setting->manager_logistics_profile_id)
            ->lockForUpdate()
            ->first();

        if ($profile === null || $profile->status !== 'active') {
            throw new AuthorizationException('The appointed Logistics policy manager is no longer active.');
        }

        $manager = User::query()
            ->whereKey($profile->user_id)
            ->lockForUpdate()
            ->first();

        if ($manager === null
            || (int) $manager->id !== (int) $actor->id
            || $manager->role !== 'logistics'
            || $manager->status !== 'active') {
            throw new AuthorizationException('Only the currently appointed active Logistics policy manager can change provider policies.');
        }
    }

    /** @return array<string, mixed> */
    private function policyPayload(Policy $policy, array $reviewReasons = []): array
    {
        $versions = $policy->versions;
        $latest = $versions->first();
        $published = $versions->first(fn (PolicyVersion $version): bool => $version->status === 'published'
            && $version->effective_at !== null
            && $version->effective_at->lte(now()));
        $displayVersion = $published ?? $latest;

        return [
            'id' => (int) $policy->id,
            'code' => $policy->code,
            'title' => $displayVersion?->title ?: $policy->title,
            'category' => $displayVersion?->category ?: $policy->category,
            'status_value' => $policy->status,
            'status' => match ($policy->status) {
                'active' => 'Active',
                'archived' => 'Archived',
                default => ucfirst($policy->status),
            },
            'latest' => $latest === null ? null : $this->versionPayload($latest, $reviewReasons[$latest->id] ?? null),
            'published' => $published === null ? null : $this->versionPayload($published, $reviewReasons[$published->id] ?? null),
            'next_version' => $this->nextVersionLabel($latest?->version),
            'versions' => $versions->map(fn (PolicyVersion $version): array => $this->versionPayload(
                $version,
                $reviewReasons[$version->id] ?? null,
            ))->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function versionPayload(PolicyVersion $version, ?string $reviewReason = null): array
    {
        return [
            'id' => (int) $version->id,
            'version' => $version->version,
            'title' => $version->title,
            'category' => $version->category,
            'status_value' => $version->status,
            'status' => match ($version->status) {
                'published' => 'Published',
                'archived' => 'Archived',
                'pending_approval' => 'Pending Admin approval',
                'changes_requested' => 'Changes requested',
                default => 'Draft',
            },
            'summary' => $version->summary,
            'body' => $version->body,
            'source_reference' => $version->source_reference,
            'review_reason' => $reviewReason,
            'author' => $version->creator?->name ?? 'System',
            'created_at' => $version->created_at?->format('M j, Y g:i A'),
            'published_at' => $version->published_at?->format('M j, Y g:i A'),
        ];
    }

    /** @param Collection<int, PolicyVersion> $versions
     * @return array<int, string>
     */
    private function auditDescriptionsForVersions(Collection $versions, string $action): array
    {
        $versionIds = $versions->pluck('id')->all();

        if ($versionIds === []) {
            return [];
        }

        return AuditLog::query()
            ->where('module', 'shipping_policy')
            ->where('auditable_type', PolicyVersion::class)
            ->whereIn('auditable_id', $versionIds)
            ->where('action', $action)
            ->orderByDesc('id')
            ->get(['auditable_id', 'description'])
            ->unique('auditable_id')
            ->mapWithKeys(fn (AuditLog $log): array => [(int) $log->auditable_id => (string) $log->description])
            ->all();
    }

    private function assertActiveAdmin(User $actor): void
    {
        if ($actor->role !== 'admin' || $actor->status !== 'active') {
            throw new AuthorizationException('Only an active Admin can review Bearly Logistics policy submissions.');
        }
    }

    private function nextVersionLabel(?string $current): string
    {
        if ($current === null) {
            return 'v1.0';
        }

        if (preg_match('/^v?(\d+)(?:\.(\d+))?$/', $current, $matches) === 1) {
            $minor = isset($matches[2]) ? ((int) $matches[2]) + 1 : 1;

            return "v{$matches[1]}.{$minor}";
        }

        return '';
    }

    /** @param array<string, mixed>|null $oldValues
     * @param  array<string, mixed>  $newValues
     */
    private function audit(
        User $actor,
        string $action,
        Policy|PolicyVersion|ShippingPolicySetting $model,
        ?string $reason,
        ?array $oldValues,
        array $newValues,
        ?string $ipAddress,
        ?string $userAgent,
    ): void {
        AuditLog::query()->create([
            'actor_user_id' => $actor->id,
            'action' => $action,
            'module' => 'shipping_policy',
            'auditable_type' => $model::class,
            'auditable_id' => $model->id,
            'description' => $reason,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'severity' => 'info',
        ]);
    }
}
