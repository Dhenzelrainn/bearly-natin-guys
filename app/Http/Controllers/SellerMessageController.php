<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\MessageAttachment;
use App\Models\Store;
use App\Models\User;
use App\Services\ConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SellerMessageController extends Controller
{
    public function __construct(
        private readonly ConversationService $conversations,
    ) {}

    /**
     * Store-scoped inbox rows as JSON for keeping the messages page current
     * without a full page reload.
     */
    public function inbox(Request $request): JsonResponse
    {
        [, $store] = $this->context($request);

        return response()->json([
            'data' => [
                'conversations' => $this->conversations->inboxForSeller($request->user(), (int) $store->id),
            ],
            'message' => 'Conversations loaded.',
        ]);
    }

    public function thread(Request $request, Conversation $conversation): JsonResponse
    {
        [, $store] = $this->context($request);

        abort_unless(
            $this->conversations->sellerCanAccess($conversation, $request->user(), (int) $store->id),
            404,
        );

        return response()->json([
            'data' => $this->conversations->threadPayload(
                $conversation,
                $request->user(),
                'seller.support.messages.attachment',
                $this->afterId($request),
            ),
            'message' => 'Thread loaded.',
        ]);
    }

    public function send(Request $request, Conversation $conversation): JsonResponse
    {
        [, $store] = $this->context($request);

        abort_unless(
            $this->conversations->sellerCanAccess($conversation, $request->user(), (int) $store->id),
            404,
        );

        $validated = $request->validate([
            'message' => [
                'nullable',
                'string',
                'max:'.ConversationService::MESSAGE_MAX_LENGTH,
                'required_without:attachment',
            ],
            'attachment' => [
                'nullable',
                'file',
                'max:'.ConversationService::ATTACHMENT_MAX_KILOBYTES,
                'mimes:'.ConversationService::ATTACHMENT_MIMES,
            ],
        ]);

        $message = $this->conversations->send(
            $conversation,
            $request->user(),
            $validated['message'] ?? null,
            $request->file('attachment'),
        );

        $message->load('attachments');

        return response()->json([
            'data' => [
                'message' => $this->conversations->messagePayload(
                    $message,
                    $request->user(),
                    'seller.support.messages.attachment',
                ),
            ],
            'message' => 'Message sent successfully.',
        ]);
    }

    public function markRead(Request $request, Conversation $conversation): JsonResponse
    {
        [, $store] = $this->context($request);

        abort_unless(
            $this->conversations->sellerCanAccess($conversation, $request->user(), (int) $store->id),
            404,
        );

        $this->conversations->markRead($conversation, $request->user());

        return response()->json([
            'data' => ['unread' => 0],
            'message' => 'Conversation marked as read.',
        ]);
    }

    public function attachment(Request $request, MessageAttachment $attachment)
    {
        [, $store] = $this->context($request);

        $conversation = $this->conversations->conversationForAttachment($attachment);

        abort_unless(
            $conversation && $this->conversations->sellerCanAccess($conversation, $request->user(), (int) $store->id),
            404,
        );

        abort_unless(Storage::disk('local')->exists($attachment->file_path), 404);

        return $this->conversations->attachmentDownload($attachment);
    }

    /**
     * Only messages newer than this id are returned, which lets the messages
     * page poll for updates without re-downloading the thread history.
     */
    private function afterId(Request $request): ?int
    {
        $after = $request->integer('after');

        return $after > 0 ? $after : null;
    }

    /**
     * @return array{0: User, 1: Store}
     */
    private function context(Request $request): array
    {
        $seller = $request->user();

        $profile = $seller->sellerProfile()->first();

        abort_unless($profile, 404, 'Seller profile not found.');

        $store = $profile->store()->first();

        abort_unless($store, 404, 'Seller Store not found.');

        return [$seller, $store];
    }
}
