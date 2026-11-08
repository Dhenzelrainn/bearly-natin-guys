<?php

namespace Tests\Feature\Rider;

use App\Models\LogisticsProfile;
use App\Models\RiderAttendanceAbsence;
use App\Models\RiderAttendanceQrStation;
use App\Models\RiderAttendanceScan;
use App\Models\RiderProfile;
use App\Models\RiderShift;
use App\Models\User;
use App\Services\CourierDailyEarningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class RiderAttendanceQrTest extends TestCase
{
    use RefreshDatabase;

    public function test_hourly_proration_uses_integer_centavos_and_daily_cap(): void
    {
        $earnings = app(CourierDailyEarningService::class);

        $this->assertSame(0, $earnings->amountForWorkedSeconds(0));
        $this->assertSame(2, $earnings->amountForWorkedSeconds(1));
        $this->assertSame(4063, $earnings->amountForWorkedSeconds(1800));
        $this->assertSame(65000, $earnings->amountForWorkedSeconds(28800));
        $this->assertSame(65000, $earnings->amountForWorkedSeconds(32400));
    }

    public function test_provider_challenge_is_hash_only_and_distinct_scans_record_server_timed_transitions(): void
    {
        [$logisticsUser] = $this->makeProvider('attendance-proof');
        $rider = $this->makeRider($logisticsUser, 'attendance-proof');
        $this->travelTo(Carbon::parse('2026-11-07 07:52:00', 'Asia/Manila'));
        $this->travel(8)->minutes();

        $this->actingAs($logisticsUser)
            ->get(route('logistics.rider-attendance.index'))
            ->assertOk()
            ->assertSee('Rider Attendance')
            ->assertSee('Today’s roster')
            ->assertDontSee('data-logistics-attendance-qr', false);

        $this->actingAs($logisticsUser)
            ->get(route('logistics.dashboard'))
            ->assertOk()
            ->assertSee('Rider attendance QR')
            ->assertSee('data-logistics-attendance-qr', false)
            ->assertSee('Needs attention')
            ->assertSee('Quick actions')
            ->assertSee('Zone readiness')
            ->assertSee('Recent activity')
            ->assertDontSee('Today’s roster');

        $challenge = $this->issueChallenge($logisticsUser);
        $token = substr($challenge, strrpos($challenge, ':') + 1);
        $station = RiderAttendanceQrStation::query()->sole();

        $this->assertSame(hash('sha256', $token), $station->token_hash);
        $this->assertNotSame($token, $station->token_hash);

        $checkIn = $this->scan($rider->user, $challenge)
            ->assertOk()
            ->assertJsonPath('data.scan_type', 'check_in');
        $shift = $rider->shifts()->sole();

        $this->assertSame(now()->toDateTimeString(), $shift->checked_in_at?->toDateTimeString());
        $this->assertSame('qr', $shift->check_in_source);
        $this->assertDatabaseHas('rider_attendance_scans', [
            'rider_profile_id' => $rider->id,
            'rider_shift_id' => $shift->id,
            'generation' => $station->generation,
            'scan_type' => 'check_in',
            'scanned_at' => $shift->checked_in_at?->toDateTimeString(),
        ]);

        $this->scan($rider->user, $challenge)->assertStatus(409);
        $this->assertSame(1, $rider->shifts()->count());

        $this->travel(1)->hours();
        $checkoutChallenge = $this->issueChallenge($logisticsUser);
        $this->scan($rider->user, $checkoutChallenge)
            ->assertOk()
            ->assertJsonPath('data.scan_type', 'check_out');

        $shift->refresh();
        $this->assertSame(now()->toDateTimeString(), $shift->checked_out_at?->toDateTimeString());
        $this->assertSame('qr', $shift->check_out_source);
        $this->assertSame(2, RiderAttendanceScan::query()->where('rider_shift_id', $shift->id)->count());
        $this->assertCacheIsPrivateAndNoStore($checkIn->headers->get('Cache-Control'));
    }

    public function test_attendance_page_roster_is_provider_scoped_and_includes_active_approved_local_and_courier_riders(): void
    {
        [$logisticsUser] = $this->makeProvider('attendance-roster');
        [$foreignLogisticsUser] = $this->makeProvider('attendance-roster-foreign');
        $this->travelTo(Carbon::parse('2026-11-07 10:00:00', 'Asia/Manila'));

        $local = $this->makeRider($logisticsUser, 'attendance-roster-local');
        $local->shifts()->create([
            'checked_in_at' => Carbon::parse('2026-11-07 08:15:00', 'Asia/Manila')->utc(),
            'check_in_source' => 'qr',
        ]);
        $courier = $this->makeRider($logisticsUser, 'attendance-roster-courier');
        $courier->update(['work_type' => 'courier']);
        $legacyLocal = $this->makeRider($logisticsUser, 'attendance-roster-legacy');
        $legacyLocal->update(['work_type' => null]);

        $pending = $this->makeRider($logisticsUser, 'attendance-roster-pending');
        $pending->update(['verification_status' => 'pending']);
        $inactive = $this->makeRider($logisticsUser, 'attendance-roster-inactive');
        $inactive->user()->update(['status' => 'deactivated']);
        $foreign = $this->makeRider($foreignLogisticsUser, 'attendance-roster-foreign');

        $response = $this->actingAs($logisticsUser)
            ->get(route('logistics.rider-attendance.index'))
            ->assertOk()
            ->assertSee($local->user->name)
            ->assertSee($courier->user->name)
            ->assertSee($legacyLocal->user->name)
            ->assertSee('8:15 AM PHT')
            ->assertSee('Available at 11:59 PM PHT')
            ->assertDontSee($pending->user->name)
            ->assertDontSee($inactive->user->name)
            ->assertDontSee($foreign->user->name);

        $response->assertViewHas('attendance', function (array $attendance) use ($local, $courier, $legacyLocal): bool {
            $rows = collect($attendance['riders'])->keyBy('id');

            return $attendance['date'] === '2026-11-07'
                && $attendance['checked_in_count'] === 1
                && $attendance['absent_count'] === 0
                && $attendance['can_mark_absent'] === false
                && count($attendance['riders']) === 3
                && $rows->get($local->id)['status'] === 'checked_in'
                && $rows->get($courier->id)['work_type'] === 'Courier'
                && $rows->get($legacyLocal->id)['work_type'] === 'Local Rider';
        });
    }

    public function test_manual_absence_is_cutoff_gated_audited_and_kept_when_a_later_qr_checkin_arrives(): void
    {
        [$logisticsUser] = $this->makeProvider('attendance-absence');
        $rider = $this->makeRider($logisticsUser, 'attendance-absence');
        $absenceUrl = route('logistics.rider-attendance.absent', $rider->id);

        $this->travelTo(Carbon::parse('2026-11-07 23:58:59', 'Asia/Manila'));
        $this->actingAs($logisticsUser)
            ->post($absenceUrl)
            ->assertSessionHasErrors('attendance');
        $this->assertSame(0, RiderAttendanceAbsence::query()->count());

        $this->travelTo(Carbon::parse('2026-11-07 23:59:00', 'Asia/Manila'));
        $this->actingAs($logisticsUser)
            ->get(route('logistics.rider-attendance.index'))
            ->assertOk()
            ->assertSee('Mark absent')
            ->assertViewHas('attendance', fn (array $attendance): bool => $attendance['can_mark_absent']);

        $this->actingAs($logisticsUser)
            ->post($absenceUrl)
            ->assertRedirect(route('logistics.rider-attendance.index'))
            ->assertSessionHas('success');

        $absence = RiderAttendanceAbsence::query()->sole();
        $this->assertSame($logisticsUser->logisticsProfile->id, $absence->logistics_profile_id);
        $this->assertSame($rider->id, $absence->rider_profile_id);
        $this->assertSame($logisticsUser->id, $absence->marked_by_user_id);
        $this->assertSame('2026-11-07', $absence->work_date->toDateString());
        $this->assertSame('2026-11-07 23:59:00', $absence->marked_at->copy()->timezone('Asia/Manila')->toDateTimeString());

        $challenge = $this->issueChallenge($logisticsUser);
        $this->travel(1)->seconds();
        $this->scan($rider->user, $challenge)
            ->assertOk()
            ->assertJsonPath('data.scan_type', 'check_in');

        $response = $this->actingAs($logisticsUser)
            ->get(route('logistics.rider-attendance.index'))
            ->assertOk()
            ->assertSee('Absent')
            ->assertSee('Late check-in')
            ->assertSee('Marked by Logistics attendance-absence');

        $response->assertViewHas('attendance', function (array $attendance) use ($rider): bool {
            $row = collect($attendance['riders'])->firstWhere('id', $rider->id);

            return $row['status'] === 'absent'
                && $row['absence']['marked_by'] === 'Logistics attendance-absence'
                && $row['late_check_in_at']?->format('g:i A') === '11:59 PM';
        });
        $this->assertSame(1, RiderAttendanceAbsence::query()->count());
        $this->assertSame(1, RiderAttendanceScan::query()->where('rider_profile_id', $rider->id)->count());
    }

    public function test_manual_absence_cannot_be_used_for_a_checked_in_or_foreign_rider(): void
    {
        [$logisticsUser] = $this->makeProvider('attendance-absence-owner');
        [$foreignLogisticsUser] = $this->makeProvider('attendance-absence-foreign');
        $checkedIn = $this->makeRider($logisticsUser, 'attendance-already-checked-in');
        $foreign = $this->makeRider($foreignLogisticsUser, 'attendance-foreign-rider');
        $this->travelTo(Carbon::parse('2026-11-07 23:59:00', 'Asia/Manila'));
        $checkedIn->shifts()->create([
            'checked_in_at' => now(),
            'check_in_source' => 'qr',
        ]);

        $this->actingAs($logisticsUser)
            ->post(route('logistics.rider-attendance.absent', $checkedIn->id))
            ->assertSessionHasErrors('attendance');
        $this->actingAs($logisticsUser)
            ->post(route('logistics.rider-attendance.absent', $foreign->id))
            ->assertNotFound();

        $this->assertSame(0, RiderAttendanceAbsence::query()->count());
    }

    public function test_expired_replaced_and_cross_provider_qrs_cannot_create_attendance(): void
    {
        [$logisticsA] = $this->makeProvider('attendance-owner-a');
        [$logisticsB] = $this->makeProvider('attendance-owner-b');
        $rider = $this->makeRider($logisticsA, 'attendance-owner-a');

        $this->travelTo(Carbon::parse('2026-11-07 08:00:00', 'Asia/Manila'));
        $oldChallenge = $this->issueChallenge($logisticsA);
        $currentChallenge = $this->issueChallenge($logisticsA);
        $this->scan($rider->user, $oldChallenge)->assertStatus(409);

        $foreignChallenge = $this->issueChallenge($logisticsB);
        $this->scan($rider->user, $foreignChallenge)->assertForbidden();

        $this->travel(46)->seconds();
        $this->scan($rider->user, $currentChallenge)->assertStatus(409);
        $this->assertSame(0, $rider->shifts()->count());
        $this->assertSame(0, RiderAttendanceScan::query()->count());

        $this->scan($rider->user, 'not-a-bearly-qr')->assertUnprocessable();
        $this->assertSame(0, RiderShift::query()->count());
    }

    private function issueChallenge(User $logisticsUser): string
    {
        $response = $this->actingAs($logisticsUser)
            ->postJson(route('logistics.rider-attendance.challenge'))
            ->assertOk();
        $this->assertCacheIsPrivateAndNoStore($response->headers->get('Cache-Control'));

        return (string) $response->json('data.challenge');
    }

    private function scan(User $rider, string $challenge): TestResponse
    {
        return $this->actingAs($rider)->postJson(route('rider.attendance.scan'), [
            'qr_payload' => $challenge,
        ]);
    }

    private function assertCacheIsPrivateAndNoStore(?string $header): void
    {
        $this->assertNotNull($header);
        $this->assertStringContainsString('private', strtolower($header));
        $this->assertStringContainsString('no-store', strtolower($header));
    }

    /** @return array{0: User, 1: LogisticsProfile} */
    private function makeProvider(string $suffix): array
    {
        $user = User::factory()->create([
            'name' => "Logistics {$suffix}",
            'email' => "qr-logistics-{$suffix}@example.test",
            'role' => 'logistics',
            'status' => 'active',
        ]);
        $profile = LogisticsProfile::query()->create([
            'user_id' => $user->id,
            'legal_name' => "Logistics {$suffix} Inc.",
            'display_name' => "Logistics {$suffix}",
            'contact_phone' => '09170000001',
            'status' => 'active',
        ]);

        return [$user, $profile];
    }

    private function makeRider(User $logisticsUser, string $suffix): RiderProfile
    {
        $user = User::factory()->create([
            'name' => "Rider {$suffix}",
            'email' => "qr-rider-{$suffix}@example.test",
            'role' => 'rider',
            'status' => 'active',
            'logistics_id' => $logisticsUser->id,
        ]);

        return RiderProfile::query()->create([
            'user_id' => $user->id,
            'logistics_profile_id' => $logisticsUser->logisticsProfile->id,
            'vehicle_type' => 'Motorcycle',
            'plate_number' => 'QR-'.strtoupper(substr(hash('sha1', $suffix), 0, 10)),
            'work_type' => 'local',
            'availability_status' => 'offline',
            'verification_status' => 'approved',
        ]);
    }
}
