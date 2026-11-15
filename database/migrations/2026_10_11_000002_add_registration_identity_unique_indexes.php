<?php

use App\Services\InternationalPhone;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberType;
use libphonenumber\PhoneNumberUtil;

return new class extends Migration
{
    public function up(): void
    {
        $normalizedPhones = $this->normalizedPhones();
        [$normalizedRiderUsers, $normalizedRiderProfiles] =
            $this->normalizedRiderPlates();

        DB::transaction(function () use (
            $normalizedPhones,
            $normalizedRiderUsers,
            $normalizedRiderProfiles
        ): void {
            foreach ($normalizedPhones as $userId => $phoneValues) {
                DB::table('users')
                    ->where('id', $userId)
                    ->update($phoneValues);
            }

            foreach ($normalizedRiderUsers as $userId => $plateNumber) {
                DB::table('users')
                    ->where('id', $userId)
                    ->update(['plate_number' => $plateNumber]);
            }

            foreach ($normalizedRiderProfiles as $profileId => $plateNumber) {
                DB::table('rider_profiles')
                    ->where('id', $profileId)
                    ->update(['plate_number' => $plateNumber]);
            }
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex('users_phone_index');
            $table->unique('phone', 'users_phone_unique');
            $table->unique('contact_number', 'users_contact_number_unique');
            $table->unique(
                ['role', 'plate_number'],
                'users_role_plate_number_unique'
            );
        });

        Schema::table('rider_profiles', function (Blueprint $table): void {
            $table->unique(
                'plate_number',
                'rider_profiles_plate_number_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('rider_profiles', function (Blueprint $table): void {
            $table->dropUnique('rider_profiles_plate_number_unique');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique('users_role_plate_number_unique');
            $table->dropUnique('users_contact_number_unique');
            $table->dropUnique('users_phone_unique');
            $table->index('phone', 'users_phone_index');
        });
    }

    /**
     * Normalize valid phone values and stop if a migration would choose
     * between different phone identities or preserve a duplicate identity.
     *
     * @return array<int, array{phone: ?string, contact_number: ?string}>
     */
    private function normalizedPhones(): array
    {
        $rows = DB::table('users')
            ->select('id', 'phone', 'contact_number', 'phone_country')
            ->orderBy('id')
            ->get();

        $canonicalByUser = [];
        $phoneOwners = [];

        foreach ($rows as $row) {
            $candidates = [];

            foreach (['phone', 'contact_number'] as $field) {
                $raw = trim((string) ($row->{$field} ?? ''));

                if ($raw === '') {
                    continue;
                }

                $normalized = $this->normalizePhone(
                    $raw,
                    $row->phone_country
                );

                $identity = $normalized
                    ? 'e164:'.$normalized
                    : 'legacy:'.mb_strtolower($raw);

                $candidates[$identity] = $normalized ?: $raw;
            }

            if (count($candidates) > 1) {
                throw new RuntimeException(
                    'Cannot add registration phone constraints: user IDs '
                    .(int) $row->id
                    .' has conflicting phone and contact_number values. '
                    .'Review the account and retry the migration.'
                );
            }

            if ($candidates === []) {
                continue;
            }

            $identity = array_key_first($candidates);
            $canonical = str_starts_with($identity, 'e164:')
                ? $candidates[$identity]
                : null;

            $canonicalByUser[(int) $row->id] = [
                'phone' => $canonical ?? $this->nullableTrimmed($row->phone),
                'contact_number' => $canonical
                    ?? $this->nullableTrimmed($row->contact_number),
            ];
            $phoneOwners[$identity][] = (int) $row->id;
        }

        foreach ($phoneOwners as $ownerIds) {
            if (count($ownerIds) > 1) {
                throw new RuntimeException(
                    'Cannot add registration phone constraints: duplicate '
                    .'normalized phone values exist for user IDs '
                    .implode(', ', $ownerIds)
                    .'. Resolve these accounts before retrying.'
                );
            }
        }

        return $canonicalByUser;
    }

    private function normalizePhone(
        string $value,
        ?string $country
    ): ?string {
        $country = strtoupper(trim((string) $country));

        if ($country !== '') {
            try {
                return app(InternationalPhone::class)
                    ->normalize($value, $country);
            } catch (ValidationException) {
                return null;
            }
        }

        if (str_starts_with($value, '+')) {
            try {
                $util = PhoneNumberUtil::getInstance();
                $phone = $util->parse($value, null);

                if (
                    $util->isValidNumber($phone)
                    && in_array(
                        $util->getNumberType($phone),
                        [
                            PhoneNumberType::MOBILE,
                            PhoneNumberType::FIXED_LINE_OR_MOBILE,
                        ],
                        true
                    )
                ) {
                    return $util->format(
                        $phone,
                        PhoneNumberFormat::E164
                    );
                }
            } catch (NumberParseException) {
                return null;
            }
        }

        try {
            return app(InternationalPhone::class)
                ->normalize($value, 'PH');
        } catch (ValidationException) {
            return null;
        }
    }

    /**
     * @return array{array<int, string>, array<int, string>}
     */
    private function normalizedRiderPlates(): array
    {
        $riderUsers = DB::table('users')
            ->where('role', 'rider')
            ->orderBy('id')
            ->get(['id', 'plate_number']);

        $profilesForOtherRoles = DB::table('rider_profiles as rider_profiles')
            ->join('users', 'users.id', '=', 'rider_profiles.user_id')
            ->where(function ($query): void {
                $query->whereNull('users.role')
                    ->orWhere('users.role', '<>', 'rider');
            })
            ->pluck('users.id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($profilesForOtherRoles !== []) {
            throw new RuntimeException(
                'Cannot add Rider plate constraints: RiderProfile rows '
                .'belong to non-Rider user IDs '
                .implode(', ', $profilesForOtherRoles)
                .'. Review role/profile ownership before retrying.'
            );
        }

        $profiles = DB::table('rider_profiles as rider_profiles')
            ->join('users', 'users.id', '=', 'rider_profiles.user_id')
            ->where('users.role', 'rider')
            ->orderBy('rider_profiles.id')
            ->get([
                'rider_profiles.id',
                'rider_profiles.user_id',
                'rider_profiles.plate_number',
            ]);

        $profilesByUser = $profiles->keyBy('user_id');
        $plateByUser = [];
        $ownersByPlate = [];

        foreach ($riderUsers as $user) {
            $userPlate = $this->normalizePlate($user->plate_number);
            $profile = $profilesByUser->get($user->id);
            $profilePlate = $this->normalizePlate(
                $profile?->plate_number
            );

            if (
                $userPlate !== null
                && $profilePlate !== null
                && $userPlate !== $profilePlate
            ) {
                throw new RuntimeException(
                    'Cannot add Rider plate constraints: user ID '
                    .(int) $user->id
                    .' has different user/profile plate values. '
                    .'Review the account and retry the migration.'
                );
            }

            $plate = $userPlate ?? $profilePlate;

            if ($plate === null) {
                continue;
            }

            $plateByUser[(int) $user->id] = $plate;
            $ownersByPlate[$plate][(int) $user->id] = true;
        }

        foreach ($ownersByPlate as $ownerIds) {
            if (count($ownerIds) > 1) {
                throw new RuntimeException(
                    'Cannot add Rider plate constraints: duplicate '
                    .'normalized plates exist for user IDs '
                    .implode(', ', array_keys($ownerIds))
                    .'. Resolve these accounts before retrying.'
                );
            }
        }

        $normalizedUsers = [];
        $normalizedProfiles = [];

        foreach ($plateByUser as $userId => $plate) {
            $normalizedUsers[$userId] = $plate;
            $profile = $profilesByUser->get($userId);

            if ($profile) {
                $normalizedProfiles[(int) $profile->id] = $plate;
            }
        }

        return [$normalizedUsers, $normalizedProfiles];
    }

    private function normalizePlate(mixed $value): ?string
    {
        $plate = strtoupper(trim((string) $value));

        return $plate === '' ? null : $plate;
    }

    private function nullableTrimmed(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
};
