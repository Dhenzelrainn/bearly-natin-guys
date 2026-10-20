<?php

namespace Tests\Feature;

use App\Events\UserNotificationCreated;
use App\Jobs\SendBearlyNotificationEmail;
use App\Models\User;
use App\Models\UserNotificationPreference;
use App\Notifications\AccountStatusChangedNotification;
use App\Notifications\SellerWithdrawalStatusNotification;
use App\Notifications\WorkflowNotification;
use App\Services\NotificationCategoryService;
use App\Services\NotificationPreferenceService;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class NotificationCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_history_lists_only_the_authenticated_users_notifications_with_unread_count(): void
    {
        $seller = $this->seller();
        $other = $this->seller();

        $seller->notify(new AccountStatusChangedNotification('pending', 'active', 'Approved.'));
        $seller->notify(new SellerWithdrawalStatusNotification(7, 'WD-7', 25_000, 'approved'));
        $other->notify(new AccountStatusChangedNotification('active', 'suspended', 'Policy.'));

        $response = $this->actingAs($seller)->getJson(route('notifications.index'));

        $response->assertOk()
            ->assertJsonPath('data.unread_count', 2)
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.next_page', null);

        $ids = collect($response->json('data.items'))->pluck('id');
        $otherId = (string) $other->notifications()->first()->id;

        $this->assertFalse($ids->contains($otherId));
        $this->assertFalse($ids->contains((string) Str::uuid()));
    }

    public function test_history_paginates_and_flags_read_items(): void
    {
        $buyer = $this->buyer();

        foreach (range(1, 3) as $index) {
            $buyer->notify(new AccountStatusChangedNotification('pending', 'active', "Reason {$index}"));
        }

        // Mark the most recent notification read; the two older ones stay unread.
        $buyer->notifications()->latest()->first()->markAsRead();

        $response = $this->actingAs($buyer)->getJson(route('notifications.index', ['per_page' => 2]));

        $response->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.next_page', 2)
            ->assertJsonPath('data.unread_count', 2);

        $this->assertTrue($response->json('data.items.0.read'));
        $this->assertFalse($response->json('data.items.1.read'));
    }

    public function test_marking_one_notification_read_is_scoped_and_updates_unread_count(): void
    {
        $buyer = $this->buyer();
        $other = $this->buyer();

        $buyer->notify(new AccountStatusChangedNotification('pending', 'active', 'Approved.'));
        $other->notify(new AccountStatusChangedNotification('pending', 'active', 'Approved.'));

        $own = $buyer->notifications()->first();
        $foreign = $other->notifications()->first();

        $this->actingAs($buyer)
            ->postJson(route('notifications.read', $own->id))
            ->assertOk()
            ->assertJsonPath('data.unread_count', 0);

        $this->assertNotNull($own->fresh()->read_at);

        $this->actingAs($buyer)
            ->postJson(route('notifications.read', $foreign->id))
            ->assertNotFound();

        $this->assertNull($foreign->fresh()->read_at);

        $this->actingAs($buyer)
            ->postJson(route('notifications.read', (string) Str::uuid()))
            ->assertNotFound();
    }

    public function test_mark_all_read_only_affects_the_authenticated_user(): void
    {
        $buyer = $this->buyer();
        $other = $this->buyer();

        $buyer->notify(new AccountStatusChangedNotification('pending', 'active', 'One.'));
        $buyer->notify(new AccountStatusChangedNotification('pending', 'active', 'Two.'));
        $other->notify(new AccountStatusChangedNotification('pending', 'active', 'Other.'));

        $this->actingAs($buyer)
            ->postJson(route('notifications.read-all'))
            ->assertOk()
            ->assertJsonPath('data.unread_count', 0);

        $this->assertSame(0, $buyer->unreadNotifications()->count());
        $this->assertSame(1, $other->unreadNotifications()->count());
    }

    public function test_preferences_expose_role_events_with_defaults_and_mandatory_flags(): void
    {
        $seller = $this->seller();

        $events = collect($this->actingAs($seller)
            ->getJson(route('notifications.preferences'))
            ->assertOk()
            ->json('data.events'))
            ->keyBy('key');

        $this->assertTrue($events->has('account_status_changed'));
        $this->assertTrue($events->has('seller_withdrawal_status_changed'));
        $this->assertTrue($events['account_status_changed']['mandatory']);
        $this->assertTrue($events['account_status_changed']['in_app']);
        $this->assertTrue($events['seller_withdrawal_status_changed']['in_app']);
        $this->assertTrue($events['seller_withdrawal_status_changed']['email']);
        $this->assertTrue($events['seller_order_updates']['email']);
        $this->assertTrue($events['seller_buyer_message']['email']);

        $buyer = $this->buyer();
        $buyerEvents = collect($this->actingAs($buyer)
            ->getJson(route('notifications.preferences'))
            ->json('data.events'))
            ->keyBy('key');

        $this->assertTrue($buyerEvents->has('account_status_changed'));
        $this->assertFalse($buyerEvents->has('seller_withdrawal_status_changed'));
        $this->assertTrue($buyerEvents['buyer_shipment_updates']['email']);
        $this->assertTrue($buyerEvents['buyer_message_received']['email']);
    }

    public function test_every_role_receives_registry_events_on_their_declared_default_channels(): void
    {
        $preferences = app(NotificationPreferenceService::class);

        $users = [
            'buyer' => $this->buyer(),
            'seller' => $this->seller(),
            'admin' => User::factory()->create(['role' => 'admin', 'status' => 'active']),
            'logistics' => User::factory()->create(['role' => 'logistics', 'status' => 'active']),
            'rider' => User::factory()->create(['role' => 'rider', 'status' => 'active']),
        ];

        foreach (config('notifications.events') as $key => $definition) {
            foreach ((array) $definition['audience'] as $role) {
                $channels = $preferences->channelsFor($users[$role], $key);

                $expectedInApp = (bool) ($definition['mandatory'] ?? false)
                    || (bool) ($definition['defaults']['in_app'] ?? true);
                $expectedEmail = (bool) ($definition['defaults']['email'] ?? false);

                $this->assertSame(
                    $expectedInApp,
                    $channels['in_app'],
                    "[{$role}] accounts must follow the declared in-app default for [{$key}].",
                );
                $this->assertSame(
                    $expectedEmail,
                    $channels['email'],
                    "[{$role}] accounts must follow the declared email default for [{$key}].",
                );
            }
        }
    }

    public function test_platform_announcements_default_to_in_app_without_broadcast_email(): void
    {
        foreach (['buyer', 'seller', 'logistics', 'rider'] as $role) {
            $user = User::factory()->create([
                'role' => $role,
                'status' => 'active',
            ]);

            $channels = app(NotificationPreferenceService::class)
                ->channelsFor($user, 'announcement_published');

            $this->assertTrue($channels['in_app']);
            $this->assertFalse($channels['email']);
        }
    }

    public function test_saving_preferences_persists_rows_and_mandatory_events_stay_in_app(): void
    {
        $seller = $this->seller();

        $payload = [
            'preferences' => [
                'seller_withdrawal_status_changed' => ['in_app' => false, 'email' => true],
                'account_status_changed' => ['in_app' => false, 'email' => true],
                'announcement_published' => ['in_app' => false, 'email' => false],
            ],
        ];

        $this->actingAs($seller)
            ->postJson(route('notifications.preferences.update'), $payload)
            ->assertOk();

        $this->assertDatabaseHas('user_notification_preferences', [
            'user_id' => $seller->id,
            'event_key' => 'seller_withdrawal_status_changed',
            'in_app' => 0,
            'email' => 1,
        ]);

        $this->assertDatabaseHas('user_notification_preferences', [
            'user_id' => $seller->id,
            'event_key' => 'account_status_changed',
            'in_app' => 1,
            'email' => 1,
        ]);

        $this->assertDatabaseHas('user_notification_preferences', [
            'user_id' => $seller->id,
            'event_key' => 'announcement_published',
            'in_app' => 1,
            'email' => 0,
        ]);

        // Saving again updates the same rows instead of duplicating them.
        $this->actingAs($seller)
            ->postJson(route('notifications.preferences.update'), $payload)
            ->assertOk();

        $this->assertSame(3, UserNotificationPreference::query()->where('user_id', $seller->id)->count());
    }

    public function test_preferences_reject_unknown_and_cross_role_events(): void
    {
        $buyer = $this->buyer();

        $this->actingAs($buyer)
            ->postJson(route('notifications.preferences.update'), [
                'preferences' => ['seller_withdrawal_status_changed' => ['in_app' => true, 'email' => false]],
            ])
            ->assertStatus(422);

        $this->actingAs($buyer)
            ->postJson(route('notifications.preferences.update'), [
                'preferences' => ['not_a_real_event' => ['in_app' => true, 'email' => false]],
            ])
            ->assertStatus(422);

        $this->assertSame(0, UserNotificationPreference::query()->count());
    }

    public function test_delivery_channels_follow_stored_preferences(): void
    {
        $seller = $this->seller();

        Notification::fake();

        $seller->notify(new SellerWithdrawalStatusNotification(1, 'WD-1', 10_000, 'approved'));

        Notification::assertSentTo(
            $seller,
            SellerWithdrawalStatusNotification::class,
            fn ($notification, $channels) => $channels === ['database', 'mail'],
        );

        Notification::fake();

        UserNotificationPreference::create([
            'user_id' => $seller->id,
            'event_key' => 'seller_withdrawal_status_changed',
            'in_app' => false,
            'email' => true,
        ]);

        $seller->notify(new SellerWithdrawalStatusNotification(1, 'WD-1', 10_000, 'approved'));

        Notification::assertSentTo(
            $seller,
            SellerWithdrawalStatusNotification::class,
            fn ($notification, $channels) => $channels === ['mail'],
        );

        Notification::fake();

        UserNotificationPreference::query()
            ->where('user_id', $seller->id)
            ->update(['email' => false]);

        $seller->notify(new SellerWithdrawalStatusNotification(1, 'WD-1', 10_000, 'approved'));

        Notification::assertNothingSent();
    }

    public function test_mandatory_events_always_keep_the_in_app_channel(): void
    {
        $buyer = $this->buyer();

        Notification::fake();

        UserNotificationPreference::create([
            'user_id' => $buyer->id,
            'event_key' => 'account_status_changed',
            'in_app' => false,
            'email' => true,
        ]);

        $buyer->notify(new AccountStatusChangedNotification('active', 'suspended', 'Policy.'));

        Notification::assertSentTo(
            $buyer,
            AccountStatusChangedNotification::class,
            fn ($notification, $channels) => $channels === ['database', 'mail'],
        );
    }

    public function test_real_delivery_persists_a_database_row_and_respects_disabled_channels(): void
    {
        $seller = $this->seller();

        $seller->notify(new SellerWithdrawalStatusNotification(9, 'WD-9', 15_000, 'completed'));

        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => User::class,
            'notifiable_id' => $seller->id,
        ]);
        $this->assertSame(1, $seller->notifications()->count());
        $this->assertSame(1, $seller->unreadNotifications()->count());

        UserNotificationPreference::create([
            'user_id' => $seller->id,
            'event_key' => 'seller_withdrawal_status_changed',
            'in_app' => false,
            'email' => false,
        ]);

        $seller->notify(new SellerWithdrawalStatusNotification(9, 'WD-9', 15_000, 'completed'));

        $this->assertSame(1, $seller->notifications()->count());
    }

    public function test_database_notification_and_realtime_broadcast_are_delivered_immediately(): void
    {
        $seller = $this->seller();

        Queue::fake();
        Event::fake([UserNotificationCreated::class]);

        app(NotificationService::class)->send(
            $seller,
            new SellerWithdrawalStatusNotification(9, 'WD-9', 15_000, 'completed'),
        );

        $this->assertSame(1, $seller->notifications()->count());
        $this->assertSame(1, $seller->unreadNotifications()->count());

        Event::assertDispatched(
            UserNotificationCreated::class,
            fn (UserNotificationCreated $event): bool => $event->userId === $seller->id
                && $event->event === 'seller_withdrawal_status_changed',
        );

        Queue::assertPushed(SendBearlyNotificationEmail::class, 1);
    }

    public function test_email_channel_is_queued_instead_of_sent_synchronously(): void
    {
        $seller = $this->seller();

        Queue::fake();
        Mail::fake();

        app(NotificationService::class)->send(
            $seller,
            new SellerWithdrawalStatusNotification(4, 'WD-4', 5_000, 'approved'),
        );

        Queue::assertPushed(
            SendBearlyNotificationEmail::class,
            fn (SendBearlyNotificationEmail $job): bool => $job->userId === $seller->id,
        );

        Mail::assertNothingSent();
    }

    public function test_disabled_email_preference_does_not_queue_the_email_job(): void
    {
        $seller = $this->seller();

        UserNotificationPreference::create([
            'user_id' => $seller->id,
            'event_key' => 'seller_withdrawal_status_changed',
            'in_app' => true,
            'email' => false,
        ]);

        Queue::fake();

        app(NotificationService::class)->send(
            $seller,
            new SellerWithdrawalStatusNotification(5, 'WD-5', 6_000, 'approved'),
        );

        Queue::assertNotPushed(SendBearlyNotificationEmail::class);
        $this->assertSame(1, $seller->notifications()->count());
    }

    public function test_email_is_still_queued_when_in_app_delivery_is_disabled(): void
    {
        $seller = $this->seller();

        UserNotificationPreference::create([
            'user_id' => $seller->id,
            'event_key' => 'seller_withdrawal_status_changed',
            'in_app' => false,
            'email' => true,
        ]);

        Queue::fake();
        Event::fake([UserNotificationCreated::class]);

        app(NotificationService::class)->send(
            $seller,
            new SellerWithdrawalStatusNotification(6, 'WD-6', 7_000, 'processing'),
        );

        Queue::assertPushed(SendBearlyNotificationEmail::class, 1);
        $this->assertSame(0, $seller->notifications()->count());
        Event::assertNotDispatched(UserNotificationCreated::class);
    }

    public function test_handling_the_queued_email_job_does_not_duplicate_the_database_notification(): void
    {
        $seller = $this->seller();
        $notification = new SellerWithdrawalStatusNotification(7, 'WD-7', 8_000, 'approved');

        Queue::fake();

        app(NotificationService::class)->send($seller, $notification);

        $this->assertSame(1, $seller->notifications()->count());

        Mail::fake();

        (new SendBearlyNotificationEmail($seller->id, $notification))->handle();

        $this->assertSame(1, $seller->notifications()->count());
    }

    public function test_queued_email_transport_failure_is_logged_without_breaking_notification_delivery(): void
    {
        $seller = $this->seller();
        $notification = new SellerWithdrawalStatusNotification(3, 'WD-3', 12_000, 'processing');

        Mail::shouldReceive('mailer')->andThrow(new RuntimeException('SMTP transport unavailable.'));
        Log::spy();

        (new SendBearlyNotificationEmail($seller->id, $notification))->handle();

        // The mail-only job never writes an in-app row.
        $this->assertSame(0, $seller->notifications()->count());

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message): bool => str_contains($message, 'Bearly notification delivery failed'))
            ->once();
    }

    public function test_mail_channel_message_contains_title_body_and_action_link(): void
    {
        $seller = $this->seller();

        $mail = (new SellerWithdrawalStatusNotification(9, 'WD-9', 15_000, 'completed'))->toMail($seller);

        $this->assertSame('Withdrawal completed', $mail->subject);
        $this->assertContains(
            'Withdrawal WD-9 is complete. Finance verified the bank transfer.',
            $mail->introLines,
        );
        $this->assertSame(route('seller.finance.wallet'), $mail->actionUrl);
    }

    public function test_history_filters_by_role_category_and_keeps_account_events_visible(): void
    {
        $buyer = $this->buyer();

        $buyer->notify(new WorkflowNotification(
            eventKey: 'buyer_order_updates',
            title: 'Order confirmed',
            body: 'Your order was confirmed.',
            actionUrl: url('/orders/1'),
        ));
        $buyer->notify(new WorkflowNotification(
            eventKey: 'buyer_shipment_updates',
            title: 'Shipment in transit',
            body: 'Your parcel left the sorting center.',
        ));
        $buyer->notify(new AccountStatusChangedNotification('pending', 'active', 'Approved.'));

        $this->actingAs($buyer)
            ->getJson(route('notifications.index', ['category' => 'orders']))
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.event', 'buyer_order_updates')
            ->assertJsonPath('data.items.0.category', 'orders')
            ->assertJsonPath('data.total', 1);

        $this->actingAs($buyer)
            ->getJson(route('notifications.index', ['category' => 'shipments']))
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.event', 'buyer_shipment_updates')
            ->assertJsonPath('data.items.0.category', 'shipments');

        // Account and security alerts stay visible in All with no owned tab.
        $all = $this->actingAs($buyer)
            ->getJson(route('notifications.index'))
            ->assertOk()
            ->assertJsonCount(3, 'data.items');

        $account = collect($all->json('data.items'))->firstWhere('event', 'account_status_changed');

        $this->assertNotNull($account);
        $this->assertNull($account['category']);

        // Unread covers every category, including account alerts.
        $buyer->notifications()->where('data->event', 'buyer_order_updates')->first()->markAsRead();

        $unread = $this->actingAs($buyer)
            ->getJson(route('notifications.index', ['category' => 'unread']))
            ->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.unread_count', 2);

        $this->assertFalse(collect($unread->json('data.items'))->contains('event', 'buyer_order_updates'));
    }

    public function test_history_search_matches_title_and_body_within_the_authenticated_users_rows(): void
    {
        $buyer = $this->buyer();
        $other = $this->buyer();

        $buyer->notify(new WorkflowNotification(
            eventKey: 'buyer_order_updates',
            title: 'Order confirmed',
            body: 'Seller packed the sandals order.',
        ));
        $buyer->notify(new WorkflowNotification(
            eventKey: 'buyer_shipment_updates',
            title: 'Parcel arrived at hub',
            body: 'Scan recorded at the Manila sorting center.',
        ));
        $other->notify(new WorkflowNotification(
            eventKey: 'buyer_order_updates',
            title: 'Order confirmed',
            body: 'Seller packed the sandals order.',
        ));

        // Body text matches and the other user's identical row never leaks in.
        $this->actingAs($buyer)
            ->getJson(route('notifications.index', ['search' => 'sandals']))
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.event', 'buyer_order_updates')
            ->assertJsonPath('data.total', 1);

        $this->actingAs($buyer)
            ->getJson(route('notifications.index', ['search' => 'parcel']))
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.event', 'buyer_shipment_updates');

        $this->actingAs($buyer)
            ->getJson(route('notifications.index', ['search' => 'no such history']))
            ->assertOk()
            ->assertJsonCount(0, 'data.items')
            ->assertJsonPath('data.total', 0)
            ->assertJsonPath('data.next_page', null);

        // Search combines with the role category filter.
        $this->actingAs($buyer)
            ->getJson(route('notifications.index', ['search' => 'sandals', 'category' => 'shipments']))
            ->assertOk()
            ->assertJsonCount(0, 'data.items');
    }

    public function test_history_pagination_exposes_metadata_beyond_the_first_page(): void
    {
        $buyer = $this->buyer();

        foreach (range(1, 25) as $index) {
            $buyer->notify(new WorkflowNotification(
                eventKey: 'buyer_order_updates',
                title: "Order update {$index}",
                body: "Update number {$index}.",
            ));
        }

        $this->actingAs($buyer)
            ->getJson(route('notifications.index', ['per_page' => 10, 'page' => 2]))
            ->assertOk()
            ->assertJsonCount(10, 'data.items')
            ->assertJsonPath('data.current_page', 2)
            ->assertJsonPath('data.last_page', 3)
            ->assertJsonPath('data.total', 25)
            ->assertJsonPath('data.next_page', 3);

        $this->actingAs($buyer)
            ->getJson(route('notifications.index', ['per_page' => 10, 'page' => 3]))
            ->assertOk()
            ->assertJsonCount(5, 'data.items')
            ->assertJsonPath('data.current_page', 3)
            ->assertJsonPath('data.next_page', null);

        // Older popover callers only send per_page and keep the previous shape.
        $legacy = $this->actingAs($buyer)
            ->getJson(route('notifications.index', ['per_page' => 5]))
            ->assertOk()
            ->assertJsonCount(5, 'data.items')
            ->assertJsonPath('data.unread_count', 25);

        $this->assertArrayHasKey('items', $legacy->json('data'));
        $this->assertArrayHasKey('next_page', $legacy->json('data'));
    }

    public function test_notification_center_page_renders_role_specific_categories(): void
    {
        $this->get(route('notifications.center'))->assertRedirect('/login');

        $buyer = $this->buyer();

        $this->actingAs($buyer)
            ->get(route('notifications.center'))
            ->assertOk()
            ->assertSee('data-notification-center-page', false)
            ->assertSee('data-skin="buyer"', false)
            ->assertSee('data-nc-category="orders"', false)
            ->assertSee('Order updates')
            ->assertSee('Returns & refunds')
            ->assertSee('data-nc-category="announcements"', false);

        $seller = $this->seller();

        $this->actingAs($seller)
            ->get(route('notifications.center'))
            ->assertOk()
            ->assertSee('data-skin="seller"', false)
            ->assertSee('data-nc-category="cancellations"', false)
            ->assertSee('Return & refund requests')
            ->assertSee('Finance & withdrawals')
            ->assertSee(route('seller.settings.notifications'), false);

        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        $this->actingAs($admin)
            ->get(route('notifications.center'))
            ->assertOk()
            ->assertSee('data-skin="admin"', false)
            ->assertSee('data-nc-category="registrations"', false)
            ->assertSee('Support messages')
            ->assertSee(route('admin.announcements'), false)
            ->assertDontSee('data-nc-category="announcements"', false);

        $logistics = User::factory()->create(['role' => 'logistics', 'status' => 'active']);

        $this->actingAs($logistics)
            ->get(route('notifications.center'))
            ->assertOk()
            ->assertSee('data-skin="logistics"', false)
            ->assertSee('data-nc-category="pickups"', false)
            ->assertSee('COD & refund cash')
            ->assertSee('Rider applications');

        $rider = User::factory()->create(['role' => 'rider', 'status' => 'active']);

        $this->actingAs($rider)
            ->get(route('notifications.center'))
            ->assertOk()
            ->assertSee('data-skin="rider"', false)
            ->assertSee('Pickup assignments')
            ->assertSee('Delivery assignments')
            ->assertSee('Refund cash assignments')
            // Riders intentionally have no Messages category.
            ->assertDontSee('data-nc-category="messages"', false);
    }

    public function test_notification_center_page_requires_an_active_role(): void
    {
        $suspended = User::factory()->create(['role' => 'seller', 'status' => 'suspended']);

        $this->actingAs($suspended)
            ->get(route('notifications.center'))
            ->assertForbidden();
    }

    public function test_center_categories_map_to_registered_events_for_each_role(): void
    {
        $categories = app(NotificationCategoryService::class);
        $registry = config('notifications.events');

        foreach (['buyer', 'seller', 'admin', 'logistics', 'rider'] as $role) {
            $tabs = $categories->tabsFor($role);

            $this->assertSame(['all', 'unread'], array_column(array_slice($tabs, 0, 2), 'key'));

            foreach (array_slice($tabs, 2) as $tab) {
                $events = $categories->eventsFor($role, $tab['key']);

                $this->assertNotEmpty($events, "[{$role}:{$tab['key']}] must map to at least one event key.");

                foreach ($events as $event) {
                    $this->assertArrayHasKey($event, $registry, "[{$role}:{$tab['key']}] references unregistered event [{$event}].");
                    $this->assertContains($role, (array) $registry[$event]['audience'], "[{$event}] must declare the {$role} audience.");
                    $this->assertSame($tab['key'], $categories->categoryForEvent($role, $event));
                }
            }
        }

        // Reserved filters never constrain by event key.
        foreach (['all', 'unread', '', null] as $reserved) {
            $this->assertNull($categories->eventsFor('buyer', $reserved));
        }

        // Account and security alerts intentionally have no dedicated tab.
        $this->assertNull($categories->categoryForEvent('buyer', 'account_status_changed'));
        $this->assertNull($categories->categoryForEvent('seller', 'account_status_changed'));

        // Platform announcements map for every role that can receive them.
        foreach (['buyer', 'seller', 'logistics', 'rider'] as $role) {
            $this->assertSame('announcements', $categories->categoryForEvent($role, 'announcement_published'));
        }
    }

    private function seller(): User
    {
        return User::factory()->create(['role' => 'seller', 'status' => 'active']);
    }

    private function buyer(): User
    {
        return User::factory()->create(['role' => 'buyer', 'status' => 'active']);
    }
}
