<?php

namespace Tests\Feature;

use App\Mail\ApplicationDecisionNotice;
use App\Mail\RegistrationCode;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SellerProfile;
use App\Models\Store;
use App\Models\User;
use App\Notifications\BearlyPasswordResetNotification;
use App\Notifications\SellerWithdrawalStatusNotification;
use App\Notifications\WorkflowNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class EmailBrandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_registered_workflow_event_renders_branded_html_and_plain_text(): void
    {
        foreach (config('notifications.events') as $event => $definition) {
            $recipient = new User;
            $recipient->role = $definition['audience'][0] ?? 'buyer';
            $notification = new WorkflowNotification(
                eventKey: $event,
                title: "{$event} update",
                body: 'This is the event-specific notification content.',
            );

            $mail = $notification->toMail($recipient);
            $html = (string) $mail->render();
            $text = view($mail->view['text'], $mail->viewData)->render();

            $this->assertSame('emails.notifications.workflow', $mail->view['html'], "HTML view missing for [{$event}].");
            $this->assertSame('emails.text.workflow', $mail->view['text'], "Plain-text view missing for [{$event}].");
            $this->assertStringContainsString('images/bearly-logo.png', $html, "Bearly logo missing for [{$event}].");
            $this->assertStringContainsString("{$event} update", $html, "Title missing for [{$event}].");
            $this->assertStringContainsString('event-specific notification content', $text, "Body missing for [{$event}].");
        }
    }

    public function test_announcement_email_has_a_distinct_branded_panel_and_read_action(): void
    {
        $recipient = new User;
        $recipient->role = 'buyer';
        $mail = (new WorkflowNotification(
            eventKey: 'announcement_published',
            title: 'A marketplace update',
            body: 'A new announcement is available for your account.',
            actionUrl: 'https://bearly.example.test/announcements/24',
        ))->toMail($recipient);
        $html = (string) $mail->render();

        $this->assertStringContainsString('A note from Bearly', $html);
        $this->assertStringContainsString('Read announcement', $html);
        $this->assertStringContainsString('https://bearly.example.test/announcements/24', $html);
        $this->assertStringContainsString('border-radius:12px', $html);
        $this->assertStringContainsString('max-width:640px', $html);
        $this->assertStringContainsString('images/bearly-logo.png', $html);
    }

    public function test_registration_code_email_renders_the_same_code_in_branded_html_and_text(): void
    {
        $expiresInMinutes = max(1, (int) ceil((int) config('email-verification.ttl', 300) / 60));
        $mail = (new RegistrationCode('123456'))->build();
        $html = (string) $mail->render();
        $text = view('emails.text.registration-code', [
            'code' => '123456',
            'expiresInMinutes' => $expiresInMinutes,
        ])->render();

        $this->assertStringContainsString('images/bearly-logo.png', $html);
        $this->assertStringContainsString('background-color:#35251e', $html);
        $this->assertStringContainsString('123456', $html);
        $this->assertStringContainsString('123456', $text);
        $this->assertStringContainsString("{$expiresInMinutes} minutes", $html);
        $this->assertStringContainsString("{$expiresInMinutes} minutes", $text);
    }

    public function test_application_decision_email_renders_decision_and_reason_in_both_formats(): void
    {
        $mail = (new ApplicationDecisionNotice(
            'Maria Santos',
            'seller',
            'needs_revision',
            'Upload a readable business permit.',
        ))->build();
        $html = (string) $mail->render();
        $text = view('emails.text.application-decision-notice', [
            'applicantName' => 'Maria Santos',
            'role' => 'seller',
            'decision' => 'needs_revision',
            'decisionLabel' => 'Needs Revision',
            'reason' => 'Upload a readable business permit.',
        ])->render();

        $this->assertStringContainsString('images/bearly-logo.png', $html);
        $this->assertStringContainsString('Needs Revision', $html);
        $this->assertStringContainsString('Upload a readable business permit.', $html);
        $this->assertStringContainsString('Needs Revision', $text);
        $this->assertStringContainsString('Upload a readable business permit.', $text);
    }

    public function test_password_reset_email_uses_the_framework_reset_url_and_expiry(): void
    {
        $user = new User;
        $user->email = 'buyer@example.test';
        $mail = (new BearlyPasswordResetNotification('reset-token'))->toMail($user);
        $html = (string) $mail->render();
        $text = view($mail->view['text'], $mail->viewData)->render();
        $expectedUrl = route('password.reset', [
            'token' => 'reset-token',
            'email' => 'buyer@example.test',
        ]);

        $this->assertSame('emails.password-reset', $mail->view['html']);
        $this->assertSame('emails.text.password-reset', $mail->view['text']);
        $this->assertStringContainsString('images/bearly-logo.png', $html);
        $this->assertStringContainsString($expectedUrl, $html);
        $this->assertStringContainsString($expectedUrl, $text);
        $this->assertStringContainsString('60 minutes', $html);
        $this->assertStringContainsString('60 minutes', $text);
    }

    public function test_forgot_password_flow_sends_the_branded_reset_notification(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'reset@example.test']);

        $this->from(route('password.request'))
            ->post(route('password.email'), ['email' => $user->email])
            ->assertRedirect(route('password.request'))
            ->assertSessionHas('status', 'If a Bearly account exists for that email, a password reset link has been sent.');

        Notification::assertSentTo(
            $user,
            BearlyPasswordResetNotification::class,
            fn (BearlyPasswordResetNotification $notification): bool => $notification->toMail($user)->view['html'] === 'emails.password-reset',
        );
    }

    public function test_withdrawal_email_formats_centavos_without_float_arithmetic(): void
    {
        $seller = new User;
        $seller->role = 'seller';
        $mail = (new SellerWithdrawalStatusNotification(9, 'WD-9', 15_025, 'completed'))->toMail($seller);
        $html = (string) $mail->render();
        $text = view($mail->view['text'], $mail->viewData)->render();

        $this->assertStringContainsString('₱150.25', $html);
        $this->assertStringContainsString('₱150.25', $text);
    }

    public function test_seller_order_email_only_shows_that_sellers_items_and_total(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer']);
        $seller = User::factory()->create(['role' => 'seller']);
        $otherSeller = User::factory()->create(['role' => 'seller']);
        $sellerStore = $this->storeFor($seller, 'seller-one');
        $otherStore = $this->storeFor($otherSeller, 'seller-two');

        $order = Order::query()->create([
            'order_no' => 'ORD-EMAIL-001',
            'buyer_id' => $buyer->id,
            'recipient_name' => 'Buyer Name',
            'recipient_phone' => '09170000000',
            'address_line' => '1 Test Street',
            'barangay' => 'Test Barangay',
            'city_municipality' => 'San Pablo City',
            'province' => 'Laguna',
            'subtotal_minor' => 90_122,
            'total_minor' => 90_122,
        ]);
        $sellerOrder = $order->sellerOrders()->create([
            'seller_order_no' => 'SO-EMAIL-001',
            'store_id' => $sellerStore->id,
            'subtotal_minor' => 12_345,
            'total_minor' => 12_345,
        ]);
        $otherSellerOrder = $order->sellerOrders()->create([
            'seller_order_no' => 'SO-EMAIL-002',
            'store_id' => $otherStore->id,
            'subtotal_minor' => 77_777,
            'total_minor' => 77_777,
        ]);

        foreach ([
            [$sellerOrder, 'Seller product', 12_345],
            [$otherSellerOrder, 'Other seller product', 77_777],
        ] as [$ownedOrder, $productName, $priceMinor]) {
            OrderItem::query()->create([
                'seller_order_id' => $ownedOrder->id,
                'product_name' => $productName,
                'variant_name' => 'Default',
                'sku' => 'SKU-'.$ownedOrder->id,
                'unit_price_minor' => $priceMinor,
                'quantity' => 1,
                'subtotal_minor' => $priceMinor,
            ]);
        }

        $mail = (new WorkflowNotification(
            eventKey: 'seller_order_updates',
            title: 'Order received',
            body: 'A marketplace order was placed.',
            meta: ['order_id' => $order->id],
        ))->toMail($seller);
        $html = (string) $mail->render();

        $this->assertStringContainsString('SO-EMAIL-001', $html);
        $this->assertStringContainsString('Seller product', $html);
        $this->assertStringContainsString('Seller order total', $html);
        $this->assertStringContainsString('₱123.45', $html);
        $this->assertStringNotContainsString('Other seller product', $html);
        $this->assertStringNotContainsString('₱901.22', $html);
        $this->assertStringNotContainsString('₱777.77', $html);
    }

    private function storeFor(User $seller, string $slug): Store
    {
        $profile = SellerProfile::query()->create([
            'user_id' => $seller->id,
            'legal_business_name' => 'Seller '.$slug,
        ]);

        return Store::query()->create([
            'seller_profile_id' => $profile->id,
            'name' => 'Store '.$slug,
            'slug' => $slug,
        ]);
    }
}
