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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class RegistrationLifecycleService
{
    public function __construct(
        private readonly SellerProductService $sellerProductService,
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
                    ->first();

                if (! $sponsor) {
                    throw new RuntimeException(
                        'The selected Logistics partner no longer exists.'
                    );
                }

                $sponsorProfile = $this->ensureLogisticsProfile($sponsor);
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

            return $accountApplication->fresh([
                'requestedRole',
                'documents',
            ]);
        });
    }

    /**
     * Grant the approved role in role_user and synchronize the normalized
     * application/profile records.
     */
    public function approve(
        User $user,
        User $approver
    ): AccountApplication {
        return DB::transaction(function () use ($user, $approver): AccountApplication {
            $lockedUser = User::query()
                ->lockForUpdate()
                ->findOrFail($user->id);

            $application = $this->lockReviewableApplication($lockedUser);
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
    }

    public function reject(
        User $user,
        User $reviewer,
        string $reason
    ): AccountApplication {
        return DB::transaction(function () use (
            $user,
            $reviewer,
            $reason
        ): AccountApplication {
            $lockedUser = User::query()
                ->lockForUpdate()
                ->findOrFail($user->id);

            $application = $this->lockReviewableApplication($lockedUser);

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
        string $notes
    ): AccountApplication {
        return DB::transaction(function () use ($user, $reviewer, $notes): AccountApplication {
            $lockedUser = User::query()
                ->lockForUpdate()
                ->findOrFail($user->id);

            $application = $this->lockReviewableApplication($lockedUser);

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

    private function lockReviewableApplication(User $user): AccountApplication
    {
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
                $data['city']
                ?? $data['municipality']
                ?? 'Not provided',
            'province' =>
                $data['province'] ?? 'Not provided',
            'postal_code' =>
                $data['postal_code'] ?? null,
            'psgc_city_code' =>
                $data['city_code'] ?? null,
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
                $sponsor = User::find($user->logistics_id);

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

        $validHomeCenter = null;

        if ($profile->home_sorting_center_id) {
            $validHomeCenter =
                SortingCenter::query()
                    ->whereKey(
                        $profile
                            ->home_sorting_center_id
                    )
                    ->where(
                        'logistics_profile_id',
                        $sponsorProfile->id
                    )
                    ->where(
                        'status',
                        'active'
                    )
                    ->first();
        }

        if (! $validHomeCenter) {
            $validHomeCenter =
                SortingCenter::query()
                    ->where(
                        'logistics_profile_id',
                        $sponsorProfile->id
                    )
                    ->where(
                        'status',
                        'active'
                    )
                    ->oldest('id')
                    ->first();

            $profile->home_sorting_center_id =
                $validHomeCenter?->id;

            $profile->current_zone_id = null;
        }

        if (
            $profile->current_zone_id
            && $profile->home_sorting_center_id
        ) {
            $currentZoneIsValid =
                SortingZone::query()
                    ->whereKey(
                        $profile->current_zone_id
                    )
                    ->where(
                        'sorting_center_id',
                        $profile
                            ->home_sorting_center_id
                    )
                    ->where(
                        'status',
                        'active'
                    )
                    ->exists();

            if (! $currentZoneIsValid) {
                $profile->current_zone_id =
                    null;
            }
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

        return $profile->refresh();
    }

    private function ensureLogisticsProfile(
        User $user,
        ?AccountApplication $application = null
    ): LogisticsProfile {
        $application ??= AccountApplication::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->first();

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
            ->where(
                'label',
                $this->addressLabel(
                    UserRole::Logistics->value
                )
            )
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

        $center = SortingCenter::query()
            ->where(
                'logistics_profile_id',
                $profile->id
            )
            ->oldest('id')
            ->first();

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
