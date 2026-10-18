<?php

namespace Tests\Feature\Admin;

use App\Models\Announcement;
use App\Models\Role;
use App\Models\User;
use App\Notifications\WorkflowNotification;
use App\Services\AnnouncementPublishingService;
use App\Services\NotificationPreferenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminAnnouncementDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'buyer', 'seller', 'logistics', 'rider'] as $role) {
            Role::query()->firstOrCreate(
                ['name' => $role],
                ['display_name' => ucfirst($role)],
            );
        }
    }

    public function test_published_announcement_reaches_only_active_selected_roles_and_links_to_full_message(): void
    {
        $admin = $this->user('admin', 'Bearly Admin');
        $buyer = $this->user('buyer', 'Announcement Buyer');
        $seller = $this->user('seller', 'Announcement Seller');
        $logistics = $this->user('logistics', 'Announcement Logistics');
        $rider = $this->user('rider', 'Announcement Rider');
        $inactiveBuyer = $this->user('buyer', 'Inactive Buyer', 'deactivated');

        $this->actingAs($admin)
            ->post(route('admin.announcements.store'), [
                'title' => 'Announcement Testing',
                'body' => 'The service desk will be unavailable this Sunday. Please plan shipments early.',
                'status' => 'published',
                'audience_roles' => ['buyer', 'seller'],
            ])
            ->assertRedirect();

        $announcement = Announcement::query()->firstOrFail();

        foreach ([$buyer, $seller] as $recipient) {
            $notification = $recipient->notifications()
                ->where('type', WorkflowNotification::class)
                ->firstOrFail();

            $this->assertSame('announcement_published', $notification->data['event']);
            $this->assertSame('Announcement Testing', $notification->data['title']);
            $this->assertSame(
                route('announcements.show', $announcement),
                $notification->data['url'],
            );
            $this->assertSame(
                (int) $announcement->id,
                (int) $notification->data['meta']['announcement_id'],
            );

            $this->actingAs($recipient)
                ->get(route('announcements.show', $announcement))
                ->assertOk()
                ->assertSee('Announcement Testing')
                ->assertSee('The service desk will be unavailable this Sunday. Please plan shipments early.')
                ->assertSee('Platform announcement');
        }

        $this->assertSame(0, $logistics->notifications()->count());
        $this->assertSame(0, $rider->notifications()->count());
        $this->assertSame(0, $inactiveBuyer->notifications()->count());

        $this->actingAs($logistics)
            ->get(route('announcements.show', $announcement))
            ->assertNotFound();

        $channels = app(NotificationPreferenceService::class)
            ->channelsFor($buyer, 'announcement_published');

        $this->assertTrue($channels['in_app']);
        $this->assertFalse($channels['email']);
    }

    public function test_announcement_detail_includes_only_older_visible_posts_for_the_signed_in_roles(): void
    {
        $admin = $this->user('admin', 'Bearly Admin');
        $buyer = $this->user('buyer', 'Announcement Buyer');
        $olderBody = str_repeat('This is the full text of an older announcement. ', 5)
            .'It should not be shortened in the scroll feed.';
        $current = $this->announcement($admin, ['buyer'], [
            'announcement_no' => 'ANN-CURRENT-001',
            'title' => 'Current announcement',
            'published_at' => now()->subDay(),
            'publish_at' => now()->subDay(),
        ]);
        $older = $this->announcement($admin, ['buyer'], [
            'announcement_no' => 'ANN-OLDER-001',
            'title' => 'Earlier buyer announcement',
            'body' => $olderBody,
            'published_at' => now()->subDays(3),
            'publish_at' => now()->subDays(3),
        ]);
        $olderMedia = $older->media()->create([
            'media_type' => 'image',
            'disk' => 'local',
            'file_path' => 'announcement-media/'.$older->id.'/older-update.png',
            'original_name' => 'older-update.png',
            'mime_type' => 'image/png',
            'size_bytes' => 120,
            'position' => 0,
        ]);
        $olderVideo = $older->media()->create([
            'media_type' => 'video',
            'disk' => 'local',
            'file_path' => 'announcement-media/'.$older->id.'/older-update.mp4',
            'original_name' => 'older-update.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => 120,
            'position' => 1,
        ]);
        $legacyOlder = $this->announcement($admin, ['buyer'], [
            'announcement_no' => 'ANN-OLDER-LEGACY',
            'title' => 'Earlier legacy announcement',
            'published_at' => null,
            'publish_at' => now()->subDays(5),
        ]);
        $this->announcement($admin, ['buyer'], [
            'announcement_no' => 'ANN-NEWER-001',
            'title' => 'Later buyer announcement',
            'published_at' => now()->subHours(3),
            'publish_at' => now()->subHours(3),
        ]);
        $this->announcement($admin, ['seller'], [
            'announcement_no' => 'ANN-OTHER-ROLE',
            'title' => 'Seller-only earlier announcement',
            'published_at' => now()->subDays(4),
            'publish_at' => now()->subDays(4),
        ]);
        $expired = $this->announcement($admin, ['buyer'], [
            'announcement_no' => 'ANN-EXPIRED-001',
            'title' => 'Expired earlier announcement',
            'published_at' => now()->subDays(6),
            'publish_at' => now()->subDays(6),
            'expires_at' => now()->subDay(),
        ]);

        $this->actingAs($buyer)
            ->get(route('announcements.show', $current))
            ->assertOk()
            ->assertSee('Previous announcements')
            ->assertSee('announcement-history-post', false)
            ->assertSee('announcement-history-media-grid', false)
            ->assertSee('Earlier buyer announcement')
            ->assertSee('Earlier legacy announcement')
            ->assertSee('It should not be shortened in the scroll feed.')
            ->assertSee('older-update.png')
            ->assertSee('older-update.mp4')
            ->assertSee(route('announcements.show', $older), false)
            ->assertSee(route('announcements.media', [$older, $olderMedia]), false)
            ->assertSee(route('announcements.media', [$older, $olderVideo]), false)
            ->assertSee(route('announcements.show', $legacyOlder), false)
            ->assertSee('Expired earlier announcement')
            ->assertSee(route('announcements.show', $expired), false)
            ->assertDontSee('Later buyer announcement')
            ->assertDontSee('Seller-only earlier announcement');
    }

    public function test_admin_audience_and_status_filters_are_labeled_filter_buttons(): void
    {
        $admin = $this->user('admin', 'Bearly Admin');

        $this->actingAs($admin)
            ->get(route('admin.announcements'))
            ->assertOk()
            ->assertSee('class="announcement-filter-trigger"', false)
            ->assertSee('Filter by audience')
            ->assertSee('Filter by status')
            ->assertSee('All audiences')
            ->assertSee('All statuses')
            ->assertSee('Apply filters');
    }

    public function test_all_users_audience_covers_buyer_seller_logistics_and_rider(): void
    {
        $admin = $this->user('admin', 'Bearly Admin');
        $recipients = [
            $this->user('buyer', 'Buyer Recipient'),
            $this->user('seller', 'Seller Recipient'),
            $this->user('logistics', 'Logistics Recipient'),
            $this->user('rider', 'Rider Recipient'),
        ];

        $this->actingAs($admin)
            ->post(route('admin.announcements.store'), [
                'title' => 'All roles notice',
                'body' => 'This announcement is for every active marketplace role.',
                'status' => 'published',
                'audience' => 'all',
            ])
            ->assertRedirect();

        foreach ($recipients as $recipient) {
            $this->assertSame(1, $recipient->notifications()
                ->where('type', WorkflowNotification::class)
                ->count());
        }
    }

    public function test_scheduled_publish_delivers_once_and_existing_published_records_are_backfilled_idempotently(): void
    {
        $admin = $this->user('admin', 'Bearly Admin');
        $buyer = $this->user('buyer', 'Scheduled Buyer');
        $announcement = $this->announcement($admin, ['buyer'], [
            'status' => 'scheduled',
            'publish_at' => now()->subMinute(),
        ]);

        $first = app(AnnouncementPublishingService::class)->process();
        $this->assertSame(1, $first['published']);
        $this->assertSame('published', $announcement->fresh()->status);
        $this->assertSame(1, $buyer->notifications()
            ->where('type', WorkflowNotification::class)
            ->count());

        $second = app(AnnouncementPublishingService::class)->process();
        $this->assertSame(0, $second['published']);
        $this->assertSame(1, $buyer->notifications()
            ->where('type', WorkflowNotification::class)
            ->count());

        $legacy = $this->announcement($admin, ['buyer'], [
            'announcement_no' => 'ANN-LEGACY-001',
            'title' => 'Existing published announcement',
            'status' => 'published',
            'publish_at' => now()->subDay(),
            'published_at' => now()->subDay(),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.announcements'))
            ->assertOk();

        $legacyNoticeCount = $buyer->notifications()
            ->where('type', WorkflowNotification::class)
            ->get()
            ->filter(fn ($notification): bool => (int) ($notification->data['meta']['announcement_id'] ?? 0) === (int) $legacy->id
            )
            ->count();

        $this->assertSame(1, $legacyNoticeCount);

        $this->actingAs($admin)
            ->get(route('admin.announcements'))
            ->assertOk();

        $legacyNoticeCount = $buyer->notifications()
            ->where('type', WorkflowNotification::class)
            ->get()
            ->filter(fn ($notification): bool => (int) ($notification->data['meta']['announcement_id'] ?? 0) === (int) $legacy->id
            )
            ->count();

        $this->assertSame(1, $legacyNoticeCount);
    }

    public function test_recipients_can_still_open_expired_announcements_with_their_media(): void
    {
        Storage::fake('local');

        $admin = $this->user('admin', 'Bearly Admin');
        $buyer = $this->user('buyer', 'Expired Reader');
        $seller = $this->user('seller', 'Expired Outsider');

        $expired = $this->announcement($admin, ['buyer'], [
            'announcement_no' => 'ANN-READABLE-EXPIRED',
            'title' => 'Older promo notice',
            'published_at' => now()->subDays(4),
            'publish_at' => now()->subDays(4),
            'expires_at' => now()->subDay(),
        ]);
        $media = $expired->media()->create([
            'media_type' => 'image',
            'disk' => 'local',
            'file_path' => 'announcement-media/'.$expired->id.'/older-promo.png',
            'original_name' => 'older-promo.png',
            'mime_type' => 'image/png',
            'size_bytes' => 120,
            'position' => 0,
        ]);
        Storage::disk('local')->put($media->file_path, 'image-bytes');

        $archived = $this->announcement($admin, ['buyer'], [
            'announcement_no' => 'ANN-MANUAL-ARCHIVE',
            'title' => 'Hand archived notice',
            'status' => 'archived',
            'published_at' => now()->subDays(4),
            'publish_at' => now()->subDays(4),
            'expires_at' => now()->subDay(),
        ]);

        $this->actingAs($buyer)
            ->get(route('announcements.show', $expired))
            ->assertOk()
            ->assertSee('Older promo notice')
            ->assertSee('announcement-media-grid', false);

        $this->actingAs($buyer)
            ->get(route('announcements.media', [$expired, $media]))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');

        $this->actingAs($seller)
            ->get(route('announcements.show', $expired))
            ->assertNotFound();

        $this->actingAs($seller)
            ->get(route('announcements.media', [$expired, $media]))
            ->assertNotFound();

        $this->actingAs($buyer)
            ->get(route('announcements.show', $archived))
            ->assertNotFound();
    }

    public function test_publishing_process_prunes_only_the_oldest_expired_posts_beyond_ten(): void
    {
        Storage::fake('local');

        $admin = $this->user('admin', 'Bearly Admin');
        $this->user('buyer', 'Retention Buyer');

        $oldest = $this->announcement($admin, ['buyer'], [
            'announcement_no' => 'ANN-RETENTION-OLDEST',
            'title' => 'Oldest expired announcement',
            'published_at' => now()->subDays(30),
            'publish_at' => now()->subDays(30),
            'expires_at' => now()->subDays(29),
        ]);
        $media = $oldest->media()->create([
            'media_type' => 'image',
            'disk' => 'local',
            'file_path' => 'announcement-media/'.$oldest->id.'/pruned.png',
            'original_name' => 'pruned.png',
            'mime_type' => 'image/png',
            'size_bytes' => 120,
            'position' => 0,
        ]);
        Storage::disk('local')->put($media->file_path, 'image-bytes');

        $secondOldest = $this->announcement($admin, ['buyer'], [
            'announcement_no' => 'ANN-RETENTION-SECOND',
            'title' => 'Second oldest expired announcement',
            'published_at' => now()->subDays(20),
            'publish_at' => now()->subDays(20),
            'expires_at' => now()->subDays(19),
        ]);

        foreach (range(1, 9) as $index) {
            $this->announcement($admin, ['buyer'], [
                'announcement_no' => 'ANN-RETAINED-'.$index,
                'title' => 'Retained announcement '.$index,
                'published_at' => now()->subDays(10 - $index),
                'publish_at' => now()->subDays(10 - $index),
            ]);
        }

        $result = app(AnnouncementPublishingService::class)->process();

        $this->assertSame(1, $result['removed']);
        $this->assertNull(Announcement::query()->find($oldest->id));
        $this->assertNotNull(Announcement::query()->find($secondOldest->id));
        $this->assertSame(0, $oldest->media()->count());
        Storage::disk('local')->assertMissing($media->file_path);
        $this->assertSame(10, Announcement::query()->where('status', 'published')->count());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'announcement.auto_deleted',
            'module' => 'announcements',
            'auditable_id' => $oldest->id,
        ]);

        $this->assertSame(0, app(AnnouncementPublishingService::class)->process()['removed']);
        $this->assertSame(10, Announcement::query()->where('status', 'published')->count());
    }

    public function test_publishing_process_never_prunes_unexpired_posts(): void
    {
        $admin = $this->user('admin', 'Bearly Admin');
        $this->user('buyer', 'Retention Buyer');

        foreach (range(1, 12) as $index) {
            $this->announcement($admin, ['buyer'], [
                'announcement_no' => 'ANN-UNEXPIRED-'.$index,
                'title' => 'Unexpired announcement '.$index,
                'published_at' => now()->subDays(20 - $index),
                'publish_at' => now()->subDays(20 - $index),
            ]);
        }

        $result = app(AnnouncementPublishingService::class)->process();

        $this->assertSame(0, $result['removed']);
        $this->assertSame(12, Announcement::query()->where('status', 'published')->count());
    }

    public function test_admin_schedule_times_are_entered_in_manila_and_stored_as_utc(): void
    {
        $admin = $this->user('admin', 'Bearly Admin');

        $publishAt = now('Asia/Manila')->addHours(3)->startOfMinute();
        $expiresAt = now('Asia/Manila')->addHours(6)->startOfMinute();

        $this->actingAs($admin)
            ->post(route('admin.announcements.store'), [
                'title' => 'Scheduled in Philippine time',
                'body' => 'Schedule times use Philippine wall-clock time.',
                'status' => 'scheduled',
                'publish_at' => $publishAt->format('Y-m-d\TH:i'),
                'expires_at' => $expiresAt->format('Y-m-d\TH:i'),
                'audience_roles' => ['buyer'],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $announcement = Announcement::query()->firstOrFail();

        $this->assertSame(
            $publishAt->copy()->utc()->format('Y-m-d H:i:s'),
            $announcement->publish_at->utc()->format('Y-m-d H:i:s'),
        );
        $this->assertSame(
            $expiresAt->copy()->utc()->format('Y-m-d H:i:s'),
            $announcement->expires_at->utc()->format('Y-m-d H:i:s'),
        );

        $this->actingAs($admin)
            ->get(route('admin.announcements'))
            ->assertOk()
            ->assertSee('value="'.$publishAt->format('Y-m-d\TH:i').'"', false)
            ->assertSee('value="'.$expiresAt->format('Y-m-d\TH:i').'"', false);
    }

    public function test_admin_expiration_time_already_passed_in_manila_time_is_rejected(): void
    {
        $admin = $this->user('admin', 'Bearly Admin');

        $this->actingAs($admin)
            ->post(route('admin.announcements.store'), [
                'title' => 'Past expiry',
                'body' => 'This expiry already passed in Philippine time.',
                'status' => 'published',
                'expires_at' => now('Asia/Manila')->subHour()->format('Y-m-d\TH:i'),
                'audience_roles' => ['buyer'],
            ])
            ->assertSessionHasErrors('expires_at');
    }

    /** @param array<string, mixed> $overrides
     * @param  list<string>  $audienceRoles
     */
    private function announcement(User $admin, array $audienceRoles, array $overrides = []): Announcement
    {
        $announcement = Announcement::query()->create(array_merge([
            'announcement_no' => 'ANN-TEST-'.fake()->unique()->numerify('#####'),
            'title' => 'Scheduled announcement',
            'body' => 'A scheduled notice for the marketplace.',
            'status' => 'published',
            'created_by' => $admin->id,
            'publish_at' => now()->subMinute(),
            'published_at' => now()->subMinute(),
        ], $overrides));

        $roleIds = Role::query()->whereIn('name', $audienceRoles)->pluck('id')->all();
        $announcement->roles()->sync($roleIds);

        return $announcement->load('roles');
    }

    private function user(string $role, string $name, string $status = 'active'): User
    {
        return User::factory()->create([
            'name' => $name,
            'role' => $role,
            'status' => $status,
        ]);
    }
}
