<?php

namespace Tests\Feature;

use App\Models\Dispute;
use App\Models\Role;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactSupportTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Mia Santos',
            'email' => 'mia@example.test',
            'role' => 'Buyer',
            'topic' => 'Shopping & Orders',
            'reference' => 'BRLY123456',
            'subject' => 'Missing item in my order',
            'message' => 'My parcel arrived but one of the items from my order is missing.',
        ], $overrides);
    }

    private function adminFixture(): User
    {
        $admin = User::factory()->create([
            'name' => 'Admin User',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $adminRole = Role::query()->firstOrCreate(['name' => 'admin'], ['display_name' => 'Admin']);
        $admin->roles()->syncWithoutDetaching([$adminRole->id]);

        return $admin;
    }

    public function test_guest_message_is_saved_as_a_support_ticket(): void
    {
        $response = $this->postJson(route('contact.store'), $this->validPayload());

        $response->assertCreated();

        $ticketNo = $response->json('data.ticket_no');

        $this->assertIsString($ticketNo);
        $this->assertStringStartsWith('TKT-', $ticketNo);

        $ticket = SupportTicket::query()->firstOrFail();

        $this->assertSame($ticketNo, $ticket->ticket_no);
        $this->assertSame('Mia Santos', $ticket->name);
        $this->assertSame('mia@example.test', $ticket->email);
        $this->assertSame('Buyer', $ticket->role_label);
        $this->assertSame('Shopping & Orders', $ticket->topic);
        $this->assertSame('BRLY123456', $ticket->reference);
        $this->assertSame('Missing item in my order', $ticket->subject);
        $this->assertSame('open', $ticket->status);
        $this->assertSame('normal', $ticket->priority);
        $this->assertNull($ticket->user_id);
    }

    public function test_authenticated_sender_is_linked_to_the_ticket(): void
    {
        $user = User::factory()->create([
            'role' => 'buyer',
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->postJson(route('contact.store'), $this->validPayload())
            ->assertCreated();

        $this->assertSame(
            $user->id,
            SupportTicket::query()->firstOrFail()->user_id
        );
    }

    public function test_complaints_topic_remains_a_support_ticket_and_does_not_create_a_dispute(): void
    {
        $this->postJson(route('contact.store'), $this->validPayload([
            'topic' => 'Complaints & Disputes',
            'subject' => 'Complaint about an order',
        ]))->assertCreated();

        $this->assertSame('Complaints & Disputes', SupportTicket::query()->firstOrFail()->topic);
        $this->assertSame(0, Dispute::query()->count());
    }

    public function test_contact_submission_validates_required_fields(): void
    {
        $this->postJson(route('contact.store'), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'email', 'topic', 'subject', 'message']);

        $this->assertSame(0, SupportTicket::query()->count());
    }

    public function test_contact_submission_rejects_unknown_topic_and_bad_email(): void
    {
        $this->postJson(route('contact.store'), $this->validPayload([
            'email' => 'not-an-email',
            'topic' => 'Anything goes',
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'topic']);

        $this->assertSame(0, SupportTicket::query()->count());
    }

    public function test_contact_submissions_are_rate_limited(): void
    {
        foreach (range(1, 10) as $attempt) {
            $this->postJson(route('contact.store'), $this->validPayload([
                'subject' => 'Rate limit probe '.$attempt,
            ]))->assertCreated();
        }

        $this->postJson(route('contact.store'), $this->validPayload([
            'subject' => 'Rate limit probe 11',
        ]))->assertStatus(429);
    }

    public function test_admin_can_review_submitted_tickets(): void
    {
        $this->postJson(route('contact.store'), $this->validPayload())->assertCreated();

        $ticket = SupportTicket::query()->firstOrFail();

        $this->actingAs($this->adminFixture())
            ->get(route('admin.support-tickets'))
            ->assertOk()
            ->assertSee($ticket->ticket_no)
            ->assertSee('Missing item in my order')
            ->assertSee('mia@example.test');

        $this->actingAs($this->adminFixture())
            ->get(route('admin.support-tickets', ['status' => 'resolved']))
            ->assertOk()
            ->assertDontSee($ticket->ticket_no);
    }

    public function test_contact_page_renders_the_posting_form(): void
    {
        $this->get(route('contact'))
            ->assertOk()
            ->assertSee(route('contact.store'), false)
            ->assertSee('Your message is saved as a support ticket');
    }

    public function test_non_admin_cannot_open_the_support_queue(): void
    {
        $buyer = User::factory()->create([
            'role' => 'buyer',
            'status' => 'active',
        ]);

        $this->actingAs($buyer)
            ->get(route('admin.support-tickets'))
            ->assertForbidden();
    }
}
