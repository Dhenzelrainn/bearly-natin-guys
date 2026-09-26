<?php

namespace App\Http\Controllers;

use App\Models\Dispute;
use App\Models\DisputeEvent;
use App\Models\DisputeEvidence;
use App\Models\Conversation;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AdminDisputeController extends Controller
{
    public function __construct(
        private readonly AuditService $auditService
    ) {}

    public function saveNote(
        Request $request,
        Dispute $dispute
    ): JsonResponse {
        $validated = $request->validate([
            'note' => [
                'required',
                'string',
                'max:3000',
            ],
        ]);

        $event = DB::transaction(function () use (
            $request,
            $dispute,
            $validated
        ): DisputeEvent {
            $dispute = Dispute::query()
                ->whereKey($dispute->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (in_array(
                $dispute->status,
                ['resolved', 'closed'],
                true
            )) {
                throw ValidationException::withMessages([
                    'dispute' => 'A finalized dispute cannot receive new internal notes.',
                ]);
            }

            $event = DisputeEvent::create([
                'dispute_id' => $dispute->id,
                'event_type' => 'internal_note',
                'actor_user_id' => $request->user()->id,
                'from_status' => $dispute->status,
                'to_status' => $dispute->status,
                'note' => $validated['note'],
                'metadata' => [
                    'visibility' => 'admin_internal',
                ],
                'created_at' => now(),
            ]);

            $this->auditService->record(
                action: 'dispute.internal_note_added',
                module: 'Compliance & Disputes',
                subject: $dispute,
                description: "Internal note added to {$dispute->dispute_no}.",
                oldValues: [],
                newValues: [
                    'event_id' => $event->id,
                    'status' => $dispute->status,
                ],
                severity: 'info',
                request: $request,
            );

            return $event;
        });

        return response()->json([
            'message' => 'Internal note saved.',
            'event' => [
                'time' => $event->created_at?->format('g:i A'),
                'text' => $event->note,
            ],
        ]);
    }

    public function sendUpdate(
        Request $request,
        Dispute $dispute
    ): JsonResponse {
        $admin = $request->user();

        $validated = $request->validate([
            'message' => [
                'required',
                'string',
                'max:3000',
            ],
        ]);

        $result = DB::transaction(function () use (
            $request,
            $admin,
            $dispute,
            $validated
        ): array {
            $dispute = Dispute::query()
                ->whereKey($dispute->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (in_array(
                $dispute->status,
                ['resolved', 'closed'],
                true
            )) {
                throw ValidationException::withMessages([
                    'dispute' =>
                        'A finalized dispute cannot receive participant updates.',
                ]);
            }

            $participants = $dispute
                ->participants()
                ->where(
                    'users.status',
                    'active'
                )
                ->get();

            if ($participants->isEmpty()) {
                throw ValidationException::withMessages([
                    'participants' =>
                        'This dispute has no active participants to notify.',
                ]);
            }

            $sent = [];

            foreach ($participants as $participant) {
                $participantRole =
                    $participant->pivot->participant_role;

                $conversation =
                    Conversation::query()
                        ->where(
                            'dispute_id',
                            $dispute->id
                        )
                        ->where('status', 'open')
                        ->whereHas(
                            'participants',
                            fn ($query) =>
                                $query->where(
                                    'users.id',
                                    $admin->id
                                )
                        )
                        ->whereHas(
                            'participants',
                            fn ($query) =>
                                $query->where(
                                    'users.id',
                                    $participant->id
                                )
                        )
                        ->first();

                if (! $conversation) {
                    $now = now();

                    $conversation =
                        Conversation::query()->create([
                            'subject' =>
                                "Dispute {$dispute->dispute_no}",

                            'dispute_id' =>
                                $dispute->id,

                            'type' =>
                                'dispute',

                            'status' =>
                                'open',

                            'created_by' =>
                                $admin->id,

                            'last_message_at' =>
                                null,
                        ]);

                    $conversation
                        ->participants()
                        ->attach(
                            $admin->id,
                            [
                                'participant_role' =>
                                    'admin',

                                'last_read_at' =>
                                    $now,

                                'joined_at' =>
                                    $now,
                            ]
                        );

                    $conversation
                        ->participants()
                        ->attach(
                            $participant->id,
                            [
                                'participant_role' =>
                                    $participantRole,

                                'last_read_at' =>
                                    null,

                                'joined_at' =>
                                    $now,
                            ]
                        );
                }

                $now = now();

                $message =
                    $conversation
                        ->messages()
                        ->create([
                            'sender_id' =>
                                $admin->id,

                            'body' =>
                                $validated['message'],

                            'message_type' =>
                                'text',

                            'sent_at' =>
                                $now,
                        ]);

                $conversation->update([
                    'last_message_at' =>
                        $now,
                ]);

                DB::table(
                    'conversation_participants'
                )
                    ->where(
                        'conversation_id',
                        $conversation->id
                    )
                    ->where(
                        'user_id',
                        $admin->id
                    )
                    ->update([
                        'last_read_at' =>
                            $now,
                    ]);

                $sent[] = [
                    'conversation_id' =>
                        $conversation->id,

                    'participant_id' =>
                        $participant->id,

                    'participant_role' =>
                        $participantRole,

                    'message_id' =>
                        $message->id,
                ];
            }

            $event = DisputeEvent::create([
                'dispute_id' =>
                    $dispute->id,

                'event_type' =>
                    'participant_update',

                'actor_user_id' =>
                    $admin->id,

                'from_status' =>
                    $dispute->status,

                'to_status' =>
                    $dispute->status,

                'note' =>
                    $validated['message'],

                'metadata' => [
                    'visibility' =>
                        'participants',

                    'recipient_count' =>
                        count($sent),

                    'recipients' =>
                        $sent,
                ],

                'created_at' =>
                    now(),
            ]);

            $this->auditService->record(
                action:
                    'dispute.participant_update_sent',

                module:
                    'Compliance & Disputes',

                subject:
                    $dispute,

                description:
                    "Participant update sent for {$dispute->dispute_no}.",

                oldValues: [],

                newValues: [
                    'event_id' =>
                        $event->id,

                    'recipient_count' =>
                        count($sent),
                ],

                severity:
                    'info',

                request:
                    $request,
            );

            return [
                'event' =>
                    $event,

                'recipient_count' =>
                    count($sent),
            ];
        });

        return response()->json([
            'message' =>
                'Case update sent to dispute participants.',

            'recipient_count' =>
                $result['recipient_count'],

            'event' => [
                'time' =>
                    $result['event']
                        ->created_at
                        ?->format('g:i A'),

                'text' =>
                    $result['event']->note,
            ],
        ]);
    }

    public function downloadEvidence(
        Request $request,
        DisputeEvidence $evidence
    ): BinaryFileResponse {
        /*
        * Admin routes are already protected by
        * auth + role:admin middleware.
        *
        * Evidence files stay private and are
        * streamed only through this endpoint.
        */

        if (! Storage::disk('local')->exists(
            $evidence->file_path
        )) {
            abort(404);
        }

        $this->auditService->record(
            action:
                'dispute.evidence_downloaded',

            module:
                'Compliance & Disputes',

            subject:
                $evidence->dispute,

            description:
                "Evidence downloaded for {$evidence->dispute->dispute_no}.",

            oldValues: [],

            newValues: [
                'evidence_id' =>
                    $evidence->id,

                'original_name' =>
                    $evidence->original_name,
            ],

            severity:
                'info',

            request:
                $request,
        );

        return response()->download(
            Storage::disk('local')->path(
                $evidence->file_path
            ),
            $evidence->original_name
        );
    }

    public function resolve(
        Request $request,
        Dispute $dispute
    ): JsonResponse {
        $validated = $request->validate([
            'outcome' => [
                'required',
                Rule::in([
                    'buyer_favored',
                    'seller_favored',
                    'partial_refund_approved',
                    'replacement_arranged',
                ]),
            ],

            'note' => [
                'required',
                'string',
                'max:3000',
            ],
        ]);

        DB::transaction(function () use (
            $request,
            $dispute,
            $validated
        ): void {
            $dispute = Dispute::query()
                ->whereKey($dispute->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (in_array(
                $dispute->status,
                ['resolved', 'closed'],
                true
            )) {
                throw ValidationException::withMessages([
                    'dispute' => 'This dispute has already been finalized.',
                ]);
            }

            $oldStatus = $dispute->status;
            $oldResolution = $dispute->resolution;

            $outcomeLabel = match ($validated['outcome']) {
                'buyer_favored' => 'Buyer favored',
                'seller_favored' => 'Seller favored',
                'partial_refund_approved' => 'Partial refund approved',
                'replacement_arranged' => 'Replacement arranged',
            };

            $dispute->update([
                'status' => 'resolved',
                'assigned_to' => $dispute->assigned_to
                    ?? $request->user()->id,
                'resolved_at' => now(),
                'resolution' => $validated['note'],
            ]);

            DisputeEvent::create([
                'dispute_id' => $dispute->id,
                'event_type' => 'resolved',
                'actor_user_id' => $request->user()->id,
                'from_status' => $oldStatus,
                'to_status' => 'resolved',
                'note' => $validated['note'],
                'metadata' => [
                    'outcome' => $validated['outcome'],
                    'outcome_label' => $outcomeLabel,
                ],
                'created_at' => now(),
            ]);

            $this->auditService->record(
                action: 'dispute.resolved',
                module: 'Compliance & Disputes',
                subject: $dispute,
                description: "{$dispute->dispute_no} was resolved with outcome: {$outcomeLabel}.",
                oldValues: [
                    'status' => $oldStatus,
                    'resolution' => $oldResolution,
                ],
                newValues: [
                    'status' => 'resolved',
                    'resolution' => $validated['note'],
                    'outcome' => $validated['outcome'],
                    'assigned_to' => $dispute->assigned_to,
                    'resolved_at' => $dispute->resolved_at?->toDateTimeString(),
                ],
                severity: 'info',
                request: $request,
            );
        });

        return response()->json([
            'message' => 'Dispute resolved successfully.',
        ]);
    }
}