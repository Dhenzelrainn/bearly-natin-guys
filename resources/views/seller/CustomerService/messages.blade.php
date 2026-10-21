@extends('layouts.seller')
@section('title', 'Messages')
@section('page-title', 'Customer Service')
@section('content')
@php($hasConversations = $conversations !== [])
@php($activeProduct = $activeThread['conversation']['product'] ?? null)
@php($activeOrder = $activeThread['conversation']['order'] ?? null)
@php($activeDispute = $activeThread['conversation']['dispute'] ?? null)
<section class="customer-messages-page" data-message-workspace data-inbox-url="{{ route('seller.support.messages.inbox') }}">
    <header class="customer-service-heading">
        <div><span class="section-kicker">Buyer communication</span><h2>Messages</h2><p>Talk with buyers about products and active orders.</p></div>
        <div class="message-heading-actions">
            <label class="message-search"><i data-lucide="search"></i><input type="search" placeholder="Search buyers or orders" data-message-search></label>
            <div class="message-filter-tabs" role="tablist" aria-label="Conversation filters"><button class="is-active" type="button" data-message-filter="all">All</button><button type="button" data-message-filter="unread">Unread <b data-inbox-unread-count>{{ $totalUnread }}</b></button><button type="button" data-message-filter="order">Orders</button></div>
        </div>
    </header>

    <section class="message-workspace messages-standard-page">
        <aside class="message-inbox">
            <div class="conversation-list" data-conversation-list>
                @foreach ($conversations as $conversation)
                    <button class="conversation-row {{ $activeConversation && (int) $activeConversation['id'] === (int) $conversation['id'] ? 'is-active' : '' }}" type="button" data-conversation data-search="{{ strtolower($conversation['buyer'].' '.$conversation['context'].' '.$conversation['preview']) }}" data-type="{{ $conversation['type'] }}" data-unread="{{ $conversation['unread'] }}" data-payload="{{ json_encode($conversation, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_THROW_ON_ERROR) }}">
                        <span class="conversation-avatar chat-presence-target" data-presence-conversation="{{ $conversation['id'] }}" data-presence-users="{{ implode(',', $conversation['presence_user_ids'] ?? []) }}">{{ $conversation['initials'] }}</span><span class="conversation-copy"><span><strong>{{ $conversation['buyer'] }}</strong><time>{{ $conversation['time_label'] }}</time></span><em>{{ $conversation['preview'] }}</em>@if ($conversation['dispute'])<span class="conversation-dispute-badge"><i data-lucide="gavel"></i>Complaint &amp; Dispute</span>@endif<span class="conversation-chip"><i data-lucide="{{ $conversation['order'] ? 'receipt' : 'package' }}"></i>{{ $conversation['order'] ? 'Order #'.$conversation['order'] : $conversation['context'] }}</span></span>@if ($conversation['unread'])<b class="conversation-unread">{{ $conversation['unread'] }}</b>@endif
                    </button>
                @endforeach
                <div class="conversation-empty" data-conversation-empty @if ($hasConversations) hidden @endif><i data-lucide="message-circle-off"></i><strong>{{ $hasConversations ? 'No conversations found' : 'No conversations yet' }}</strong><span>{{ $hasConversations ? 'Try another search or filter.' : 'Buyer inquiries and order questions will appear here.' }}</span></div>
            </div>
        </aside>

        @if ($activeConversation)
            <section class="message-thread">
                <header class="message-thread-header"><span class="conversation-avatar chat-presence-target" data-chat-initials data-presence-conversation="{{ $activeConversation['id'] }}" data-presence-users="{{ implode(',', $activeConversation['presence_user_ids'] ?? []) }}">{{ $activeConversation['initials'] }}</span><div><strong data-chat-buyer>{{ $activeConversation['buyer'] }}</strong><small data-chat-member>Member since {{ $activeConversation['member'] }}</small></div><span class="message-order-reference{{ $activeConversation['dispute'] ? ' is-dispute' : '' }}" data-chat-order>{{ $activeConversation['dispute'] ? 'Complaint & Dispute · '.$activeConversation['dispute']['number'] : ($activeConversation['order'] ? 'Order #'.$activeConversation['order'] : 'No active order') }}</span></header>
                <a @if ($activeConversation['product_url']) href="{{ $activeConversation['product_url'] }}" @endif @if (($activeConversation['counterpart_role'] ?? 'buyer') === 'admin') hidden @endif class="message-context-strip{{ $activeConversation['product_url'] ? '' : ' is-static' }}" data-message-context><i data-lucide="info"></i><p>Buyer is asking about <strong data-chat-context-product>{{ $activeConversation['product'] ?? 'this store' }}</strong> · <span data-chat-context-order>{{ $activeConversation['order'] ? 'Order #'.$activeConversation['order'] : 'No active order yet' }}</span></p><i data-lucide="chevron-right" class="message-context-chevron"></i></a>
                <div class="message-thread-scroll" data-message-thread>
                    @php($currentDay = null)
                    @foreach ($activeThread['messages'] ?? [] as $message)
                        @if ($message['day_label'] !== $currentDay)
                            @php($currentDay = $message['day_label'])
                            <div class="message-day"><span>{{ $currentDay }}</span></div>
                        @endif
                        <article class="chat-bubble-row is-{{ $message['from'] === 'me' ? 'seller' : 'buyer' }}" data-message-id="{{ $message['id'] }}">
                            @if ($message['from'] === 'them')<span class="conversation-avatar">{{ $activeConversation['initials'] }}</span>@endif
                            <div>
                                @if ($message['text'] !== '')<p>{{ $message['text'] }}</p>@endif
                                @foreach ($message['attachments'] as $attachment)
                                    @if (!empty($attachment['is_image']))
                                        <a class="chat-attachment is-image" href="{{ $attachment['download_url'] }}" target="_blank" rel="noopener"><img class="chat-attachment-preview" src="{{ $attachment['download_url'] }}" alt="{{ $attachment['name'] }}" loading="lazy"></a>
                                    @elseif (!empty($attachment['is_video']))
                                        <span class="chat-attachment is-video">
                                            <video class="chat-attachment-preview" src="{{ $attachment['download_url'] }}" controls preload="metadata"></video>
                                            <a class="chat-attachment-open" href="{{ $attachment['download_url'] }}" target="_blank" rel="noopener">{{ $attachment['name'] }}</a>
                                        </span>
                                    @else
                                        <a class="chat-attachment" href="{{ $attachment['download_url'] }}" download><i data-lucide="paperclip"></i>{{ $attachment['name'] }}</a>
                                    @endif
                                @endforeach
                                <span class="chat-bubble-meta" data-chat-meta>
                                    <time>{{ $message['time_label'] }}</time>
                                    @if ($message['from'] === 'me' && ! empty($message['receipt']))
                                        @include('partials.chat-receipt', ['receipt' => $message['receipt']])
                                    @endif
                                </span>
                            </div>
                        </article>
                    @endforeach
                    @if (($activeThread['messages'] ?? []) === [])
                        <p class="message-thread-empty">No messages yet. Say hello to start the conversation.</p>
                    @endif
                </div>
                <footer class="message-composer">
                    <div class="quick-reply-list">@foreach ($quickReplies as $reply)<button type="button" data-quick-reply="{{ $reply['text'] }}"><i data-lucide="message-square-text"></i>{{ $reply['label'] }}</button>@endforeach</div>
                    <div class="message-attach-chip" data-message-file-chip hidden></div>
                    <form data-message-form>
                        <button class="message-attach" type="button" data-message-attach aria-label="Attach file"><i data-lucide="paperclip"></i></button>
                        <input type="file" data-message-file hidden accept=".jpg,.jpeg,.png,.webp,.mp4,.mov,.webm,.pdf,.doc,.docx,.xls,.xlsx,.txt,.csv,.zip">
                        <div class="message-field"><textarea rows="1" placeholder="Type a message…" data-message-input></textarea><button class="message-emoji" type="button" data-message-emoji aria-label="Add emoji"><i data-lucide="smile"></i></button></div>
                        <button class="message-send" type="submit"><i data-lucide="send"></i>Send</button>
                    </form>
                    <small class="message-composer-hint">Press Enter to send · Shift + Enter for a new line</small>
                </footer>
            </section>

            <aside class="conversation-details">
                <h3>Conversation details</h3>
                <div class="conversation-buyer"><span class="conversation-avatar" data-detail-initials>{{ $activeConversation['initials'] }}</span><div><strong data-detail-buyer>{{ $activeConversation['buyer'] }}</strong><small>Member since <span data-detail-member>{{ $activeConversation['member'] }}</span></small></div></div>
                <section class="detail-panel-section">
                    <span class="detail-section-label">Order information</span>
                    <div data-detail-order-card>
                        @if ($activeOrder)
                            <div class="detail-order-heading"><strong>Order #{{ $activeOrder['number'] }}</strong>@if ($activeOrder['status_label'])<span>{{ $activeOrder['status_label'] }}</span>@endif</div>
                            @if ($activeOrder['url'])<a class="detail-order-button" href="{{ $activeOrder['url'] }}">Manage orders</a>@endif
                        @else
                            <div class="detail-empty-card"><i data-lucide="package-x"></i><span>No active order for this conversation.</span></div>
                        @endif
                    </div>
                    <div class="detail-dispute-section" data-detail-dispute @if (! $activeDispute) hidden @endif>
                        <span class="detail-section-label">Dispute case</span>
                        <div class="detail-dispute-card" data-detail-dispute-card>
                            @if ($activeDispute)
                                <div class="detail-dispute-heading"><strong>{{ $activeDispute['number'] }}</strong><span>{{ $activeDispute['status_label'] }}</span></div>
                                <strong class="detail-dispute-subject">{{ $activeDispute['subject'] }}</strong>
                                <p class="detail-dispute-description">{{ $activeDispute['description'] }}</p>
                                <dl class="detail-dispute-meta">
                                    <div><dt>Seller Order</dt><dd>{{ $activeDispute['seller_order_no'] ?? '—' }}</dd></div>
                                    <div><dt>Shipment</dt><dd>{{ $activeDispute['shipment_no'] ?? 'Not linked' }}</dd></div>
                                    <div><dt>Order value</dt><dd>{{ $activeDispute['amount_label'] }}</dd></div>
                                </dl>
                            @endif
                        </div>
                    </div>
                    <a class="detail-product-card{{ $activeProduct ? '' : ' is-static' }}" data-detail-product-card @if ($activeProduct) href="{{ $activeProduct['url'] }}" @endif>
                        <span class="detail-product-thumb">@if ($activeProduct && $activeProduct['image_url'])<img src="{{ $activeProduct['image_url'] }}" alt="">@else<i data-lucide="{{ $activeProduct ? 'shirt' : 'messages-square' }}"></i>@endif</span>
                        <span class="detail-product-copy"><strong>{{ $activeProduct['name'] ?? 'Direct message' }}</strong>@if ($activeProduct && $activeProduct['price_label'])<small>{{ $activeProduct['price_label'] }}</small>@endif @if (! $activeProduct)<small>No product linked to this conversation.</small>@endif</span>
                        @if ($activeProduct)<i data-lucide="chevron-right"></i>@endif
                    </a>
                </section>
                <section class="detail-panel-section detail-panel-actions" data-detail-actions @if (! $activeConversation['product_url'] && ! ($activeOrder['url'] ?? null)) hidden @endif>
                    <span class="detail-section-label">Quick actions</span>
                    <div class="conversation-meta-actions" data-detail-actions-body>
                        @if ($activeConversation['product_url'])<a class="meta-row" href="{{ $activeConversation['product_url'] }}"><i data-lucide="external-link"></i><span>Manage product</span><i data-lucide="chevron-right"></i></a>@endif
                        @if ($activeOrder['url'] ?? null)<a class="meta-row" href="{{ $activeOrder['url'] }}"><i data-lucide="receipt"></i><span>Manage orders</span><i data-lucide="chevron-right"></i></a>@endif
                    </div>
                </section>
                <section class="detail-panel-section">
                    <span class="detail-section-label">Activity</span>
                    <div class="conversation-meta-actions"><div class="meta-row"><i data-lucide="history"></i><span>Previous conversations</span><b data-detail-previous>{{ $activeConversation['previous'] }}</b></div></div>
                </section>
            </aside>
        @else
            <section class="message-thread message-thread-blank"><p class="message-thread-empty">No conversations yet. Product inquiries and order questions from buyers will appear here.</p></section>
            <aside class="conversation-details"></aside>
        @endif
    </section>
</section>
@endsection
