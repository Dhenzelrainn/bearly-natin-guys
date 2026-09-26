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
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class RegistrationLifecycleService
{
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
                    'status' => AccountStatus::Pending->value,
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
                    'status' => AccountStatus::Pending->value,
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
    ): void {
        DB::transaction(function () use ($user, $approver): void {
            $application = $this->ensureLegacyApplication($user);
            $role = $this->role((string) $user->role);

            $user->roles()->syncWithoutDetaching([
                $role->id => [
                    'assigned_by' => $approver->id,
                    'assigned_at' => now(),
                ],
            ]);

            if ($application) {
                $application->forceFill([
                    'status' => 'approved',
                    'review_started_at' =>
                        $application->review_started_at ?? now(),
                    'decided_at' => now(),
                    'reviewed_by' => $approver->id,
                    'decision_reason' => null,
                    'revision_notes' => null,
                ])->save();
            }

            $this->createApprovedProfile(
                $user,
                $application
            );
        });
    }

    public function reject(
        User $user,
        User $reviewer,
        string $reason
    ): void {
        DB::transaction(function () use (
            $user,
            $reviewer,
            $reason
        ): void {
            $application = $this->ensureLegacyApplication($user);

            if (! $application) {
                return;
            }

            $application->forceFill([
                'status' => AccountStatus::Rejected->value,
                'review_started_at' =>
                    $application->review_started_at ?? now(),
                'decided_at' => now(),
                'reviewed_by' => $reviewer->id,
                'decision_reason' => $reason,
            ])->save();
        });
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
                'status' => (string) $user->status,
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
            'status' =>
                $user->status === AccountStatus::Active->value
                    ? 'approved'
                    : (string) $user->status,
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
            SellerProfile::updateOrCreate(
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

            RiderProfile::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'logistics_profile_id' =>
                        $sponsorProfile->id,
                    'vehicle_type' =>
                        $user->vehicle_type
                        ?: 'Not specified',
                    'plate_number' =>
                        $user->plate_number,
                    'availability_status' => 'offline',
                    'verification_status' => 'approved',
                ]
            );
        }
    }

    private function ensureLogisticsProfile(
        User $user,
        ?AccountApplication $application = null
    ): LogisticsProfile {
        $application ??= AccountApplication::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->first();

        return LogisticsProfile::updateOrCreate(
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
