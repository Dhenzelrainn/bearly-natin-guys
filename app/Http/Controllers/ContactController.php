<?php

namespace App\Http\Controllers;

use App\Models\SupportTicket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ContactController extends Controller
{
    /**
     * Topics offered by the public contact form. The server-side whitelist
     * mirrors the form options so arbitrary taxonomy values cannot enter the
     * support queue.
     */
    private const TOPICS = [
        'Shopping & Orders',
        'Delivery & Tracking',
        'Seller Support',
        'Account & Registration',
        'Complaints & Disputes',
        'Technical Issue',
        'General Inquiry',
    ];

    private const ROLES = [
        'Buyer',
        'Seller',
        'Courier',
        'Logistics / Sorting Center',
        'Other',
    ];

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'email' => ['required', 'string', 'email', 'max:254'],
            'role' => ['nullable', 'string', Rule::in(self::ROLES)],
            'topic' => ['required', 'string', Rule::in(self::TOPICS)],
            'reference' => ['nullable', 'string', 'max:50'],
            'subject' => ['required', 'string', 'min:3', 'max:180'],
            'message' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        $ticket = SupportTicket::query()->create([
            'ticket_no' => 'TKT-'.Str::upper((string) Str::ulid()),
            'user_id' => $request->user()?->id,
            'name' => $validated['name'],
            'email' => $validated['email'],
            'role_label' => $validated['role'] ?? null,
            'topic' => $validated['topic'],
            'subject' => $validated['subject'],
            'message' => $validated['message'],
            'reference' => $validated['reference'] ?? null,
        ]);

        $message = "Thanks! Your message was received as ticket {$ticket->ticket_no}. Our team will follow up through {$ticket->email}.";

        if ($request->expectsJson()) {
            return response()->json([
                'data' => ['ticket_no' => $ticket->ticket_no],
                'message' => $message,
            ], 201);
        }

        return redirect()
            ->route('contact')
            ->with('contact_status', $message);
    }
}
