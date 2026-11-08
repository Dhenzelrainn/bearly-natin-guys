<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\RiderWorkType;
use App\Enums\UserRole;
use App\Models\LogisticsProfile;
use App\Models\RiderAttendanceQrStation;
use App\Models\RiderAttendanceScan;
use App\Models\RiderEarning;
use App\Models\RiderProfile;
use App\Models\RiderShift;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class RiderAttendanceQrService
{
    private const QR_PREFIX = 'bearly-attendance:v1:';

    public function __construct(
        private readonly RiderShiftService $shifts,
        private readonly CourierDailyEarningService $courierDaily,
    ) {}

    /** @return array{challenge: string, generation: int, expires_at: string, expires_in: int} */
    public function issueChallenge(User $operator): array
    {
        $profile = $this->activeLogisticsProfileFor($operator);

        return DB::transaction(function () use ($operator, $profile): array {
            $lockedProfile = LogisticsProfile::query()
                ->whereKey($profile->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedProfile->status !== AccountStatus::Active->value) {
                throw new AuthorizationException('An active Logistics provider is required to issue an attendance QR.');
            }

            $station = RiderAttendanceQrStation::query()
                ->where('logistics_profile_id', $lockedProfile->id)
                ->lockForUpdate()
                ->first();

            if (! $station) {
                $station = RiderAttendanceQrStation::query()->create([
                    'logistics_profile_id' => $lockedProfile->id,
                    'generation' => 0,
                ]);
            }

            $token = bin2hex(random_bytes(32));
            $generation = (int) $station->generation + 1;
            $ttlSeconds = max(15, (int) config('bearly_rider_compensation.rider_attendance_qr_ttl_seconds', 45));
            $expiresAt = now()->addSeconds($ttlSeconds);

            $station->update([
                'token_hash' => hash('sha256', $token),
                'generation' => $generation,
                'expires_at' => $expiresAt,
            ]);

            return [
                'challenge' => self::QR_PREFIX.$station->id.':'.$generation.':'.$token,
                'generation' => $generation,
                'expires_at' => $expiresAt->toIso8601String(),
                'expires_in' => $ttlSeconds,
            ];
        }, attempts: 3);
    }

    /**
     * Redeem the current provider QR and record the only allowed next
     * attendance transition. The raw token is never written to the database.
     *
     * @return array{scan_type: string, scanned_at: string, shift_id: int}
     */
    public function scan(RiderProfile $rider, User $actor, string $challenge): array
    {
        $parts = $this->parseChallenge($challenge);

        return DB::transaction(function () use ($rider, $actor, $parts): array {
            $station = RiderAttendanceQrStation::query()
                ->whereKey($parts['station_id'])
                ->lockForUpdate()
                ->first();

            if (! $station) {
                throw ValidationException::withMessages([
                    'qr_payload' => 'This attendance QR is not recognized. Scan the current QR displayed by your Logistics provider.',
                ]);
            }

            if ((int) $station->logistics_profile_id !== (int) $rider->logistics_profile_id) {
                throw new AuthorizationException('This attendance QR belongs to another Logistics provider.');
            }

            $this->assertChallengeIsCurrent($station, $parts['generation'], $parts['token']);

            if (RiderAttendanceScan::query()
                ->where('station_id', $station->id)
                ->where('generation', $parts['generation'])
                ->where('rider_profile_id', $rider->id)
                ->exists()) {
                throw new ConflictHttpException('This QR has already been used for your attendance. Ask Logistics to display a fresh QR.');
            }

            $lockedRider = RiderProfile::query()
                ->whereKey($rider->id)
                ->where('user_id', $actor->id)
                ->where('logistics_profile_id', $station->logistics_profile_id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertRiderCanScan($lockedRider, $actor);
            $this->assertLogisticsProviderCanReceiveAttendance($station->logistics_profile_id);

            $openShifts = RiderShift::query()
                ->where('rider_profile_id', $lockedRider->id)
                ->whereNull('checked_out_at')
                ->orderByDesc('checked_in_at')
                ->lockForUpdate()
                ->get();

            if ($openShifts->count() > 1) {
                throw new ConflictHttpException('More than one open attendance shift needs Logistics review before another scan.');
            }

            $isCheckOut = $openShifts->isNotEmpty();

            if (! $isCheckOut) {
                $this->assertCourierWorkDateCanOpen($lockedRider);
            }

            $shift = $isCheckOut
                ? $this->shifts->checkOut($lockedRider, $actor, 'qr')
                : $this->shifts->checkIn($lockedRider, $actor, 'qr');
            $scanType = $isCheckOut ? 'check_out' : 'check_in';
            $scannedAt = $isCheckOut ? $shift->checked_out_at : $shift->checked_in_at;

            RiderAttendanceScan::query()->create([
                'station_id' => $station->id,
                'logistics_profile_id' => $station->logistics_profile_id,
                'rider_profile_id' => $lockedRider->id,
                'rider_shift_id' => $shift->id,
                'generation' => $parts['generation'],
                'scan_type' => $scanType,
                'scanned_at' => $scannedAt,
            ]);

            // checkOut reconciles before its QR proof row exists; reconcile
            // again within this transaction after persisting the check-out scan.
            if ($isCheckOut && $lockedRider->work_type === RiderWorkType::Courier->value) {
                $this->courierDaily->reconcileShift($shift);
            }

            return [
                'scan_type' => $scanType,
                'scanned_at' => $scannedAt->toIso8601String(),
                'shift_id' => (int) $shift->id,
            ];
        }, attempts: 3);
    }

    private function activeLogisticsProfileFor(User $operator): LogisticsProfile
    {
        if ($operator->status !== AccountStatus::Active->value
            || ! $operator->hasRole(UserRole::Logistics->value)) {
            throw new AuthorizationException('An active Logistics account is required.');
        }

        $profile = $operator->logisticsProfile()
            ->where('status', AccountStatus::Active->value)
            ->first();

        if (! $profile) {
            throw new AuthorizationException('An active Logistics profile is required.');
        }

        return $profile;
    }

    private function assertLogisticsProviderCanReceiveAttendance(int $profileId): void
    {
        $provider = LogisticsProfile::query()
            ->with('user')
            ->whereKey($profileId)
            ->first();

        if (! $provider
            || $provider->status !== AccountStatus::Active->value
            || $provider->user?->status !== AccountStatus::Active->value
            || ! $provider->user?->hasRole(UserRole::Logistics->value)) {
            throw new AuthorizationException('This Logistics provider is not active.');
        }
    }

    private function assertRiderCanScan(RiderProfile $rider, User $actor): void
    {
        if ($actor->status !== AccountStatus::Active->value
            || ! $actor->hasRole(UserRole::Rider->value)
            || $rider->verification_status !== 'approved'
            || ! in_array($rider->work_type, [null, RiderWorkType::Local->value, RiderWorkType::Courier->value], true)) {
            throw new AuthorizationException('Only an active, approved Rider can record QR attendance.');
        }
    }

    private function assertCourierWorkDateCanOpen(RiderProfile $rider): void
    {
        if ($rider->work_type !== RiderWorkType::Courier->value) {
            return;
        }

        $workDate = now(RiderShift::TIMEZONE)->toDateString();
        $earning = RiderEarning::query()
            ->where('rider_profile_id', $rider->id)
            ->where('type', config('bearly_rider_compensation.courier_daily_earning_type', 'courier_daily'))
            ->whereDate('work_date', $workDate)
            ->first();

        if ($earning && (
            $earning->paid_at !== null
            || $earning->status === 'paid'
            || $earning->payout()->exists()
            || $earning->payoutBatchItem()->exists()
        )) {
            throw new ConflictHttpException('This work date already has a paid or batched Courier earning. A new shift cannot be opened for that date.');
        }
    }

    /** @return array{station_id: int, generation: int, token: string} */
    private function parseChallenge(string $challenge): array
    {
        if (! preg_match('/\Abearly-attendance:v1:(\d+):(\d+):([a-f0-9]{64})\z/D', $challenge, $matches)) {
            throw ValidationException::withMessages([
                'qr_payload' => 'That QR is not a Bearly attendance code. Scan the current QR displayed by your Logistics provider.',
            ]);
        }

        return [
            'station_id' => (int) $matches[1],
            'generation' => (int) $matches[2],
            'token' => $matches[3],
        ];
    }

    /** @param array{station_id: int, generation: int, token: string} $parts */
    private function assertChallengeIsCurrent(
        RiderAttendanceQrStation $station,
        int $generation,
        string $token,
    ): void {
        if ((int) $station->generation !== $generation
            || ! $station->token_hash
            || ! hash_equals((string) $station->token_hash, hash('sha256', $token))
            || ! $station->expires_at
            || $station->expires_at->lessThanOrEqualTo(now())) {
            throw new ConflictHttpException('This attendance QR has expired or was replaced. Ask Logistics to display the current QR.');
        }
    }
}
