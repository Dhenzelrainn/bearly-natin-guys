<?php

namespace App\Http\Controllers;

use App\Models\Dispute;
use App\Models\DisputeEvent;
use App\Models\DisputeEvidence;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ParticipantDisputeController extends Controller
{
    public function index(
        Request $request
    ): JsonResponse {
        $user = $request->user();

        $disputes = Dispute::query()
            ->whereHas(
                'participants',
                fn ($query) => $query->where(
                    'users.id',
                    $user->id
                )
            )
            ->withCount('evidence')
            ->orderByDesc('opened_at')
            ->get()
            ->map(function (Dispute $dispute): array {
                return [
                    'id' => $dispute->id,

                    'dispute_no' => $dispute->dispute_no,

                    'subject' => $dispute->subject,

                    'status' => $dispute->status,

                    'status_label' => Str::of($dispute->status)
                        ->replace(
                            ['_', '-'],
                            ' '
                        )
                        ->title()
                        ->toString(),

                    'priority' => $dispute->priority,

                    'amount_minor' => $dispute->amount_minor,

                    'opened_at' => $dispute->opened_at
                        ?->toIso8601String(),

                    'response_due_at' => $dispute->response_due_at
                        ?->toIso8601String(),

                    'evidence_count' => $dispute->evidence_count,

                    'detail_url' => route(
                        'disputes.show',
                        $dispute
                    ),
                ];
            })
            ->values();

        return response()->json([
            'disputes' => $disputes,
        ]);
    }

    public function show(
        Request $request,
        Dispute $dispute
    ): JsonResponse {
        $user = $request->user();

        $isParticipant = $dispute
            ->participants()
            ->where(
                'users.id',
                $user->id
            )
            ->exists();

        if (! $isParticipant) {
            abort(404);
        }

        $dispute->load([
            'participants',
            'evidence.uploader',
            'events.actor',
        ]);

        $participant =
            $dispute->participants
                ->firstWhere(
                    'id',
                    $user->id
                );

        $evidence =
            $dispute->evidence
                ->sortBy('created_at')
                ->values()
                ->map(function ($item): array {
                    return [
                        'id' => $item->id,

                        'name' => $item->original_name,

                        'description' => $item->description,

                        'type' => $item->type,

                        'uploaded_by' => $item->uploader?->name,

                        'created_at' => $item->created_at
                            ?->toIso8601String(),
                    ];
                });

        /*
        * Admin internal notes must never be
        * exposed to dispute participants.
        */
        $timeline =
            $dispute->events
                ->reject(
                    fn ($event) => $event->event_type ===
                        'internal_note'
                )
                ->sortBy('created_at')
                ->values()
                ->map(function ($event): array {
                    return [
                        'type' => $event->event_type,

                        'text' => $event->note,

                        'actor' => $event->actor?->name,

                        'created_at' => $event->created_at
                            ?->toIso8601String(),
                    ];
                });

        return response()->json([
            'dispute' => [
                'id' => $dispute->id,

                'dispute_no' => $dispute->dispute_no,

                'subject' => $dispute->subject,

                'description' => $dispute->description,

                'priority' => $dispute->priority,

                'status' => $dispute->status,

                'amount_minor' => $dispute->amount_minor,

                'participant_role' => $participant
                    ?->pivot
                    ?->participant_role,

                'opened_at' => $dispute->opened_at
                    ?->toIso8601String(),

                'response_due_at' => $dispute->response_due_at
                    ?->toIso8601String(),

                'resolved_at' => $dispute->resolved_at
                    ?->toIso8601String(),

                'resolution' => $dispute->resolution,

                'evidence' => $evidence,

                'timeline' => $timeline,

                'evidence_upload_url' => ! in_array(
                    $dispute->status,
                    ['resolved', 'closed'],
                    true
                )
                        ? route(
                            'disputes.evidence.store',
                            $dispute
                        )
                        : null,
            ],
        ]);
    }

    public function storeEvidence(
        Request $request,
        Dispute $dispute
    ): JsonResponse {
        $user = $request->user();

        $participant = $dispute
            ->participants()
            ->where(
                'users.id',
                $user->id
            )
            ->first();

        if (! $participant) {
            abort(404);
        }

        if (in_array(
            $dispute->status,
            ['resolved', 'closed'],
            true
        )) {
            throw ValidationException::withMessages([
                'dispute' => 'A finalized dispute cannot receive new evidence.',
            ]);
        }

        $validated = $request->validate([
            'file' => [
                'required',
                'file',
                'max:10240',
                'mimes:jpg,jpeg,png,webp,pdf,doc,docx,xls,xlsx,txt,csv,zip',
            ],

            'description' => [
                'nullable',
                'string',
                'max:500',
            ],
        ]);

        $file = $validated['file'];

        $path = $file->store(
            "dispute-evidence/{$dispute->id}",
            'local'
        );

        try {
            $result = DB::transaction(function () use (

                $user,
                $participant,
                $dispute,
                $validated,
                $file,
                $path
            ): array {
                $evidence =
                    DisputeEvidence::query()->create([
                        'dispute_id' => $dispute->id,

                        'uploaded_by' => $user->id,

                        'type' => $this->evidenceType(
                            $file->getMimeType()
                        ),

                        'file_path' => $path,

                        'original_name' => $file->getClientOriginalName(),

                        'description' => $validated['description']
                                ?? null,

                        'created_at' => now(),
                    ]);

                $event =
                    DisputeEvent::query()->create([
                        'dispute_id' => $dispute->id,

                        'event_type' => 'evidence_submitted',

                        'actor_user_id' => $user->id,

                        'from_status' => $dispute->status,

                        'to_status' => $dispute->status,

                        'note' => "{$participant->pivot->participant_role} submitted evidence: {$evidence->original_name}",

                        'metadata' => [
                            'evidence_id' => $evidence->id,

                            'participant_role' => $participant
                                ->pivot
                                ->participant_role,

                            'original_name' => $evidence->original_name,
                        ],

                        'created_at' => now(),
                    ]);

                return [
                    'evidence' => $evidence,
                    'event' => $event,
                ];
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete(
                $path
            );

            throw $exception;
        }

        return response()->json([
            'message' => 'Evidence submitted successfully.',

            'evidence' => [
                'id' => $result['evidence']->id,

                'name' => $result['evidence']
                    ->original_name,

                'description' => $result['evidence']
                    ->description,

                'type' => $result['evidence']->type,
            ],

            'event' => [
                'time' => $result['event']
                    ->created_at
                    ?->format('g:i A'),

                'text' => $result['event']->note,
            ],
        ], 201);
    }

    private function evidenceType(
        ?string $mimeType
    ): string {
        if (
            $mimeType &&
            str_starts_with(
                $mimeType,
                'image/'
            )
        ) {
            return 'image';
        }

        return 'document';
    }
}
