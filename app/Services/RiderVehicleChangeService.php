<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\RiderWorkType;
use App\Enums\UserRole;
use App\Models\DispatchBatch;
use App\Models\LinehaulRun;
use App\Models\LogisticsProfile;
use App\Models\PickupAssignment;
use App\Models\RefundCashPayout;
use App\Models\RiderProfile;
use App\Models\RiderVehicleChangeRequest;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

class RiderVehicleChangeService
{
    /**
     * Store a proposal without changing the currently approved Rider profile.
     *
     * @param  array<string, mixed>  $data
     */
    public function submit(
        User $user,
        array $data,
        UploadedFile $orCr,
        UploadedFile $driverLicense
    ): RiderVehicleChangeRequest {
        $profile = $this->activeRiderProfile($user);

        try {
            $workType = RiderWorkType::from((string) ($data['work_type'] ?? ''));
        } catch (\ValueError) {
            throw ValidationException::withMessages([
                'work_type' => 'Choose a supported Rider work type.',
            ]);
        }

        if (! in_array(
            $data['vehicle_type'] ?? null,
            RiderWorkType::vehicleTypes($workType),
            true
        )) {
            throw ValidationException::withMessages([
                'vehicle_type' => 'The requested vehicle is not supported for this work type.',
            ]);
        }

        $pending = RiderVehicleChangeRequest::query()
            ->where('rider_profile_id', $profile->id)
            ->where('status', 'pending')
            ->exists();

        if ($pending) {
            throw new ConflictHttpException('A vehicle or work-type change is already awaiting Logistics review.');
        }

        $this->assertPlateAvailable((string) $data['plate_number'], $user, $profile);

        $orCrPath = null;
        $licensePath = null;

        try {
            $orCrPath = $orCr->store('rider-vehicle-change-requests/or-cr', 'local');
            $licensePath = $driverLicense->store('rider-vehicle-change-requests/licenses', 'local');

            if (! is_string($orCrPath) || ! is_string($licensePath)) {
                throw new RuntimeException('The uploaded vehicle documents could not be stored.');
            }

            return DB::transaction(function () use (
                $user,
                $profile,
                $data,
                $orCr,
                $driverLicense,
                $orCrPath,
                $licensePath
            ): RiderVehicleChangeRequest {
                $lockedProfile = RiderProfile::query()
                    ->whereKey($profile->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (RiderVehicleChangeRequest::query()
                    ->where('rider_profile_id', $lockedProfile->id)
                    ->where('status', 'pending')
                    ->lockForUpdate()
                    ->exists()) {
                    throw new ConflictHttpException('A vehicle or work-type change is already awaiting Logistics review.');
                }

                $this->assertPlateAvailable((string) $data['plate_number'], $user, $lockedProfile);

                return RiderVehicleChangeRequest::query()->create([
                    'rider_profile_id' => $lockedProfile->id,
                    'requested_work_type' => $data['work_type'],
                    'vehicle_type' => $data['vehicle_type'],
                    'vehicle_model' => filled($data['vehicle_model'] ?? null)
                        ? trim((string) $data['vehicle_model'])
                        : null,
                    'plate_number' => strtoupper(trim((string) $data['plate_number'])),
                    'or_cr_disk' => 'local',
                    'or_cr_path' => $orCrPath,
                    'or_cr_original_name' => $this->safeOriginalName($orCr),
                    'or_cr_mime_type' => (string) $orCr->getMimeType(),
                    'or_cr_size_bytes' => (int) $orCr->getSize(),
                    'driver_license_disk' => 'local',
                    'driver_license_path' => $licensePath,
                    'driver_license_original_name' => $this->safeOriginalName($driverLicense),
                    'driver_license_mime_type' => (string) $driverLicense->getMimeType(),
                    'driver_license_size_bytes' => (int) $driverLicense->getSize(),
                    'status' => 'pending',
                ]);
            }, attempts: 3);
        } catch (Throwable $exception) {
            Storage::disk('local')->delete(array_filter([$orCrPath, $licensePath]));

            throw $exception;
        }
    }

    public function approve(
        RiderVehicleChangeRequest $change,
        User $reviewer,
        ?int $linehaulCapacity = null
    ): RiderVehicleChangeRequest {
        return DB::transaction(function () use ($change, $reviewer, $linehaulCapacity): RiderVehicleChangeRequest {
            $provider = $this->logisticsProfile($reviewer);
            $lockedChange = RiderVehicleChangeRequest::query()
                ->lockForUpdate()
                ->findOrFail($change->id);
            $profile = RiderProfile::query()
                ->whereKey($lockedChange->rider_profile_id)
                ->lockForUpdate()
                ->firstOrFail();
            $riderUser = User::query()->whereKey($profile->user_id)->lockForUpdate()->firstOrFail();

            $this->assertOwnedPendingChange($lockedChange, $profile, $riderUser, $provider);
            $this->ensureNoCrossWorkActiveAssignments($profile, $lockedChange->requested_work_type);

            if ($lockedChange->requested_work_type === RiderWorkType::Courier->value) {
                if (! $linehaulCapacity || $linehaulCapacity > 1000) {
                    throw ValidationException::withMessages([
                        'linehaul_capacity' => 'Enter the Logistics-verified parcel capacity for this Courier vehicle.',
                    ]);
                }

                if (! Storage::disk($lockedChange->or_cr_disk)->exists($lockedChange->or_cr_path)
                    || ! Storage::disk($lockedChange->driver_license_disk)->exists($lockedChange->driver_license_path)) {
                    throw ValidationException::withMessages([
                        'vehicle_documents' => 'Both vehicle documents must be available for verification before approving Courier work.',
                    ]);
                }
            }

            $this->assertPlateAvailable($lockedChange->plate_number, $riderUser, $profile);

            $now = now();
            $profile->update([
                'vehicle_type' => $lockedChange->vehicle_type,
                'vehicle_model' => $lockedChange->vehicle_model,
                'plate_number' => $lockedChange->plate_number,
                'work_type' => $lockedChange->requested_work_type,
                'linehaul_parcel_capacity' => $lockedChange->requested_work_type === RiderWorkType::Courier->value
                    ? $linehaulCapacity
                    : null,
                'courier_vehicle_verified_by' => $lockedChange->requested_work_type === RiderWorkType::Courier->value
                    ? $reviewer->id
                    : null,
                'courier_vehicle_verified_at' => $lockedChange->requested_work_type === RiderWorkType::Courier->value
                    ? $now
                    : null,
            ]);
            $riderUser->update([
                'vehicle_type' => $lockedChange->vehicle_type,
                'plate_number' => $lockedChange->plate_number,
            ]);
            $lockedChange->update([
                'status' => 'approved',
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => $now,
                'review_notes' => 'Vehicle and work type verified by sponsoring Logistics.',
            ]);

            return $lockedChange->fresh(['riderProfile.user', 'reviewer']);
        }, attempts: 3);
    }

    public function reject(
        RiderVehicleChangeRequest $change,
        User $reviewer,
        string $reason
    ): RiderVehicleChangeRequest {
        $reason = trim($reason);

        if (mb_strlen($reason) < 5) {
            throw ValidationException::withMessages([
                'review_notes' => 'Provide a clear reason for rejecting this vehicle or work-type change.',
            ]);
        }

        return DB::transaction(function () use ($change, $reviewer, $reason): RiderVehicleChangeRequest {
            $provider = $this->logisticsProfile($reviewer);
            $lockedChange = RiderVehicleChangeRequest::query()
                ->lockForUpdate()
                ->findOrFail($change->id);
            $profile = RiderProfile::query()
                ->whereKey($lockedChange->rider_profile_id)
                ->lockForUpdate()
                ->firstOrFail();
            $riderUser = User::query()->whereKey($profile->user_id)->lockForUpdate()->firstOrFail();

            $this->assertOwnedPendingChange($lockedChange, $profile, $riderUser, $provider);
            $lockedChange->update([
                'status' => 'rejected',
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_notes' => $reason,
            ]);

            return $lockedChange->fresh(['riderProfile.user', 'reviewer']);
        }, attempts: 3);
    }

    public function activeRiderProfile(User $user): RiderProfile
    {
        $profile = $user->riderProfile()->first();

        if ($user->role !== UserRole::Rider->value
            || $user->status !== AccountStatus::Active->value
            || ! $profile
            || $profile->verification_status !== 'approved') {
            throw new AuthorizationException('An active, approved Rider profile is required.');
        }

        return $profile;
    }

    private function logisticsProfile(User $user): LogisticsProfile
    {
        $profile = $user->logisticsProfile()->first();

        if ($user->role !== UserRole::Logistics->value
            || $user->status !== AccountStatus::Active->value
            || ! $profile
            || $profile->status !== AccountStatus::Active->value) {
            throw new AuthorizationException('An active Logistics account is required.');
        }

        return $profile;
    }

    private function assertOwnedPendingChange(
        RiderVehicleChangeRequest $change,
        RiderProfile $profile,
        User $riderUser,
        LogisticsProfile $provider
    ): void {
        if ((int) $profile->logistics_profile_id !== (int) $provider->id) {
            throw new AuthorizationException('This Rider belongs to another Logistics provider.');
        }

        if ($riderUser->status !== AccountStatus::Active->value
            || $profile->verification_status !== 'approved') {
            throw new ConflictHttpException('Only an active, approved Rider profile can change its vehicle/work type.');
        }

        if ($change->status !== 'pending') {
            throw new ConflictHttpException('This vehicle change request has already been reviewed.');
        }
    }

    private function assertPlateAvailable(string $plate, User $user, RiderProfile $profile): void
    {
        $normalized = strtoupper(trim($plate));
        $userHasPlate = User::withTrashed()
            ->where('role', UserRole::Rider->value)
            ->where('id', '<>', $user->id)
            ->whereRaw('UPPER(TRIM(plate_number)) = ?', [$normalized])
            ->exists();
        $profileHasPlate = RiderProfile::withTrashed()
            ->where('id', '<>', $profile->id)
            ->whereRaw('UPPER(TRIM(plate_number)) = ?', [$normalized])
            ->exists();
        $requestHasPlate = RiderVehicleChangeRequest::query()
            ->where('status', 'pending')
            ->where('rider_profile_id', '<>', $profile->id)
            ->whereRaw('UPPER(TRIM(plate_number)) = ?', [$normalized])
            ->exists();

        if ($userHasPlate || $profileHasPlate || $requestHasPlate) {
            throw ValidationException::withMessages([
                'plate_number' => 'This plate number is already registered or awaiting review for another Rider.',
            ]);
        }
    }

    private function ensureNoCrossWorkActiveAssignments(RiderProfile $profile, string $requestedWorkType): void
    {
        $hasActiveLinehaul = LinehaulRun::query()
            ->where('courier_rider_profile_id', $profile->id)
            ->whereIn('status', ['assigned', 'loading', 'loaded', 'departed', 'arrived', 'exception'])
            ->exists();

        if ($hasActiveLinehaul) {
            throw new ConflictHttpException(
                'Complete the active line-haul run and destination acceptance before approving a vehicle or work-type change.'
            );
        }

        if ($requestedWorkType === RiderWorkType::Courier->value) {
            $hasLocalWork = PickupAssignment::query()
                ->where('rider_profile_id', $profile->id)
                ->whereIn('status', ['assigned', 'accepted', 'arrived', 'picked_up'])
                ->exists()
                || DispatchBatch::query()
                    ->where('rider_profile_id', $profile->id)
                    ->whereNotIn('status', ['completed', 'cancelled'])
                    ->exists()
                || RefundCashPayout::query()
                    ->where('rider_profile_id', $profile->id)
                    ->whereIn('status', [
                        'assigned_to_rider',
                        'cash_with_rider',
                        'out_for_delivery',
                        'cash_return_pending',
                        'logistics_return_verification',
                        'delivered_pending_verification',
                    ])
                    ->exists();

            if ($hasLocalWork) {
                throw new ConflictHttpException(
                    'Finish or have Logistics close active local assignments before activating Courier work.'
                );
            }
        }

    }

    private function safeOriginalName(UploadedFile $file): string
    {
        $name = basename(str_replace('\\', '/', $file->getClientOriginalName()));

        return mb_substr($name !== '' ? $name : 'document', 0, 255);
    }
}
