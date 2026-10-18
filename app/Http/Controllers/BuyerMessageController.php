<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\MessageAttachment;
use App\Models\Product;
use App\Models\Store;
use App\Services\ConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class BuyerMessageController extends Controller
{
    public function __construct(
        private readonly ConversationService $conversations,
    ) {}

    public function inbox(Request $request): JsonResponse
    {
        return response()->json([
            'data' => [
                'conversations' => $this->conversations->inboxForBuyer($request->user()),
            ],
            'message' => 'Conversations loaded.',
        ]);
    }

    /**
     * Start (or reuse) the buyer's conversation with the Store that owns the
     * submitted product. Only the product identifier is accepted; the Store
     * and Seller are resolved server-side from the visible product.
     */
    public function startProduct(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer'],
        ]);

        $buyer = $request->user();

        $product = Product::query()
            ->visibleToBuyers()
            ->with('store.sellerProfile.user')
            ->findOrFail((int) $validated['product_id']);

        $sellerUser = $product->store?->sellerProfile?->user;

        abort_unless($sellerUser, 404);

        $conversation = $this->conversations->findOrCreateProductConversation($buyer, $product, $sellerUser);

        if ($request->expectsJson()) {
            return response()->json([
                'data' => [
                    'conversation_id' => (int) $conversation->id,
                    'url' => route('buyer.chat', ['conversation' => $conversation->id]),
                ],
                'message' => 'Conversation ready.',
            ], 201);
        }

        return redirect()->route('buyer.chat', ['conversation' => $conversation->id]);
    }

    /**
     * Start (or reuse) the buyer's store-level conversation with a Store shown
     * in the buyer-facing storefront. Only the store slug is accepted; the
     * Seller is resolved server-side from the visible store.
     */
    public function startStore(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'store' => ['required', 'string', 'max:255'],
        ]);

        $buyer = $request->user();

        $store = Store::query()
            ->visibleToBuyers()
            ->with('sellerProfile.user')
            ->where('slug', (string) $validated['store'])
            ->firstOrFail();

        $sellerUser = $store->sellerProfile?->user;

        abort_unless($sellerUser, 404);

        $conversation = $this->conversations->findOrCreateStoreConversation($buyer, $store, $sellerUser);

        if ($request->expectsJson()) {
            return response()->json([
                'data' => [
                    'conversation_id' => (int) $conversation->id,
                    'url' => route('buyer.chat', ['conversation' => $conversation->id]),
                ],
                'message' => 'Conversation ready.',
            ], 201);
        }

        return redirect()->route('buyer.chat', ['conversation' => $conversation->id]);
    }

    public function thread(Request $request, Conversation $conversation): JsonResponse
    {
        abort_unless($this->conversations->isParticipant($conversation, $request->user()), 404);

        return response()->json([
            'data' => $this->conversations->threadPayload(
                $conversation,
                $request->user(),
                'buyer.chat.attachment',
                $this->afterId($request),
            ),
            'message' => 'Thread loaded.',
        ]);
    }

    public function send(Request $request, Conversation $conversation): JsonResponse
    {
        abort_unless($this->conversations->isParticipant($conversation, $request->user()), 404);

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
                    'buyer.chat.attachment',
                ),
            ],
            'message' => 'Message sent successfully.',
        ]);
    }

    public function markRead(Request $request, Conversation $conversation): JsonResponse
    {
        abort_unless($this->conversations->isParticipant($conversation, $request->user()), 404);

        $this->conversations->markRead($conversation, $request->user());

        return response()->json([
            'data' => ['unread' => 0],
            'message' => 'Conversation marked as read.',
        ]);
    }

    public function attachment(Request $request, MessageAttachment $attachment)
    {
        $conversation = $this->conversations->conversationForAttachment($attachment);

        abort_unless(
            $conversation && $this->conversations->isParticipant($conversation, $request->user()),
            404,
        );

        abort_unless(Storage::disk('local')->exists($attachment->file_path), 404);

        return $this->conversations->attachmentDownload($attachment);
    }

    /**
     * Only messages newer than this id are returned, which lets the chat page
     * poll for updates without re-downloading the thread history.
     */
    private function afterId(Request $request): ?int
    {
        $after = $request->integer('after');

        return $after > 0 ? $after : null;
    }
}
