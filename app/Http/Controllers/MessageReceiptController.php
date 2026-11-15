<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Services\ConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Role-neutral delivery acknowledgements.
 *
 * Every role's chat client (and the global realtime listener) can call this
 * endpoint after the authenticated browser has actually received a message.
 * Conversation membership is re-checked server-side; the submitted
 * message_ids only narrow the write, they never widen it.
 */
class MessageReceiptController extends Controller
{
    public function __construct(
        private readonly ConversationService $conversations,
    ) {}

    public function delivered(Request $request, Conversation $conversation): JsonResponse
    {
        $recipient = $request->user();

        abort_unless(
            $this->conversations->isParticipant($conversation, $recipient),
            404,
        );

        $validated = $request->validate([
            'message_ids' => ['nullable', 'array', 'max:200'],
            'message_ids.*' => ['integer', 'min:1'],
        ]);

        $count = $this->conversations->acknowledgeDelivered(
            $conversation,
            $recipient,
            array_map('intval', $validated['message_ids'] ?? []),
        );

        return response()->json([
            'data' => ['delivered' => $count],
            'message' => 'Delivery acknowledged.',
        ]);
    }
}
