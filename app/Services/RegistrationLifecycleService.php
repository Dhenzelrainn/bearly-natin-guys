<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Models\AccountApplication;
use App\Models\Address;
use App\Models\ApplicationDocument;
use App\Models\Category;
use App\Models\LogisticsProfile;
use App\Models\RiderProfile;
use App\Models\Role;
use App\Models\SellerProfile;
use App\Models\SortingCenter;
use App\Models\SortingZone;
use App\Models\User;
use App\Notifications\WorkflowNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class RegistrationLifecycleService
{
    public function __construct(
        private readonly SellerProductService $sellerProductService,
        private readonly RiderZoneAssignmentService $riderZoneAssignment,
        private readonly NotificationService $notifications,
    ) {
    }

    /**
     * Write the normalized records that mirror one pending registration.
     *
     * The legacy columns on users remain populated for compatibility with
     * existing Bearly pages. New auth/database code should use these
     * normalized records as the long-term source of application data.
     */
    public function recordPendingApplication(
        User $user,
        string $requestedRole,
        array $address,
        array $application = [],
        array $documents = [],
        ?int $sponsorLogisticsUserId = null
    ): AccountApplication {
        return DB::transaction(function () use (
            $user,
            $requestedRole,
            $address,
            $application,
            $documents,
            $sponsorLogisticsUserId
        ) {
            $role = $this->role($requestedRole);

            $normalizedAddress = $this->storeAddress(
                $user,
                $requestedRole,
                $address
            );

            $sponsorProfile = null;

            if (
                $requestedRole === UserRole::Rider->value
                && $sponsorLogisticsUserId
            ) {
                $sponsor = User::query()
                    ->whereKey($sponsorLogisticsUserId)
                    ->where('role', UserRole::Logistics->value)
                    ->where('status', AccountStatus::Active->value)
                    ->first();

                if (! $sponsor) {
                    throw new RuntimeException(
                        'The selected Logistics partner no longer exists.'
                    );
                }

                $sponsorProfile = $sponsor->logisticsProfile;

                if (! $sponsorProfile) {
                    throw new RuntimeException(
                        'The selected Logistics partner is not configured for a service city.'
                    );
                }

                if (
                    $sponsorProfile->status !== AccountStatus::Active->value
                    || (string) $sponsorProfile->psgc_city_code
                        !== (string) $normalizedAddress->psgc_city_code
                ) {
                    throw ValidationException::withMessages([
                        'logistics_partner' => 'Choose an active Logistics account serving your registered home municipality/city.',
                    ]);
                }
            }

            $accountApplication = AccountApplication::query()
                ->where('user_id', $user->id)
                ->where('requested_role_id', $role->id)
                ->latest('id')
                ->first();

            if (! $accountApplication) {
                $accountApplication = AccountApplication::create([
                    'application_no' => $this->applicationNumber(
                        $requestedRole
                    ),
                    'user_id' => $user->id,
                    'requested_role_id' => $role->id,
                    'sponsor_logistics_profile_id' =>
                        $sponsorProfile?->id,
                    'business_name' =>
                        $application['business_name']
                        ?? $user->business_name,
                    'business_category_id' =>
                        $this->categoryId(
                            $application['business_category']
                            ?? $user->business_category
                        ),
                    'status' => 'submitted',
                    'submitted_at' => now(),
                ]);
            } else {
                $accountApplication->forceFill([
                    'sponsor_logistics_profile_id' =>
                        $sponsorProfile?->id
                        ?? $accountApplication
                            ->sponsor_logistics_profile_id,
                    'business_name' =>
                        $application['business_name']
                        ?? $accountApplication->business_name
                        ?? $user->business_name,
                    'business_category_id' =>
                        $this->categoryId(
                            $application['business_category']
                            ?? $user->business_category
                        )
                        ?? $accountApplication->business_category_id,
                    'status' => 'submitted',
                    'submitted_at' =>
                        $accountApplication->submitted_at
                        ?? now(),
                ])->save();
            }

            foreach ($documents as $document) {
                $this->storeDocument(
                    $accountApplication,
                    $document
                );
            }

            /*
             * Keep a usable normalized address ready for future profile
             * creation. No role is granted here; role assignment happens
             * only after the application is approved.
             */
            if (
                $requestedRole === UserRole::Seller->value
                && $normalizedAddress
            ) {
                // Intentionally no SellerProfile yet.
                // It is created only after Admin approval.
            }

            $this->notifyRegistrationSubmitted($user, $requestedRole, $accountApplication, $sponsorProfile);

            return $accountApplication->fresh([
                'requestedRole',
                'documents',
            ]);
        });
    }

    /**
     * Notify the queue that owns the next decision: Admins for Buyer, Seller,
     * and Logistics applications; the sponsoring Logistics account for Rider
     * applications.
     */
    private function notifyRegistrationSubmitted(
        User $user,
        string $requestedRole,
        AccountApplication $application,
        ?LogisticsProfile $sponsorProfile,
    ): void {
        if ($requestedRole === UserRole::Rider->value) {
            $sponsorUser = $sponsorProfile?->user;

            if (! $sponsorUser) {
                return;
            }

            $this->notifications->sendAfterCommit($sponsorUser, new WorkflowNotification(
                eventKey: 'logistics_rider_application_submitted',
                title: 'New rider application',
                body: "{$user->name} submitted a rider application ({$application->application_no}) under your Logistics account.",
                actionUrl: route('logistics.riders.index'),
                type: 'info',
                meta: ['application_id' => (int) $application->id],
            ));

            return;
        }

        $url = match ($requestedRole) {
            UserRole::Buyer->value => route('admin.registrations.buyers'),
            UserRole::Seller->value => route('admin.registrations.sellers'),
            UserRole::Logistics->value => route('admin.registrations.logistics'),
            default => route('admin.registrations'),
        };

        $this->notifications->sendManyAfterCommit(
            $this->notifications->admins(),
            new WorkflowNotification(
                eventKey: 'admin_registration_submitted',
                title: 'New '.ucfirst($requestedRole).' registration',
                body: "{$user->name} submitted a {$requestedRole} registration ({$application->application_no}) awaiting review.",
                actionUrl: $url,
                type: 'info',
                meta: ['application_id' => (int) $application->id],
            ),
        );
    }

    /**
     * Grant the approved role in role_user and synchronize the normalized
     * application/profile records.
     */
    public function approve(
        User $user,
        User $approver,
        ?int $applicationId = null
    ): AccountApplication {
        try {
            return DB::transaction(function () use (
                $user,
                $approver,
                $applicationId
            ): AccountApplication {
            $lockedUser = User::query()
                ->lockForUpdate()
                ->findOrFail($user->id);

            $application = $this->lockReviewableApplication(
                $lockedUser,
                $applicationId
            );
            $role = $application->requestedRole;

            $this->ensureRequiredDocumentsAreVerified($application);

            $lockedUser->roles()->syncWithoutDetaching([
                $role->id => [
                    'assigned_by' => $approver->id,
                    'assigned_at' => now(),
                ],
            ]);

            $application->forceFill([
                'status' => 'approved',
                'review_started_at' =>
                    $application->review_started_at ?? now(),
                'decided_at' => now(),
                'reviewed_by' => $approver->id,
                'decision_reason' => null,
                'revision_notes' => null,
            ])->save();

            $lockedUser->forceFill([
                'role' => $role->name,
                'status' => AccountStatus::Active->value,
                'approved_by' => $approver->id,
                'approved_at' => now(),
                'rejection_reason' => null,
            ])->save();

            $this->createApprovedProfile(
                $lockedUser,
                $application
            );

                return $application->fresh([
                    'user',
                    'requestedRole',
                    'documents',
                ]);
            });
        } catch (QueryException $exception) {
            if (str_contains($exception->getMessage(), 'psgc_city_code')) {
                throw ValidationException::withMessages([
                    'city' => 'Another Logistics account was approved for this municipality/city. This application was not approved.',
                ]);
            }

            throw $exception;
        }
    }

    public function reject(
        User $user,
        User $reviewer,
        string $reason,
        ?int $applicationId = null
    ): AccountApplication {
        return DB::transaction(function () use (
            $user,
            $reviewer,
            $reason,
            $applicationId
        ): AccountApplication {
            $lockedUser = User::query()
                ->lockForUpdate()
                ->findOrFail($user->id);

            $application = $this->lockReviewableApplication(
                $lockedUser,
                $applicationId
            );

            $application->forceFill([
                'status' => AccountStatus::Rejected->value,
                'review_started_at' =>
                    $application->review_started_at ?? now(),
                'decided_at' => now(),
                'reviewed_by' => $reviewer->id,
                'decision_reason' => $reason,
                'revision_notes' => null,
            ])->save();

            $lockedUser->forceFill([
                'status' => AccountStatus::Rejected->value,
                'approved_by' => $reviewer->id,
                'approved_at' => now(),
                'rejection_reason' => $reason,
            ])->save();

            return $application->fresh([
                'user',
                'requestedRole',
                'documents',
            ]);
        });
    }

    public function requestRevision(
        User $user,
        User $reviewer,
        string $notes,
        ?int $applicationId = null
    ): AccountApplication {
        return DB::transaction(function () use (
            $user,
            $reviewer,
            $notes,
            $applicationId
        ): AccountApplication {
            $lockedUser = User::query()
                ->lockForUpdate()
                ->findOrFail($user->id);

            $application = $this->lockReviewableApplication(
                $lockedUser,
                $applicationId
            );

            $application->forceFill([
                'status' => 'needs_revision',
                'review_started_at' => $application->review_started_at ?? now(),
                'reviewed_by' => $reviewer->id,
                'revision_notes' => $notes,
                'decision_reason' => null,
                'decided_at' => null,
            ])->save();

            $lockedUser->forceFill([
                'status' => AccountStatus::NeedsRevision->value,
                'approved_by' => $reviewer->id,
                'approved_at' => null,
                'rejection_reason' => null,
            ])->save();

            return $application->fresh([
                'user',
                'requestedRole',
                'documents',
            ]);
        });
    }

    private function lockReviewableApplication(
        User $user,
        ?int $applicationId = null
    ): AccountApplication
    {
        if ($applicationId !== null) {
            $application = AccountApplication::query()
                ->with(['requestedRole', 'documents'])
                ->lockForUpdate()
                ->find($applicationId);

            $reviewableUserStatuses = [
                AccountStatus::Pending->value,
                AccountStatus::NeedsRevision->value,
            ];

            $reviewableApplicationStatuses = [
                AccountStatus::Pending->value,
                'submitted',
                'under_review',
                AccountStatus::NeedsRevision->value,
            ];

            if (
                ! $application
                || $application->user_id !== $user->id
                || $application->requestedRole?->name !== $user->role
                || ! in_array($user->status, $reviewableUserStatuses, true)
                || ! in_array($application->status, $reviewableApplicationStatuses, true)
            ) {
                throw new ConflictHttpException(
                    'The selected application is no longer available for review.'
                );
            }

            if ($application->status === AccountStatus::Pending->value) {
                $application->forceFill(['status' => 'submitted'])->save();
            }

            return $application;
        }

        $application = $this->ensureLegacyApplication($user);

        abort_unless($application, 422);

        $application = AccountApplication::query()
            ->with(['requestedRole', 'documents'])
            ->lockForUpdate()
            ->findOrFail($application->id);

        if ($application->status === AccountStatus::Pending->value) {
            $application->forceFill(['status' => 'submitted'])->save();
        }

        abort_unless(
            $application->user_id === $user->id
            && $application->requestedRole?->name === $user->role
            && in_array(
                $application->status,
                ['submitted', 'under_review', 'needs_revision'],
                true
            ),
            422
        );

        return $application;
    }

    private function applicationStatusForUser(User $user): string
    {
        return match ($user->status) {
            AccountStatus::Active->value => 'approved',
            AccountStatus::Rejected->value => 'rejected',
            AccountStatus::NeedsRevision->value => 'needs_revision',
            default => 'submitted',
        };
    }

    private function ensureRequiredDocumentsAreVerified(
        AccountApplication $application
    ): void {
        $required = match ($application->requestedRole->name) {
            UserRole::Buyer->value => [
                ['government_id', 'valid_id'],
            ],

            UserRole::Seller->value,
            UserRole::Logistics->value => [
                ['government_id', 'valid_id'],
                ['business_permit'],
            ],

            UserRole::Rider->value => [
                ['driver_license'],
                ['or_cr'],
            ],

            default => [],
        };

        $documents = $application
            ->documents
            ->keyBy('document_type');

        $unverified = collect($required)->filter(
            fn (array $types): bool =>
                ! collect($types)->contains(
                    fn (string $type): bool =>
                        $documents->has($type)
                        && $documents
                            ->get($type)
                            ->verification_status === 'verified'
                )
        );

        if ($unverified->isNotEmpty()) {
            throw ValidationException::withMessages([
                'documents' =>
                    'All required documents must be verified before approval.',
            ]);
        }
    }

    /**
     * Keep an already-active legacy account represented in role_user.
     * This is safe to call on every successful login.
     */
    public function syncActiveRole(User $user): void
    {
        if (
            $user->status !== AccountStatus::Active->value
            || blank($user->role)
        ) {
            return;
        }

        $role = $this->role((string) $user->role);

        $user->roles()->syncWithoutDetaching([
            $role->id => [
                'assigned_at' => now(),
            ],
        ]);
    }

    /**
     * One-time/current-data normalization used by the artisan sync command.
     */
    public function backfillUser(User $user): void
    {
        if (blank($user->role)) {
            return;
        }

        if ($user->role === UserRole::Admin->value) {
            $this->syncActiveRole($user);
            return;
        }

        $application = $this->ensureLegacyApplication($user);

        if ($user->status === AccountStatus::Active->value) {
            $this->syncActiveRole($user);

            if ($application) {
                $application->forceFill([
                    'status' => 'approved',
                    'decided_at' =>
                        $application->decided_at
                        ?? $user->approved_at
                        ?? now(),
                    'reviewed_by' =>
                        $application->reviewed_by
                        ?? $user->approved_by,
                ])->save();
            }

            $this->createApprovedProfile(
                $user,
                $application
            );

            return;
        }

        if ($application) {
            $application->forceFill([
                'status' => $this->applicationStatusForUser($user),
                'decided_at' =>
                    $user->status === AccountStatus::Rejected->value
                        ? (
                            $application->decided_at
                            ?? $user->approved_at
                            ?? now()
                        )
                        : $application->decided_at,
                'reviewed_by' =>
                    $application->reviewed_by
                    ?? $user->approved_by,
                'decision_reason' =>
                    $application->decision_reason
                    ?? $user->rejection_reason,
            ])->save();
        }
    }

    private function ensureLegacyApplication(
        User $user
    ): ?AccountApplication {
        if (
            blank($user->role)
            || $user->role === UserRole::Admin->value
        ) {
            return null;
        }

        $role = $this->role((string) $user->role);

        $existing = AccountApplication::query()
            ->where('user_id', $user->id)
            ->where('requested_role_id', $role->id)
            ->latest('id')
            ->first();

        if ($existing) {
            return $existing;
        }

        $address = $user->addresses()->first();

        if (! $address) {
            $address = Address::create([
                'user_id' => $user->id,
                'label' => $this->addressLabel(
                    (string) $user->role
                ),
                'recipient_name' =>
                    $user->name
                    ?: trim(
                        $user->first_name . ' ' .
                        $user->last_name
                    ),
                'phone' =>
                    $user->contact_number
                    ?: $user->phone
                    ?: 'Not provided',
                'house_number' => null,
                'street' =>
                    $user->street_address ?: 'Not provided',
                'barangay' =>
                    $user->barangay ?: 'Not provided',
                'city_municipality' =>
                    $user->city ?: 'Not provided',
                'province' =>
                    $user->province ?: 'Not provided',
                'postal_code' => null,
                'is_default_shipping' =>
                    $user->role === UserRole::Buyer->value,
                'is_default_pickup' => in_array(
                    $user->role,
                    [
                        UserRole::Seller->value,
                        UserRole::Logistics->value,
                    ],
                    true
                ),
            ]);
        }

        $sponsorProfile = null;

        if (
            $user->role === UserRole::Rider->value
            && $user->logistics_id
        ) {
            $sponsor = User::find($user->logistics_id);

            if ($sponsor) {
                $sponsorProfile =
                    $this->ensureLogisticsProfile($sponsor);
            }
        }

        $application = AccountApplication::create([
            'application_no' =>
                $this->applicationNumber((string) $user->role),
            'user_id' => $user->id,
            'requested_role_id' => $role->id,
            'sponsor_logistics_profile_id' =>
                $sponsorProfile?->id,
            'business_name' => $user->business_name,
            'business_category_id' =>
                $this->categoryId($user->business_category),
            'status' => $this->applicationStatusForUser($user),
            'submitted_at' => $user->created_at ?? now(),
            'decided_at' =>
                in_array(
                    $user->status,
                    [
                        AccountStatus::Active->value,
                        AccountStatus::Rejected->value,
                    ],
                    true
                )
                    ? ($user->approved_at ?? null)
                    : null,
            'reviewed_by' => $user->approved_by,
            'decision_reason' => $user->rejection_reason,
        ]);

        foreach ($this->legacyDocuments($user) as $document) {
            $this->storeDocument($application, $document);
        }

        return $application;
    }

    private function storeAddress(
        User $user,
        string $role,
        array $data
    ): Address {
        $existing = Address::query()
            ->where('user_id', $user->id)
            ->where('label', $this->addressLabel($role))
            ->first();

        $city = (string) ($data['city'] ?? $data['municipality'] ?? 'Not provided');
        $province = (string) ($data['province'] ?? 'Not provided');
        $cityCode = app(PostalCodeLookup::class)->canonicalCityCode(
            isset($data['city_code']) ? (string) $data['city_code'] : null,
            $province,
            $city,
        );

        $payload = [
            'recipient_name' =>
                $user->name
                ?: trim(
                    $user->first_name . ' ' .
                    $user->last_name
                ),
            'phone' =>
                $user->contact_number
                ?: $user->phone
                ?: 'Not provided',
            'house_number' =>
                $data['house_number'] ?? null,
            'street' =>
                $data['street']
                ?? $data['street_name']
                ?? 'Not provided',
            'barangay' =>
                $data['barangay'] ?? 'Not provided',
            'city_municipality' =>
                $city,
            'province' =>
                $province,
            'postal_code' =>
                $data['postal_code'] ?? null,
            'psgc_city_code' => $cityCode,
            'is_default_shipping' =>
                $role === UserRole::Buyer->value,
            'is_default_pickup' => in_array(
                $role,
                [
                    UserRole::Seller->value,
                    UserRole::Logistics->value,
                ],
                true
            ),
        ];

        if ($existing) {
            $existing->forceFill($payload)->save();

            return $existing;
        }

        return Address::create([
            'user_id' => $user->id,
            'label' => $this->addressLabel($role),
            ...$payload,
        ]);
    }

    private function storeDocument(
        AccountApplication $application,
        array $document
    ): void {
        $path = (string) ($document['path'] ?? '');
        $type = (string) ($document['type'] ?? '');

        if ($type === 'valid_id') {
            $type = 'government_id';
        }

        if ($path === '' || $type === '') {
            return;
        }

        /** @var UploadedFile|null $file */
        $file = $document['file'] ?? null;

        $originalName =
            $file?->getClientOriginalName()
            ?: basename($path);

        $mime =
            $file?->getMimeType()
            ?: 'application/octet-stream';

        $size = $file?->getSize();

        if (
            $size === null
            && Storage::disk('local')->exists($path)
        ) {
            $size = Storage::disk('local')->size($path);
        }

        ApplicationDocument::updateOrCreate(
            [
                'application_id' => $application->id,
                'document_type' => $type,
            ],
            [
                'file_path' => $path,
                'original_name' => $originalName,
                'mime_type' => $mime,
                'size_bytes' => max(0, (int) ($size ?? 0)),
                'verification_status' => 'pending',
            ]
        );
    }

    private function createApprovedProfile(
        User $user,
        ?AccountApplication $application
    ): void {
        if ($user->role === UserRole::Seller->value) {
            $sellerProfile = SellerProfile::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'application_id' => $application?->id,
                    'legal_business_name' =>
                        $user->business_name
                        ?: $user->name
                        ?: 'Bearly Seller',
                    'approved_category_id' =>
                        $application?->business_category_id,
                    'pickup_address_id' =>
                        $user->addresses()->latest('id')->value('id'),
                    'approved_at' => now(),
                ]
            );

            $this->sellerProductService->storeForProfile(
                $sellerProfile,
                $user,
            );

            return;
        }

        if ($user->role === UserRole::Logistics->value) {
            $this->ensureLogisticsProfile(
                $user,
                $application
            );

            return;
        }

        if ($user->role === UserRole::Rider->value) {
            $sponsorProfile =
                $application?->sponsorLogisticsProfile;

            if (! $sponsorProfile && $user->logistics_id) {
                $sponsor = User::query()
                    ->whereKey($user->logistics_id)
                    ->where('role', UserRole::Logistics->value)
                    ->where('status', AccountStatus::Active->value)
                    ->first();

                if ($sponsor) {
                    $sponsorProfile =
                        $this->ensureLogisticsProfile($sponsor);
                }
            }

            if (! $sponsorProfile) {
                throw new RuntimeException(
                    'Rider approval requires an active Logistics sponsor.'
                );
            }

            $homeAddress = Address::query()
                ->where('user_id', $user->id)
                ->where('label', $this->addressLabel(UserRole::Rider->value))
                ->latest('id')
                ->first();

            if (
                $sponsorProfile->status !== AccountStatus::Active->value
                || ! $homeAddress
                || ! filled($homeAddress->psgc_city_code)
                || (string) $homeAddress->psgc_city_code !== (string) $sponsorProfile->psgc_city_code
            ) {
                throw ValidationException::withMessages([
                    'logistics_partner' => 'The Rider and sponsoring Logistics account must serve the same verified municipality/city.',
                ]);
            }

            $this->ensureRiderProfile(
                $user,
                $sponsorProfile
            );

            return;
        }

    }

    private function ensureRiderProfile(
        User $user,
        LogisticsProfile $sponsorProfile
    ): RiderProfile {
        $profile = RiderProfile::query()
            ->where(
                'user_id',
                $user->id
            )
            ->first();

        $isNew = ! $profile;

        if (! $profile) {
            $profile = new RiderProfile([
                'user_id' => $user->id,
            ]);
        }

        $profile->logistics_profile_id =
            $sponsorProfile->id;

        $profile->vehicle_type =
            $user->vehicle_type
            ?: 'Not specified';

        $profile->plate_number =
            $user->plate_number;

        if ($isNew) {
            $profile->availability_status =
                'offline';
        }

        $profile->verification_status =
            'approved';

        $profile->save();

        return $this->riderZoneAssignment->sync($profile->refresh());
    }

    private function ensureLogisticsProfile(
        User $user,
        ?AccountApplication $application = null
    ): LogisticsProfile {
        $application ??= AccountApplication::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->first();

        $serviceAddress = Address::query()
            ->where('user_id', $user->id)
            ->whereRaw('LOWER(label) = ?', [strtolower($this->addressLabel(UserRole::Logistics->value))])
            ->latest('id')
            ->first();

        $cityCode = $serviceAddress?->psgc_city_code;

        if (! filled($cityCode) || ! preg_match('/^[0-9]{6,10}$/D', (string) $cityCode)) {
            throw ValidationException::withMessages([
                'city' => 'The Logistics application must have a valid PSGC municipality/city code before approval.',
            ]);
        }

        $existingCityOwner = LogisticsProfile::withTrashed()
            ->where('psgc_city_code', $cityCode)
            ->where('user_id', '<>', $user->id)
            ->lockForUpdate()
            ->first();

        if ($existingCityOwner) {
            throw ValidationException::withMessages([
                'city' => 'Another Logistics account already owns this municipality/city.',
            ]);
        }

        $existingProfile = LogisticsProfile::withTrashed()
            ->where('user_id', $user->id)
            ->first();

        if (
            $existingProfile
            && filled($existingProfile->psgc_city_code)
            && (string) $existingProfile->psgc_city_code !== (string) $cityCode
        ) {
            throw ValidationException::withMessages([
                'city' => 'A Logistics account cannot change its registered service municipality/city.',
            ]);
        }

        $profile = LogisticsProfile::updateOrCreate(
            ['user_id' => $user->id],
            [
                'application_id' => $application?->id,
                'legal_name' =>
                    $user->business_name
                    ?: $user->name
                    ?: 'Bearly Logistics',
                'display_name' =>
                    $user->business_name
                    ?: $user->name
                    ?: 'Bearly Logistics',
                'contact_phone' =>
                    $user->contact_number
                    ?: $user->phone
                    ?: 'Not provided',
                'psgc_city_code' => (string) $cityCode,
                'status' => 'active',
            ]
        );

        $this->ensureSortingCenter(
            $user,
            $profile
        );

        return $profile;
    }

    private function ensureSortingCenter(
        User $user,
        LogisticsProfile $profile
    ): SortingCenter {
        $address = Address::query()
            ->where('user_id', $user->id)
            ->whereRaw('LOWER(label) = ?', [strtolower($this->addressLabel(UserRole::Logistics->value))])
            ->latest('id')
            ->first();

        $address ??= Address::query()
            ->where('user_id', $user->id)
            ->where('is_default_pickup', true)
            ->latest('id')
            ->first();

        $address ??= $user->addresses()
            ->latest('id')
            ->first();

        if (! $address) {
            $address = $this->storeAddress(
                $user,
                UserRole::Logistics->value,
                [
                    'street' =>
                        $user->street_address
                        ?: 'Not provided',
                    'barangay' =>
                        $user->barangay
                        ?: 'Not provided',
                    'municipality' =>
                        $user->city
                        ?: 'Not provided',
                    'province' =>
                        $user->province
                        ?: 'Not provided',
                ]
            );
        }

        $centers = SortingCenter::withTrashed()
            ->where(
                'logistics_profile_id',
                $profile->id
            )
            ->orderBy('id')
            ->lockForUpdate()
            ->limit(2)
            ->get();

        if ($centers->count() > 1) {
            throw ValidationException::withMessages([
                'city' => 'This Logistics account has multiple Sorting Center records. Resolve the existing center records before provisioning can continue; no centers were changed.',
            ]);
        }

        $center = $centers->first();

        if ($center?->trashed()) {
            throw ValidationException::withMessages([
                'city' => 'This Logistics account’s Sorting Center is archived. Review and restore it before provisioning can continue.',
            ]);
        }

        if ($center) {
            $center->forceFill([
                'address_id' => $address->id,
                'name' => $profile->display_name,
                'contact_phone' => $profile->contact_phone,
            ])->save();

            return $center;
        }

        return SortingCenter::create([
            'logistics_profile_id' => $profile->id,
            'address_id' => $address->id,
            'name' => $profile->display_name,
            'code' => $this->sortingCenterCode(
                $profile
            ),
            'contact_phone' => $profile->contact_phone,
            'status' => 'active',
        ]);
    }

    private function sortingCenterCode(
        LogisticsProfile $profile
    ): string {
        return 'SC-' . $profile->id;
    }

    private function role(string $name): Role
    {
        $labels = [
            UserRole::Admin->value => 'Administrator',
            UserRole::Buyer->value => 'Buyer',
            UserRole::Seller->value => 'Seller',
            UserRole::Logistics->value => 'Logistics',
            UserRole::Rider->value => 'Rider',
        ];

        return Role::firstOrCreate(
            ['name' => $name],
            [
                'display_name' =>
                    $labels[$name]
                    ?? Str::headline($name),
            ]
        );
    }

    private function categoryId(?string $name): ?int
    {
        if (blank($name)) {
            return null;
        }

        return Category::query()
            ->whereRaw('LOWER(name) = ?', [
                strtolower(trim((string) $name)),
            ])
            ->value('id');
    }

    private function applicationNumber(string $role): string
    {
        do {
            $number = sprintf(
                'BRLY-%s-%s-%s',
                strtoupper(substr($role, 0, 3)),
                now()->format('Ymd'),
                Str::upper(Str::random(6))
            );
        } while (
            AccountApplication::query()
                ->where('application_no', $number)
                ->exists()
        );

        return $number;
    }

    private function addressLabel(string $role): string
    {
        return match ($role) {
            UserRole::Seller->value => 'Pickup',
            UserRole::Logistics->value => 'Sorting center',
            default => 'Home',
        };
    }

    private function legacyDocuments(User $user): array
    {
        return array_values(array_filter([
            $user->valid_id_path
                ? [
                    'type' => 'valid_id',
                    'path' => $user->valid_id_path,
                ]
                : null,
            $user->business_permit_path
                ? [
                    'type' => 'business_permit',
                    'path' => $user->business_permit_path,
                ]
                : null,
            $user->or_cr_path
                ? [
                    'type' => 'or_cr',
                    'path' => $user->or_cr_path,
                ]
                : null,
            $user->driver_license_path
                ? [
                    'type' => 'driver_license',
                    'path' => $user->driver_license_path,
                ]
                : null,
        ]));
    }
}
