@extends('layouts.seller')
@section('title', 'Messages')
@section('page-title', 'Customer Service')
@section('content')
@php($activeConversation = $conversations[0])
<section class="customer-messages-page" data-message-workspace>
    <header class="customer-service-heading">
        <div><span class="section-kicker">Buyer communication</span><h2>Messages</h2><p>Talk with buyers about products and active orders.</p></div>
        <div class="message-heading-actions"><label class="seller-availability"><i></i><select aria-label="Seller availability" data-seller-availability><option>Available</option><option>Away</option></select></label><span class="message-unread-total"><strong data-total-unread>5</strong> unread</span></div>
    </header>

    <section class="message-workspace">
        <aside class="message-inbox">
            <div class="message-inbox-heading"><h3>Inbox</h3><button type="button" aria-label="Inbox options"><i data-lucide="list-filter"></i></button></div>
            <label class="message-search"><i data-lucide="search"></i><input type="search" placeholder="Search buyers or orders" data-message-search></label>
            <div class="message-filter-tabs" role="tablist"><button class="is-active" type="button" data-message-filter="all">All</button><button type="button" data-message-filter="unread">Unread <b>5</b></button><button type="button" data-message-filter="order">Orders</button></div>
            <div class="conversation-list">
                @foreach ($conversations as $conversation)
                    <button class="conversation-row {{ $loop->first ? 'is-active' : '' }}" type="button" data-conversation data-search="{{ strtolower($conversation['buyer'].' '.$conversation['context'].' '.$conversation['last']) }}" data-type="{{ $conversation['type'] }}" data-unread="{{ $conversation['unread'] }}" data-payload='@json($conversation)'>
                        <span class="conversation-avatar">{{ $conversation['initials'] }}</span><span class="conversation-copy"><span><strong>{{ $conversation['buyer'] }}</strong><time>{{ $conversation['time'] }}</time></span><em>{{ $conversation['last'] }}</em><small>{{ $conversation['context'] }}</small></span>@if ($conversation['unread'])<b class="conversation-unread">{{ $conversation['unread'] }}</b>@endif
                    </button>
                @endforeach
                <div class="conversation-empty" data-conversation-empty hidden><i data-lucide="message-circle-off"></i><strong>No conversations found</strong><span>Try another search or filter.</span></div>
            </div>
        </aside>

        <section class="message-thread">
            <header class="message-thread-header"><span class="conversation-avatar" data-chat-initials>{{ $activeConversation['initials'] }}</span><div><strong data-chat-buyer>{{ $activeConversation['buyer'] }}</strong><small data-chat-active>{{ $activeConversation['active'] }}</small></div><span class="message-order-reference" data-chat-order>{{ $activeConversation['order'] }}</span><button type="button" aria-label="Search conversation"><i data-lucide="search"></i></button><button type="button" aria-label="Conversation options"><i data-lucide="ellipsis-vertical"></i></button></header>
            <div class="message-context-strip"><i data-lucide="info"></i><p>Buyer is asking about <strong data-chat-context-product>{{ $activeConversation['product'] }}</strong> · <span data-chat-context-order>{{ $activeConversation['order'] }}</span></p><a href="{{ route('seller.orders') }}">View order<i data-lucide="external-link"></i></a></div>
            <div class="message-thread-scroll" data-message-thread><div class="message-day"><span>Today</span></div>@foreach ($activeConversation['messages'] as $message)<article class="chat-bubble-row is-{{ $message['from'] }}">@if ($message['from'] === 'buyer')<span class="conversation-avatar">{{ $activeConversation['initials'] }}</span>@endif<div><p>{{ $message['text'] }}</p><time>{{ $message['time'] }}@if ($message['from'] === 'seller')<i data-lucide="check-check"></i>@endif</time></div></article>@endforeach</div>
            <footer class="message-composer"><div class="quick-reply-list">@foreach ($quickReplies as $reply)<button type="button" data-quick-reply="{{ $reply['text'] }}"><i data-lucide="message-square-text"></i>{{ $reply['label'] }}</button>@endforeach</div><form data-message-form><button class="message-attach" type="button" data-message-attach aria-label="Attach file"><i data-lucide="paperclip"></i></button><label><textarea rows="2" placeholder="Write a reply…" data-message-input></textarea><small>Enter to send · Shift + Enter for a new line</small></label><button class="message-send" type="submit"><i data-lucide="send"></i>Send</button></form></footer>
        </section>

        <aside class="conversation-details">
            <h3>Conversation details</h3><div class="conversation-buyer"><span class="conversation-avatar" data-detail-initials>{{ $activeConversation['initials'] }}</span><div><strong data-detail-buyer>{{ $activeConversation['buyer'] }}</strong><small>Member since <span data-detail-member>{{ $activeConversation['member'] }}</span></small></div></div>
            <section><span class="detail-section-label">Active context</span><div class="detail-order-heading"><strong data-detail-order>{{ $activeConversation['order'] }}</strong><span data-detail-status>{{ $activeConversation['status'] }}</span></div><div class="detail-product"><span><i data-lucide="shirt"></i></span><div><strong data-detail-product>{{ $activeConversation['product'] }}</strong><small data-detail-variant>{{ $activeConversation['variant'] }}</small><b data-detail-price>{{ $activeConversation['price'] }}</b></div></div><a class="detail-order-button" href="{{ route('seller.orders') }}">Open full order</a></section>
            <section class="conversation-meta-actions"><button type="button"><span>Previous conversations</span><b data-detail-previous>{{ $activeConversation['previous'] }}</b><i data-lucide="chevron-right"></i></button><button class="is-danger" type="button" data-report-conversation><i data-lucide="flag"></i>Report conversation</button></section>
        </aside>
    </section>
</section>
@endsection
