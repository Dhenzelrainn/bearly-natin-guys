<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Dispute;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class AdminMessageController extends Controller
{
    public function storeConversation(Request $request): JsonResponse
    {
        $admin = $request->user();

        $validated = $request->validate([
            'recipient_id' => [
                'required',
                'integer',
                'exists:users,id',
            ],

            'recipient_role' => [
                'required',
                'string',
                Rule::in([
                    'buyer',
                    'seller',
                    'logistics',
                    'rider',
                ]),
            ],

            'subject' => [
                'nullable',
                'string',
                'max:180',
            ],

            'message' => [
                'required',
                'string',
                'max:5000',
            ],
        ]);

        if ((int) $validated['recipient_id'] === (int) $admin->id) {
            throw ValidationException::withMessages([
                'recipient_id' => 'You cannot start a conversation with yourself.',
            ]);
        }

        $recipient = User::query()
            ->findOrFail($validated['recipient_id']);

        $recipientRole = strtolower(
            $validated['recipient_role']
        );

        if (! $recipient->hasRole($recipientRole)) {
            throw ValidationException::withMessages([
                'recipient_id' => 'The selected user does not belong to the selected role.',
            ]);
        }

        if ($recipient->status !== 'active') {
            throw ValidationException::withMessages([
                'recipient_id' => 'Only active users can receive new conversations.',
            ]);
        }

        [$conversation, $message] = DB::transaction(
            function () use (
                $admin,
                $recipient,
                $recipientRole,
                $validated
            ) {
                $now = now();

                $conversation = Conversation::query()->create([
                    'subject' => $validated['subject'] ?? null,
                    'type' => 'direct',
                    'status' => 'open',
                    'created_by' => $admin->id,
                    'last_message_at' => $now,
                ]);

                $conversation->participants()->attach(
                    $admin->id,
                    [
                        'participant_role' => 'admin',
                        'last_read_at' => $now,
                        'joined_at' => $now,
                    ]
                );

                $conversation->participants()->attach(
                    $recipient->id,
                    [
                        'participant_role' => $recipientRole,
                        'last_read_at' => null,
                        'joined_at' => $now,
                    ]
                );

                $message = $conversation
                    ->messages()
                    ->create([
                        'sender_id' => $admin->id,
                        'body' => $validated['message'],
                        'message_type' => 'text',
                        'sent_at' => $now,
                    ]);

                return [
                    $conversation,
                    $message,
                ];
            }
        );

        return response()->json([
            'message' => 'Conversation started successfully.',

            'conversation' => [
                'id' => $conversation->id,
                'subject' => $conversation->subject,
                'status' => $conversation->status,
                'type' => $conversation->type,

                'participant' => [
                    'id' => $recipient->id,
                    'name' => $recipient->name,
                    'role' => $recipientRole,
                ],

                'latest_message' => [
                    'id' => $message->id,
                    'body' => $message->body,
                    'sent_at' => $message->sent_at
                        ?->toIso8601String(),
                ],
            ],
        ], 201);
    }

    public function openDisputeConversation(
        Request $request,
        Dispute $dispute
    ): JsonResponse {
        $admin = $request->user();

        $validated = $request->validate([
            'recipient_id' => [
                'required',
                'integer',
                'exists:users,id',
            ],

            'recipient_role' => [
                'required',
                'string',
                Rule::in([
                    'buyer',
                    'seller',
                    'logistics',
                    'rider',
                ]),
            ],
        ]);

        $recipient = User::query()
            ->findOrFail(
                $validated['recipient_id']
            );

        $recipientRole =
            strtolower(
                $validated['recipient_role']
            );

        if (! $recipient->hasRole($recipientRole)) {
            throw ValidationException::withMessages([
                'recipient_id' => 'The selected user does not belong to the selected role.',
            ]);
        }

        if ($recipient->status !== 'active') {
            throw ValidationException::withMessages([
                'recipient_id' => 'Only active users can receive dispute messages.',
            ]);
        }

        $isDisputeParticipant =
            $dispute
                ->participants()
                ->where(
                    'users.id',
                    $recipient->id
                )
                ->wherePivot(
                    'participant_role',
                    $recipientRole
                )
                ->exists();

        abort_unless(
            $isDisputeParticipant,
            404
        );

        $conversation =
            Conversation::query()
                ->where(
                    'dispute_id',
                    $dispute->id
                )
                ->where('status', 'open')
                ->whereHas(
                    'participants',
                    fn ($query) => $query->where(
                        'users.id',
                        $admin->id
                    )
                )
                ->whereHas(
                    'participants',
                    fn ($query) => $query->where(
                        'users.id',
                        $recipient->id
                    )
                )
                ->first();

        if (! $conversation) {
            $conversation =
                DB::transaction(
                    function () use (
                        $admin,
                        $recipient,
                        $recipientRole,
                        $dispute
                    ) {
                        $now = now();

                        $conversation =
                            Conversation::query()->create([
                                'subject' => "Dispute {$dispute->dispute_no}",

                                'dispute_id' => $dispute->id,

                                'type' => 'dispute',

                                'status' => 'open',

                                'created_by' => $admin->id,

                                'last_message_at' => null,
                            ]);

                        $conversation
                            ->participants()
                            ->attach(
                                $admin->id,
                                [
                                    'participant_role' => 'admin',

                                    'last_read_at' => $now,

                                    'joined_at' => $now,
                                ]
                            );

                        $conversation
                            ->participants()
                            ->attach(
                                $recipient->id,
                                [
                                    'participant_role' => $recipientRole,

                                    'last_read_at' => null,

                                    'joined_at' => $now,
                                ]
                            );

                        return $conversation;
                    }
                );
        }

        return response()->json([
            'message' => 'Dispute conversation ready.',

            'conversation' => [
                'id' => $conversation->id,

                'dispute_id' => $conversation->dispute_id,

                'type' => $conversation->type,

                'status' => $conversation->status,

                'participant' => [
                    'id' => $recipient->id,

                    'name' => $recipient->name,

                    'role' => $recipientRole,
                ],

                'messages_url' => route(
                    'admin.messages',
                    [
                        'conversation' => $conversation->id,
                    ]
                ),
            ],
        ]);
    }

    public function send(
        Request $request,
        Conversation $conversation
    ): JsonResponse {
        $admin = $request->user();

        $validated = $request->validate([
            'message' => [
                'nullable',
                'string',
                'max:5000',
                'required_without:attachment',
            ],

            'attachment' => [
                'nullable',
                'file',
                'max:10240',
                'mimes:jpg,jpeg,png,webp,pdf,doc,docx,xls,xlsx,txt,csv,zip',
            ],
        ]);

        $isParticipant = $conversation
            ->participants()
            ->where('users.id', $admin->id)
            ->exists();

        abort_unless($isParticipant, 404);

        if ($conversation->status !== 'open') {
            throw ValidationException::withMessages([
                'message' => 'Messages cannot be sent to a closed conversation.',
            ]);
        }

        $uploadedFile = $request->file('attachment');

        $storedPath = null;

        try {
            $message = DB::transaction(
                function () use (
                    $admin,
                    $conversation,
                    $validated,
                    $uploadedFile,
                    &$storedPath
                ) {
                    $lockedConversation = Conversation::query()
                        ->lockForUpdate()
                        ->findOrFail($conversation->id);

                    if ($lockedConversation->status !== 'open') {
                        throw ValidationException::withMessages([
                            'message' => 'Messages cannot be sent to a closed conversation.',
                        ]);
                    }

                    $now = now();

                    $body = trim(
                        (string) ($validated['message'] ?? '')
                    );

                    $messageType = $uploadedFile && $body === ''
                        ? 'attachment'
                        : 'text';

                    $message = Message::query()->create([
                        'conversation_id' => $lockedConversation->id,
                        'sender_id' => $admin->id,
                        'body' => $body,
                        'message_type' => $messageType,
                        'sent_at' => $now,
                    ]);

                    if ($uploadedFile) {
                        /*
                         * Store attachments privately.
                         *
                         * Do not expose storage/app/private directly.
                         * We will add an authorized download endpoint
                         * in the next step.
                         */
                        $storedPath = $uploadedFile->store(
                            "message-attachments/{$lockedConversation->id}",
                            'local'
                        );

                        $message->attachments()->create([
                            'file_path' => $storedPath,

                            'original_name' => $uploadedFile->getClientOriginalName(),

                            'mime_type' => $uploadedFile->getMimeType()
                                ?: 'application/octet-stream',

                            'size_bytes' => $uploadedFile->getSize(),
                        ]);
                    }

                    $lockedConversation->update([
                        'last_message_at' => $now,
                    ]);

                    DB::table('conversation_participants')
                        ->where(
                            'conversation_id',
                            $lockedConversation->id
                        )
                        ->where(
                            'user_id',
                            $admin->id
                        )
                        ->update([
                            'last_read_at' => $now,
                        ]);

                    return $message;
                }
            );
        } catch (Throwable $exception) {
            /*
             * Filesystem writes are not rolled back by a
             * database transaction.
             *
             * If the DB operation fails after the file was
             * stored, remove the orphaned file manually.
             */
            if (
                $storedPath &&
                Storage::disk('local')->exists($storedPath)
            ) {
                Storage::disk('local')->delete($storedPath);
            }

            throw $exception;
        }

        $message->load('attachments');

        return response()->json([
            'message' => 'Message sent successfully.',

            'data' => [
                'id' => $message->id,

                'from' => 'me',

                'text' => $message->body,

                'time' => $message->sent_at
                    ?->format('g:i A'),

                'sent_at' => $message->sent_at
                    ?->toIso8601String(),

                'attachments' => $message
                    ->attachments
                    ->map(
                        fn (MessageAttachment $attachment) => [
                            'id' => $attachment->id,

                            'name' => $attachment->original_name,

                            'mime_type' => $attachment->mime_type,

                            'size_bytes' => $attachment->size_bytes,

                            'download_url' => route(
                                'admin.messages.attachments.download',
                                $attachment
                            ),
                        ]
                    )
                    ->values()
                    ->all(),
            ],
        ]);
    }

    public function downloadAttachment(
        Request $request,
        MessageAttachment $attachment
    ) {
        $admin = $request->user();

        $attachment->loadMissing(
            'message.conversation'
        );

        $conversation =
            $attachment->message?->conversation;

        abort_unless(
            $conversation,
            404
        );

        $isParticipant = $conversation
            ->participants()
            ->where(
                'users.id',
                $admin->id
            )
            ->exists();

        abort_unless(
            $isParticipant,
            404
        );

        abort_unless(
            Storage::disk('local')
                ->exists(
                    $attachment->file_path
                ),
            404
        );

        return Storage::disk('local')
            ->download(
                $attachment->file_path,
                $attachment->original_name,
                [
                    'Content-Type' => $attachment->mime_type
                        ?: 'application/octet-stream',
                ]
            );
    }

    public function markRead(
        Request $request,
        Conversation $conversation
    ): JsonResponse {
        $admin = $request->user();

        $participant = DB::table(
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
            ->first();

        abort_unless($participant, 404);

        DB::table('conversation_participants')
            ->where(
                'conversation_id',
                $conversation->id
            )
            ->where(
                'user_id',
                $admin->id
            )
            ->update([
                'last_read_at' => now(),
            ]);

        return response()->json([
            'message' => 'Conversation marked as read.',
            'unread' => 0,
        ]);
    }

    public function markUnread(
        Request $request,
        Conversation $conversation
    ): JsonResponse {
        $admin = $request->user();

        $participant = DB::table(
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
            ->first();

        abort_unless($participant, 404);

        $latestInbound = Message::query()
            ->where(
                'conversation_id',
                $conversation->id
            )
            ->where(
                'sender_id',
                '!=',
                $admin->id
            )
            ->orderByDesc('sent_at')
            ->first();

        if (! $latestInbound) {
            return response()->json([
                'message' => 'There are no received messages to mark as unread.',
                'unread' => 0,
            ]);
        }

        $lastReadAt = Carbon::parse(
            $latestInbound->sent_at
        )->subSecond();

        DB::table('conversation_participants')
            ->where(
                'conversation_id',
                $conversation->id
            )
            ->where(
                'user_id',
                $admin->id
            )
            ->update([
                'last_read_at' => $lastReadAt,
            ]);

        $unread = Message::query()
            ->where(
                'conversation_id',
                $conversation->id
            )
            ->where(
                'sender_id',
                '!=',
                $admin->id
            )
            ->where(
                'sent_at',
                '>',
                $lastReadAt
            )
            ->count();

        return response()->json([
            'message' => 'Conversation marked as unread.',
            'unread' => $unread,
        ]);
    }
}
