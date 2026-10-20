<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Events\ConversationMessageCreated;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Notifications\WorkflowNotification;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ConversationService
{
    public const MESSAGE_MAX_LENGTH = 5000;

    public const ATTACHMENT_MIMES = 'jpg,jpeg,png,webp,pdf,doc,docx,xls,xlsx,txt,csv,zip';

    public const ATTACHMENT_MAX_KILOBYTES = 10240;

    /**
     * Message timestamps are stored in the application timezone but always
     * presented to both roles in Philippine time.
     */
    private const TIMEZONE = 'Asia/Manila';

    public function __construct(
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Buyer inbox rows, newest activity first.
     *
     * @return list<array<string, mixed>>
     */
    public function inboxForBuyer(User $buyer): array
    {
        $conversations = $this->participatingConversations($buyer)->get();

        $unread = $this->unreadCounts($buyer, $conversations->pluck('id'));

        return $conversations
            ->map(fn (Conversation $conversation): array => $this->buyerSummary(
                $conversation,
                $buyer,
                $unread[(int) $conversation->id] ?? 0,
            ))
            ->values()
            ->all();
    }

    /**
     * Store-scoped seller inbox rows, newest activity first. A conversation is
     * listed when it is linked to a Product or Seller Order that belongs to
     * the authenticated seller's Store, or when it is a direct thread with a
     * Buyer (store-level chat), an Admin (Bearly Support), or a Logistics
     * participant.
     *
     * @return list<array<string, mixed>>
     */
    public function inboxForSeller(User $seller, int $storeId): array
    {
        $conversations = $this->participatingConversations($seller)
            ->where(function ($query) use ($seller, $storeId): void {
                $query->whereHas('product', fn ($products) => $products->where('store_id', $storeId))
                    ->orWhereHas('sellerOrder', fn ($orders) => $orders->where('store_id', $storeId))
                    ->orWhere(function ($linkless): void {
                        $linkless->whereNull('product_id')
                            ->whereNull('seller_order_id')
                            ->whereNull('order_id')
                            ->whereNull('dispute_id')
                            ->whereHas('participants', fn ($participants) => $participants
                                ->whereIn('conversation_participants.participant_role', ['buyer', 'admin', 'logistics']));
                    })
                    ->orWhere(function ($disputeConversation) use ($seller, $storeId): void {
                        $disputeConversation
                            ->whereNotNull('dispute_id')
                            ->whereHas('dispute.sellerOrder', fn ($orders) => $orders
                                ->where('store_id', $storeId))
                            ->whereHas('dispute.participants', fn ($participants) => $participants
                                ->where('users.id', $seller->id)
                                ->where('dispute_participants.participant_role', 'seller'));
                    });
            })
            ->get();

        $unread = $this->unreadCounts($seller, $conversations->pluck('id'));

        $buyerCounts = $conversations
            ->map(fn (Conversation $conversation): int => (int) ($this->buyerParticipant($conversation)?->id ?? 0))
            ->filter()
            ->countBy()
            ->all();

        return $conversations
            ->map(fn (Conversation $conversation): array => $this->sellerSummary(
                $conversation,
                $unread[(int) $conversation->id] ?? 0,
                max(0, ($buyerCounts[(int) ($this->buyerParticipant($conversation)?->id ?? 0)] ?? 1) - 1),
            ))
            ->values()
            ->all();
    }

    /**
     * Thread payload for an authorized viewer. `$attachmentRoute` selects the
     * participant-guarded download endpoint for the current role. When
     * `$afterId` is provided only newer messages are returned, which lets the
     * chat pages poll for updates without re-reading the whole history.
     *
     * @return array{conversation: array<string, mixed>, messages: list<array<string, mixed>>}
     */
    public function threadPayload(Conversation $conversation, User $viewer, string $attachmentRoute, ?int $afterId = null): array
    {
        $conversation->loadMissing([
            'participants.sellerProfile.store',
            'product.store',
            'sellerOrder.order',
            'order',
            'dispute.sellerOrder.order',
            'dispute.shipment',
        ]);

        $messages = $conversation->messages()
            ->with('attachments')
            ->when($afterId, fn ($query) => $query->where('id', '>', $afterId))
            ->orderBy('sent_at')
            ->orderBy('id')
            ->get();

        $title = $this->threadTitle($conversation, $viewer);
        $forSeller = $conversation->participants
            ->firstWhere('id', $viewer->id)?->pivot?->participant_role === 'seller';

        return [
            'conversation' => [
                'id' => (int) $conversation->id,
                'status' => (string) $conversation->status,
                'title' => $title,
                'initials' => $this->initials($title),
                'context' => $this->conversationContext($conversation),
                'store_url' => $this->storeUrl($conversation),
                'product_url' => $this->productUrl($conversation, $forSeller),
                'counterpart' => $this->counterpartPayload($conversation, $viewer),
                'product' => $this->productCard($conversation->product, $forSeller),
                'order' => $this->orderCard($conversation, $viewer),
                'dispute' => $this->disputePayload($conversation),
            ],
            'messages' => $messages
                ->map(fn (Message $message): array => $this->messagePayload($message, $viewer, $attachmentRoute))
                ->values()
                ->all(),
        ];
    }

    /**
     * Reuse the buyer's open conversation for this product, or create one
     * transactionally. The Seller participant always comes from the Store
     * resolved from the product; request input is never trusted for ownership.
     */
    public function findOrCreateProductConversation(User $buyer, Product $product, User $sellerUser): Conversation
    {
        return DB::transaction(function () use ($buyer, $product, $sellerUser): Conversation {
            $existing = Conversation::query()
                ->where('product_id', $product->id)
                ->where('status', 'open')
                ->whereHas('participants', fn ($query) => $query->where('users.id', $buyer->id))
                ->whereHas('participants', fn ($query) => $query->where('users.id', $sellerUser->id))
                ->orderByDesc('id')
                ->first();

            if ($existing) {
                return $existing;
            }

            $now = now();

            $conversation = Conversation::query()->create([
                'subject' => $product->name,
                'product_id' => $product->id,
                'type' => 'direct',
                'status' => 'open',
                'created_by' => $buyer->id,
                'last_message_at' => $now,
            ]);

            $conversation->participants()->attach($buyer->id, [
                'participant_role' => 'buyer',
                'last_read_at' => $now,
                'joined_at' => $now,
            ]);

            $conversation->participants()->attach($sellerUser->id, [
                'participant_role' => 'seller',
                'last_read_at' => null,
                'joined_at' => $now,
            ]);

            return $conversation;
        });
    }

    /**
     * Reuse the buyer's open store-level conversation with this Store, or
     * create one transactionally. Store-level conversations carry no product
     * or order link; the Seller participant is resolved from the Store page,
     * never from request input.
     */
    public function findOrCreateStoreConversation(User $buyer, Store $store, User $sellerUser): Conversation
    {
        return DB::transaction(function () use ($buyer, $store, $sellerUser): Conversation {
            $existing = Conversation::query()
                ->whereNull('product_id')
                ->whereNull('seller_order_id')
                ->whereNull('order_id')
                ->whereNull('dispute_id')
                ->where('status', 'open')
                ->whereHas('participants', fn ($query) => $query->where('users.id', $buyer->id))
                ->whereHas('participants', fn ($query) => $query->where('users.id', $sellerUser->id))
                ->orderByDesc('id')
                ->first();

            if ($existing) {
                return $existing;
            }

            $now = now();

            $conversation = Conversation::query()->create([
                'subject' => $store->name,
                'type' => 'direct',
                'status' => 'open',
                'created_by' => $buyer->id,
                'last_message_at' => $now,
            ]);

            $conversation->participants()->attach($buyer->id, [
                'participant_role' => 'buyer',
                'last_read_at' => $now,
                'joined_at' => $now,
            ]);

            $conversation->participants()->attach($sellerUser->id, [
                'participant_role' => 'seller',
                'last_read_at' => null,
                'joined_at' => $now,
            ]);

            return $conversation;
        });
    }

    /**
     * Persist a message (with an optional private attachment) and update the
     * conversation activity + the sender's read marker atomically.
     */
    public function send(Conversation $conversation, User $sender, ?string $body, ?UploadedFile $attachment): Message
    {
        $storedPath = null;

        try {
            return DB::transaction(function () use ($conversation, $sender, $body, $attachment, &$storedPath): Message {
                $locked = Conversation::query()
                    ->lockForUpdate()
                    ->findOrFail($conversation->id);

                abort_if(
                    $locked->status !== 'open',
                    409,
                    'Messages cannot be sent to a closed conversation.',
                );

                $now = now();
                $text = trim((string) $body);

                $message = Message::query()->create([
                    'conversation_id' => $locked->id,
                    'sender_id' => $sender->id,
                    'body' => $text,
                    'message_type' => $attachment && $text === '' ? 'attachment' : 'text',
                    'sent_at' => $now,
                ]);

                if ($attachment) {
                    $storedPath = $attachment->store("message-attachments/{$locked->id}", 'local');

                    $message->attachments()->create([
                        'file_path' => $storedPath,
                        'original_name' => $attachment->getClientOriginalName(),
                        'mime_type' => $attachment->getMimeType() ?: 'application/octet-stream',
                        'size_bytes' => $attachment->getSize(),
                    ]);
                }

                $locked->forceFill(['last_message_at' => $now])->save();

                DB::table('conversation_participants')
                    ->where('conversation_id', $locked->id)
                    ->where('user_id', $sender->id)
                    ->update(['last_read_at' => $now]);

                $this->notifyConversationCounterparts($locked, $sender, $message);

                return $message;
            });
        } catch (Throwable $exception) {
            /*
             * Filesystem writes are not rolled back by a database
             * transaction. Remove the orphaned file manually.
             */
            if ($storedPath && Storage::disk('local')->exists($storedPath)) {
                Storage::disk('local')->delete($storedPath);
            }

            throw $exception;
        }
    }

    /**
     * Dispatch "new message" notifications to every other conversation
     * participant after the surrounding transaction commits. Public so
     * producers outside this service (the Logistics and Admin workspaces)
     * can announce messages they persist themselves.
     */
    public function notifyConversationCounterparts(Conversation $conversation, User $sender, Message $message): void
    {
        $recipientIds = DB::table('conversation_participants')
            ->where('conversation_id', $conversation->id)
            ->where('user_id', '!=', $sender->id)
            ->pluck('user_id');

        if ($recipientIds->isEmpty()) {
            return;
        }

        DB::afterCommit(function () use ($conversation, $message, $recipientIds): void {
            try {
                event(new ConversationMessageCreated(
                    conversationId: (int) $conversation->id,
                    messageId: (int) $message->id,
                    recipientIds: $recipientIds->map(fn ($id): int => (int) $id)->values()->all(),
                ));
            } catch (Throwable $exception) {
                /*
                 * A realtime outage must not roll back or fail a message that
                 * is already persisted. Recipients still get the notification
                 * and the polling fallback.
                 */
                Log::notice('Bearly realtime message broadcast failed.', [
                    'conversation_id' => (int) $conversation->id,
                    'message_id' => (int) $message->id,
                    'exception' => $exception::class,
                ]);
            }
        });

        $preview = trim((string) $message->body);
        $preview = $preview === '' ? 'Sent an attachment.' : Str::limit($preview, 120);

        $conversationId = (int) $conversation->id;

        foreach (User::query()->whereIn('id', $recipientIds)->get() as $recipient) {
            $eventKey = match ($recipient->role) {
                UserRole::Buyer->value => 'buyer_message_received',
                UserRole::Seller->value => 'seller_buyer_message',
                UserRole::Admin->value => 'admin_message_received',
                UserRole::Logistics->value => 'logistics_message_received',
                default => null,
            };

            if ($eventKey === null) {
                continue;
            }

            $this->notifications->sendAfterCommit($recipient, new WorkflowNotification(
                eventKey: $eventKey,
                title: 'New message from '.$sender->name,
                body: $preview,
                actionUrl: match ($recipient->role) {
                    UserRole::Buyer->value => route('buyer.chat', ['conversation' => $conversationId]),
                    UserRole::Admin->value => route('admin.messages', ['conversation' => $conversationId]),
                    UserRole::Logistics->value => route('logistics.messages.index', ['conversation' => $conversationId]),
                    default => route('seller.support.messages', ['conversation' => $conversationId]),
                },
                type: 'info',
                meta: ['conversation_id' => $conversationId],
            ));
        }
    }

    public function markRead(Conversation $conversation, User $user): void
    {
        $readAt = now();

        DB::table('conversation_participants')
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $user->id)
            ->update(['last_read_at' => $readAt]);

        $this->markConversationNotificationsRead($conversation, $user, $readAt);
    }

    /**
     * Keep the notification centre consistent with the conversation read
     * state: message notifications for this conversation that already existed
     * at the read moment are marked read as well. Notifications created after
     * the read moment stay unread, matching last_read_at.
     */
    public function markConversationNotificationsRead(Conversation $conversation, User $user, ?CarbonInterface $before = null): void
    {
        $before ??= now();

        $user->unreadNotifications()
            ->where('created_at', '<=', $before)
            ->get()
            ->filter(
                fn (DatabaseNotification $notification) => (int) ($notification->data['meta']['conversation_id'] ?? 0) === (int) $conversation->id,
            )
            ->each(fn (DatabaseNotification $notification) => $notification->markAsRead());
    }

    public function isParticipant(Conversation $conversation, User $user): bool
    {
        return DB::table('conversation_participants')
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $user->id)
            ->exists();
    }

    /**
     * A seller may access a conversation only when they participate in it and
     * either its linked Product/Seller Order belongs to their Store, or it is
     * a store-level buyer conversation (no product/order/dispute link).
     */
    public function sellerCanAccess(Conversation $conversation, User $seller, int $storeId): bool
    {
        $isSellerParticipant = DB::table('conversation_participants')
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $seller->id)
            ->where('participant_role', 'seller')
            ->exists();

        if (! $isSellerParticipant) {
            return false;
        }

        if ($conversation->dispute_id !== null) {
            return Conversation::query()
                ->whereKey($conversation->id)
                ->whereHas('dispute.sellerOrder', fn ($orders) => $orders
                    ->where('store_id', $storeId))
                ->whereHas('dispute.participants', fn ($participants) => $participants
                    ->where('users.id', $seller->id)
                    ->where('dispute_participants.participant_role', 'seller'))
                ->exists();
        }

        if (
            $conversation->product_id === null
            && $conversation->seller_order_id === null
            && $conversation->order_id === null
            && $conversation->dispute_id === null
        ) {
            $conversation->loadMissing('participants');

            return $this->buyerParticipant($conversation) !== null
                || $this->adminParticipant($conversation) !== null
                || $this->logisticsParticipant($conversation) !== null;
        }

        return Conversation::query()
            ->whereKey($conversation->id)
            ->where(function ($query) use ($storeId): void {
                $query->whereHas('product', fn ($products) => $products->where('store_id', $storeId))
                    ->orWhereHas('sellerOrder', fn ($orders) => $orders->where('store_id', $storeId));
            })
            ->exists();
    }

    public function conversationForAttachment(MessageAttachment $attachment): ?Conversation
    {
        $attachment->loadMissing('message.conversation');

        return $attachment->message?->conversation;
    }

    public function attachmentDownload(MessageAttachment $attachment): StreamedResponse
    {
        return Storage::disk('local')->download(
            $attachment->file_path,
            $attachment->original_name,
            ['Content-Type' => $attachment->mime_type ?: 'application/octet-stream'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function messagePayload(Message $message, User $viewer, string $attachmentRoute): array
    {
        $sentAt = $message->sent_at?->copy()->setTimezone(self::TIMEZONE);

        return [
            'id' => (int) $message->id,
            'from' => (int) $message->sender_id === (int) $viewer->id ? 'me' : 'them',
            'text' => (string) $message->body,
            'sent_at' => $sentAt?->toIso8601String(),
            'time_label' => $sentAt?->format('g:i A') ?? '',
            'day_label' => $this->dayLabel($message->sent_at),
            'attachments' => $message->attachments
                ->map(fn (MessageAttachment $attachment): array => [
                    'id' => (int) $attachment->id,
                    'name' => (string) $attachment->original_name,
                    'mime_type' => (string) $attachment->mime_type,
                    'size_bytes' => (int) $attachment->size_bytes,
                    'is_image' => str_starts_with((string) $attachment->mime_type, 'image/'),
                    'download_url' => route($attachmentRoute, ['attachment' => $attachment->id]),
                ])
                ->values()
                ->all(),
        ];
    }

    private function participatingConversations(User $user)
    {
        return Conversation::query()
            ->whereHas('participants', fn ($query) => $query->where('users.id', $user->id))
            ->with([
                'product.store',
                'participants.sellerProfile.store',
                'sellerOrder.order',
                'order',
                'dispute.sellerOrder.order',
                'dispute.shipment',
                'latestMessage',
            ])
            ->orderByDesc('last_message_at')
            ->orderByDesc('id');
    }

    /**
     * @param  Collection<int, int>  $conversationIds
     * @return array<int, int>
     */
    private function unreadCounts(User $user, Collection $conversationIds): array
    {
        if ($conversationIds->isEmpty()) {
            return [];
        }

        $userId = (int) $user->id;

        return DB::table('messages as messages')
            ->join('conversation_participants as participants', function ($join) use ($userId): void {
                $join->on('participants.conversation_id', '=', 'messages.conversation_id')
                    ->where('participants.user_id', '=', $userId);
            })
            ->whereIn('messages.conversation_id', $conversationIds->all())
            ->where('messages.sender_id', '!=', $userId)
            ->where(function ($query): void {
                $query->whereNull('participants.last_read_at')
                    ->orWhereColumn('messages.sent_at', '>', 'participants.last_read_at');
            })
            ->groupBy('messages.conversation_id')
            ->selectRaw('messages.conversation_id as conversation_id, COUNT(*) as unread_count')
            ->pluck('unread_count', 'conversation_id')
            ->map(fn ($count): int => (int) $count)
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function buyerSummary(Conversation $conversation, User $buyer, int $unread): array
    {
        $store = $this->storeFor($conversation);
        $latest = $conversation->latestMessage;
        $lastActivityAt = $latest?->sent_at ?? $conversation->last_message_at;
        $title = $this->buyerConversationTitle($conversation, $buyer);

        return [
            'id' => (int) $conversation->id,
            'status' => (string) $conversation->status,
            'type' => (string) $conversation->type,
            'title' => $title,
            'context' => $this->conversationContext($conversation),
            'dispute' => $this->disputePayload($conversation),
            'preview' => $this->previewText($latest),
            'time_label' => $this->timeLabel($lastActivityAt),
            'unread' => $unread,
            'is_seller' => $this->hasSellerParticipant($conversation),
            'avatar' => $this->initials($title),
            'image_url' => $this->logoUrl($store),
            'store_url' => $this->storeUrl($conversation),
            'product_url' => $this->productUrl($conversation),
            'thread_url' => route('buyer.chat.thread', ['conversation' => $conversation->id]),
            'send_url' => route('buyer.chat.send', ['conversation' => $conversation->id]),
            'read_url' => route('buyer.chat.read', ['conversation' => $conversation->id]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sellerSummary(Conversation $conversation, int $unread, int $previous): array
    {
        $buyer = $this->buyerParticipant($conversation);
        $admin = $buyer ? null : $this->adminParticipant($conversation);
        $logistics = ($buyer || $admin) ? null : $this->logisticsParticipant($conversation);
        $counterpart = $buyer ?? $admin ?? $logistics;
        $product = $conversation->product;
        $sellerOrder = $conversation->sellerOrder;
        $disputeOrder = $conversation->dispute?->sellerOrder;
        $order = $sellerOrder?->order ?? $disputeOrder?->order;
        $latest = $conversation->latestMessage;
        $lastActivityAt = $latest?->sent_at ?? $conversation->last_message_at;

        if ($buyer) {
            $counterpartName = $this->displayName($buyer);
        } else {
            $counterpartName = $admin
                ? ($this->roleName('admin') ?? 'Bearly Support')
                : ($logistics
                    ? ($this->roleName('logistics') ?? 'Bearly Logistics')
                    : 'Buyer');
        }

        return [
            'id' => (int) $conversation->id,
            'status' => (string) $conversation->status,
            'type' => $conversation->dispute || $sellerOrder ? 'order' : 'product',
            'buyer' => $counterpartName,
            'initials' => $this->initials($counterpartName),
            'counterpart_role' => $buyer ? 'buyer' : ($admin ? 'admin' : ($logistics ? 'logistics' : null)),
            'member' => $counterpart?->created_at?->copy()->setTimezone(self::TIMEZONE)->format('F j, Y') ?? '—',
            'previous' => $previous,
            'context' => $this->conversationContext($conversation),
            'order' => $order?->order_no,
            'order_status' => $sellerOrder
                ? Str::headline((string) $sellerOrder->status)
                : ($disputeOrder ? Str::headline((string) $disputeOrder->status) : null),
            'order_url' => $order ? route('seller.orders') : null,
            'product' => $product?->name,
            'product_url' => $this->productUrl($conversation, true),
            'dispute' => $this->disputePayload($conversation),
            'preview' => $this->previewText($latest),
            'time_label' => $this->timeLabel($lastActivityAt),
            'unread' => $unread,
            'thread_url' => route('seller.support.messages.thread', ['conversation' => $conversation->id]),
            'send_url' => route('seller.support.messages.send', ['conversation' => $conversation->id]),
            'read_url' => route('seller.support.messages.read', ['conversation' => $conversation->id]),
        ];
    }

    private function threadTitle(Conversation $conversation, User $viewer): string
    {
        $viewerRole = $conversation->participants
            ->firstWhere('id', $viewer->id)?->pivot?->participant_role;

        if ($viewerRole === 'seller') {
            $buyer = $this->buyerParticipant($conversation);

            if ($buyer) {
                return $this->displayName($buyer);
            }

            if ($this->adminParticipant($conversation)) {
                return $this->roleName('admin') ?? 'Bearly Support';
            }

            if ($this->logisticsParticipant($conversation)) {
                return $this->roleName('logistics') ?? 'Bearly Logistics';
            }

            return 'Buyer';
        }

        return $this->buyerConversationTitle($conversation, $viewer);
    }

    private function buyerConversationTitle(Conversation $conversation, User $buyer): string
    {
        $counterpart = $conversation->participants
            ->first(fn (User $participant): bool => (int) $participant->id !== (int) $buyer->id);

        $role = (string) ($counterpart?->pivot?->participant_role ?? '');

        return $this->roleName($role)
            ?? $this->storeFor($conversation)?->name
            ?? ($counterpart ? $this->displayName($counterpart) : 'Bearly Support');
    }

    private function conversationContext(Conversation $conversation): string
    {
        if ($conversation->product) {
            return (string) $conversation->product->name;
        }

        if ($conversation->dispute) {
            return 'Dispute '.$conversation->dispute->dispute_no;
        }

        $orderNo = $conversation->sellerOrder?->order?->order_no
            ?? $conversation->order?->order_no;

        if ($orderNo) {
            return 'Order '.$orderNo;
        }

        return 'Direct message';
    }

    /**
     * Participant-visible dispute context for conversation side panels.
     * Internal dispute events and Admin-only notes are intentionally excluded.
     *
     * @return array<string, mixed>|null
     */
    private function disputePayload(Conversation $conversation): ?array
    {
        $dispute = $conversation->dispute;

        if (! $dispute) {
            return null;
        }

        $sellerOrder = $dispute->sellerOrder;
        $shipment = $dispute->shipment;

        return [
            'id' => (int) $dispute->id,
            'number' => (string) $dispute->dispute_no,
            'subject' => (string) $dispute->subject,
            'description' => (string) $dispute->description,
            'status' => (string) $dispute->status,
            'status_label' => Str::headline((string) $dispute->status),
            'amount_label' => $dispute->amount_minor !== null
                ? '₱'.number_format(((int) $dispute->amount_minor) / 100, 2)
                : '—',
            'seller_order_no' => $sellerOrder?->seller_order_no,
            'order_no' => $sellerOrder?->order?->order_no,
            'shipment_no' => $shipment?->shipment_no,
            'shipment_status' => $shipment?->status
                ? Str::headline((string) $shipment->status)
                : null,
        ];
    }

    private function storeUrl(Conversation $conversation): ?string
    {
        $store = $this->storeFor($conversation);

        return $store?->slug ? route('stores.show', ['store' => $store->slug]) : null;
    }

    /**
     * The product link for the current surface: the Buyer-facing storefront
     * page for buyers, or the seller's own product editor for sellers
     * (buyer routes answer 403 for signed-in sellers).
     */
    private function productUrl(Conversation $conversation, bool $forSeller = false): ?string
    {
        if (! $conversation->product) {
            return null;
        }

        return route($forSeller ? 'seller.products.edit' : 'products.show', [
            'product' => $conversation->product->id,
        ]);
    }

    /**
     * The Store a conversation belongs to: the linked product's store, or the
     * store of the seller participant for store-level conversations.
     */
    private function storeFor(Conversation $conversation): ?Store
    {
        return $conversation->product?->store
            ?? $this->sellerParticipant($conversation)?->sellerProfile?->store;
    }

    private function logoUrl(?Store $store): ?string
    {
        if (! $store?->logo_path) {
            return null;
        }

        return Storage::disk('public')->url($store->logo_path);
    }

    /**
     * Counterpart card data for the conversation details panel, resolved for
     * the current viewer: the Buyer for sellers, the Store for buyers.
     *
     * @return array{image_url: ?string, since_label: ?string, since_caption: ?string}
     */
    private function counterpartPayload(Conversation $conversation, User $viewer): array
    {
        $viewerRole = $conversation->participants
            ->firstWhere('id', $viewer->id)?->pivot?->participant_role;

        if ($viewerRole === 'seller') {
            $counterpart = $this->buyerParticipant($conversation)
                ?? $this->adminParticipant($conversation)
                ?? $this->logisticsParticipant($conversation);

            return [
                'image_url' => null,
                'since_label' => $counterpart?->created_at?->copy()->setTimezone(self::TIMEZONE)->format('F j, Y'),
                'since_caption' => $counterpart ? 'Member since' : null,
            ];
        }

        $store = $this->storeFor($conversation);

        if ($store) {
            return [
                'image_url' => $this->logoUrl($store),
                'since_label' => $store->created_at?->copy()->setTimezone(self::TIMEZONE)->format('M Y'),
                'since_caption' => 'Seller on Bearly since',
            ];
        }

        return [
            'image_url' => null,
            'since_label' => null,
            'since_caption' => null,
        ];
    }

    /**
     * Real product card data (image, price, link) for the conversation
     * details panel.
     *
     * @return array<string, mixed>|null
     */
    private function productCard(?Product $product, bool $forSeller = false): ?array
    {
        if (! $product) {
            return null;
        }

        $product->loadMissing(['images', 'variants']);

        $variant = $product->variants
            ->where('is_active', true)
            ->sortBy('position')
            ->first();

        $primary = $product->images->whereNull('variant_id')->firstWhere('is_primary', true)
            ?: $product->images->whereNull('variant_id')->first();

        $priceMinor = $variant ? (int) $variant->price_minor : null;

        return [
            'id' => (int) $product->id,
            'name' => (string) $product->name,
            'url' => route($forSeller ? 'seller.products.edit' : 'products.show', [
                'product' => $product->id,
            ]),
            'image_url' => $primary && $primary->path
                ? Storage::disk($primary->disk ?: 'public')->url($primary->path)
                : null,
            'price_minor' => $priceMinor,
            'price_label' => $priceMinor !== null ? '₱'.number_format($priceMinor / 100, 2) : null,
        ];
    }

    /**
     * Linked order card data for the conversation details panel.
     *
     * @return array{number: string, status_label: ?string, url: ?string}|null
     */
    private function orderCard(Conversation $conversation, User $viewer): ?array
    {
        $sellerOrder = $conversation->sellerOrder
            ?? $conversation->dispute?->sellerOrder;
        $order = $sellerOrder?->order ?? $conversation->order;

        if (! $order) {
            return null;
        }

        $isSeller = $conversation->participants
            ->firstWhere('id', $viewer->id)?->pivot?->participant_role === 'seller';

        return [
            'number' => (string) $order->order_no,
            'status_label' => $sellerOrder
                ? Str::headline((string) $sellerOrder->status)
                : Str::headline((string) $order->status),
            'url' => $isSeller ? route('seller.orders') : null,
        ];
    }

    private function hasSellerParticipant(Conversation $conversation): bool
    {
        return $conversation->participants
            ->contains(fn (User $participant): bool => ($participant->pivot?->participant_role ?? '') === 'seller');
    }

    private function buyerParticipant(Conversation $conversation): ?User
    {
        return $conversation->participants
            ->first(fn (User $participant): bool => ($participant->pivot?->participant_role ?? '') === 'buyer');
    }

    private function adminParticipant(Conversation $conversation): ?User
    {
        return $conversation->participants
            ->first(fn (User $participant): bool => ($participant->pivot?->participant_role ?? '') === 'admin');
    }

    private function logisticsParticipant(Conversation $conversation): ?User
    {
        return $conversation->participants
            ->first(fn (User $participant): bool => ($participant->pivot?->participant_role ?? '') === 'logistics');
    }

    private function sellerParticipant(Conversation $conversation): ?User
    {
        return $conversation->participants
            ->first(fn (User $participant): bool => ($participant->pivot?->participant_role ?? '') === 'seller');
    }

    private function roleName(string $role): ?string
    {
        return match ($role) {
            'admin' => 'Bearly Support',
            'logistics' => 'Bearly Logistics',
            'rider' => 'Bearly Rider',
            default => null,
        };
    }

    private function displayName(User $user): string
    {
        $name = trim((string) ($user->name ?? ''));

        if ($name === '') {
            $name = trim(($user->first_name ?? '').' '.($user->last_name ?? ''));
        }

        return $name !== '' ? $name : 'Bearly user';
    }

    private function initials(string $name): string
    {
        $parts = array_slice(array_values(array_filter(preg_split('/\s+/', trim($name)) ?: [])), 0, 2);

        $initials = '';

        foreach ($parts as $part) {
            $initials .= mb_strtoupper(mb_substr((string) $part, 0, 1));
        }

        return $initials !== '' ? $initials : 'B';
    }

    private function previewText(?Message $message): string
    {
        if (! $message) {
            return 'No messages yet.';
        }

        $body = trim((string) $message->body);

        return $body !== '' ? Str::limit($body, 120) : 'Sent an attachment.';
    }

    private function timeLabel(?CarbonInterface $moment): string
    {
        if (! $moment) {
            return '';
        }

        $at = $moment->copy()->setTimezone(self::TIMEZONE);
        $now = CarbonImmutable::now(self::TIMEZONE);

        if ($at->isSameDay($now)) {
            return $at->format('g:i A');
        }

        if ($at->isSameDay($now->subDay())) {
            return 'Yesterday';
        }

        if ($at->isSameYear($now)) {
            return $at->format('M j');
        }

        return $at->format('M j, Y');
    }

    private function dayLabel(?CarbonInterface $moment): string
    {
        if (! $moment) {
            return '';
        }

        $at = $moment->copy()->setTimezone(self::TIMEZONE);
        $now = CarbonImmutable::now(self::TIMEZONE);

        if ($at->isSameDay($now)) {
            return 'Today';
        }

        if ($at->isSameDay($now->subDay())) {
            return 'Yesterday';
        }

        return $at->format('M j, Y');
    }
}
