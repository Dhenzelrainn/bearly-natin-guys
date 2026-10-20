<?php

namespace Tests\Feature;

use App\Events\AnnouncementPublished;
use App\Events\ConversationMessageCreated;
use App\Models\Announcement;
use App\Models\Role;
use App\Models\User;
use App\Services\AnnouncementDeliveryService;
use App\Services\AnnouncementPublishingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

class RealtimeDeliveryTest extends TestCase
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

    public function test_announcement_published_event_targets_private_role_channels_with_a_safe_payload(): void
    {
        $admin = $this->user('admin');
        $announcement = $this->announcement($admin, ['buyer', 'seller']);

        Event::fake([AnnouncementPublished::class]);

        app(AnnouncementDeliveryService::class)->broadcastPublished($announcement);

        Event::assertDispatched(
            AnnouncementPublished::class,
            function (AnnouncementPublished $event) use ($announcement): bool {
                $channels = collect($event->broadcastOn())
                    ->map(fn ($channel): string => $channel->name)
                    ->values()
                    ->all();

                $payload = $event->broadcastWith();

                return $event->broadcastAs() === 'announcement.published'
                    && $channels === ['private-announcements.buyer', 'private-announcements.seller']
                    && $payload['id'] === (int) $announcement->id
                    && $payload['audience_roles'] === ['buyer', 'seller']
                    && $payload['url'] === route('announcements.show', $announcement)
                    && ! array_key_exists('body', $payload)
                    && $payload['excerpt'] !== '';
            },
        );
    }

    public function test_announcement_broadcast_failure_is_logged_and_does_not_fail_publication(): void
    {
        $admin = $this->user('admin');
        $buyer = $this->user('buyer');
        $announcement = $this->announcement($admin, ['buyer']);

        Log::spy();

        Event::listen(AnnouncementPublished::class, function (): void {
            throw new RuntimeException('Realtime transport unavailable.');
        });

        $delivery = app(AnnouncementDeliveryService::class);
        $delivery->broadcastPublished($announcement);
        $delivery->deliverMissing($announcement);

        Log::shouldHaveReceived('notice')
            ->withArgs(fn (string $message, array $context): bool => $message === 'Bearly realtime announcement broadcast failed.'
                && $context['announcement_id'] === (int) $announcement->id)
            ->once();

        $this->assertSame('published', $announcement->fresh()->status);
        $this->assertSame(1, $buyer->notifications()->count());
    }

    public function test_role_announcement_channels_authorize_only_active_matching_users(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
        ]);

        /*
         * Channel closures register on the default broadcaster during boot.
         * PHPUnit boots with the null broadcaster, so reload the same
         * production channel definitions after selecting the reverb driver.
         */
        require base_path('routes/channels.php');

        $buyer = $this->user('buyer');
        $seller = $this->user('seller');
        $suspendedBuyer = $this->user('buyer', status: 'suspended');

        $this->actingAs($buyer)
            ->postJson('/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => 'private-announcements.buyer',
            ])
            ->assertOk();

        $this->actingAs($seller)
            ->postJson('/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => 'private-announcements.buyer',
            ])
            ->assertForbidden();

        $this->actingAs($suspendedBuyer)
            ->postJson('/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => 'private-announcements.buyer',
            ])
            ->assertForbidden();

        $this->actingAs($buyer)
            ->postJson('/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => 'private-users.'.$buyer->id,
            ])
            ->assertOk();

        $this->actingAs($buyer)
            ->postJson('/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => 'private-users.'.$seller->id,
            ])
            ->assertForbidden();
    }

    public function test_announcement_feed_returns_only_visible_new_posts_and_honors_excluded_ids(): void
    {
        $admin = $this->user('admin');
        $buyer = $this->user('buyer');
        $seller = $this->user('seller');

        $first = $this->announcement($admin, ['buyer'], [
            'title' => 'First buyer notice',
            'published_at' => now()->subMinutes(3),
        ]);
        $second = $this->announcement($admin, ['buyer', 'seller'], [
            'title' => 'Second buyer notice',
            'published_at' => now()->subMinutes(2),
        ]);
        $this->announcement($admin, ['seller'], [
            'title' => 'Seller-only notice',
            'published_at' => now()->subMinute(),
        ]);
        $this->announcement($admin, ['buyer'], [
            'title' => 'Future buyer notice',
            'published_at' => now()->addHour(),
        ]);

        $this->actingAs($buyer)
            ->getJson(route('announcements.feed', [
                'after' => now()->subHour()->toIso8601String(),
                'exclude_ids' => [(int) $first->id],
            ]))
            ->assertOk()
            ->assertJsonCount(1, 'data.announcements')
            ->assertJsonPath('data.announcements.0.id', (int) $second->id)
            ->assertJsonPath('data.announcements.0.title', 'Second buyer notice');

        $this->actingAs($seller)
            ->getJson(route('announcements.feed', [
                'after' => now()->subHour()->toIso8601String(),
            ]))
            ->assertOk()
            ->assertJsonCount(2, 'data.announcements')
            ->assertJsonMissing(['title' => 'First buyer notice']);

        $this->actingAs($this->user('buyer', status: 'suspended'))
            ->getJson(route('announcements.feed'))
            ->assertForbidden();
    }

    public function test_announcement_page_and_admin_publication_wire_up_realtime_delivery(): void
    {
        $admin = $this->user('admin');
        $buyer = $this->user('buyer');

        Event::fake([AnnouncementPublished::class]);

        $this->actingAs($admin)
            ->post(route('admin.announcements.store'), [
                'title' => 'Realtime publication',
                'body' => 'This announcement should be available to realtime subscribers.',
                'status' => 'published',
                'audience_roles' => ['buyer'],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        Event::assertDispatched(
            AnnouncementPublished::class,
            fn (AnnouncementPublished $event): bool => $event->broadcastWith()['title'] === 'Realtime publication',
        );

        $announcement = Announcement::query()->where('title', 'Realtime publication')->firstOrFail();

        $this->actingAs($buyer)
            ->get(route('announcements.show', $announcement))
            ->assertOk()
            ->assertSee('window.bearlyRealtimeIdentity', false)
            ->assertSee('data-announcement-feed-url="'.route('announcements.feed').'"', false)
            ->assertSee('data-announcement-live', false)
            ->assertSee('data-announcement-history-list', false)
            ->assertSee('announcement-live', false);
    }

    public function test_scheduled_publication_broadcasts_after_the_status_transition(): void
    {
        $admin = $this->user('admin');
        $this->user('buyer');
        $announcement = $this->announcement($admin, ['buyer'], [
            'status' => 'scheduled',
            'published_at' => null,
            'publish_at' => now()->subMinute(),
        ]);

        Event::fake([AnnouncementPublished::class]);

        $result = app(AnnouncementPublishingService::class)->process();

        $this->assertSame(1, $result['published']);
        $this->assertSame('published', $announcement->fresh()->status);

        Event::assertDispatched(
            AnnouncementPublished::class,
            fn (AnnouncementPublished $event): bool => $event->broadcastWith()['id'] === (int) $announcement->id,
        );
    }

    public function test_chat_message_event_targets_only_each_recipients_private_user_channel(): void
    {
        $buyer = $this->user('buyer');
        $seller = $this->user('seller');

        $event = new ConversationMessageCreated(
            conversationId: 42,
            messageId: 9001,
            recipientIds: [$buyer->id, $seller->id],
        );

        $channels = collect($event->broadcastOn())
            ->map(fn ($channel): string => $channel->name)
            ->values()
            ->all();

        $this->assertSame([
            'private-users.'.$buyer->id,
            'private-users.'.$seller->id,
        ], $channels);
        $this->assertSame('message.created', $event->broadcastAs());
        $this->assertSame([
            'conversation_id' => 42,
            'message_id' => 9001,
        ], $event->broadcastWith());
    }

    /** @param list<string> $audienceRoles
     * @param  array<string, mixed>  $overrides
     */
    private function announcement(User $admin, array $audienceRoles, array $overrides = []): Announcement
    {
        $announcement = Announcement::query()->create(array_merge([
            'announcement_no' => 'ANN-RT-'.fake()->unique()->numerify('#####'),
            'title' => 'Realtime announcement',
            'body' => 'A realtime announcement for the selected marketplace roles.',
            'status' => 'published',
            'created_by' => $admin->id,
            'publish_at' => now()->subMinute(),
            'published_at' => now()->subMinute(),
        ], $overrides));

        $roleIds = Role::query()->whereIn('name', $audienceRoles)->pluck('id')->all();
        $announcement->roles()->sync($roleIds);

        return $announcement->load('roles');
    }

    private function user(string $role, string $status = 'active'): User
    {
        return User::factory()->create([
            'role' => $role,
            'status' => $status,
        ]);
    }
}
