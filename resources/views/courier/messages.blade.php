@extends('layouts.courier')

@section('title', 'Chat / Messaging')
@section('page-title', 'Chat / Messaging')

@section('content')
<section class="page-hero">
    <div><span class="eyebrow">Module 09</span><h1>Courier messaging</h1><p>Switch between Buyer, Seller, and Admin support conversations in a static split-screen messaging preview.</p></div>
    <div class="hero-actions"><button class="button button-primary" type="button" data-mock-action="New courier conversation composer opened."><i data-lucide="square-pen"></i> New message</button></div>
</section>

<section class="messaging-shell panel">
    <aside class="conversation-sidebar">
        <div class="conversation-sidebar-head"><div><span class="eyebrow">Inbox</span><h2>Conversations</h2></div><span class="status-badge badge-info">1 unread</span></div>
        <label class="field-with-icon"><i data-lucide="search"></i><input type="search" placeholder="Search conversations..." data-conversation-search></label>
        <div class="conversation-list">
            @foreach($conversations as $index => $conversation)
                <button type="button" class="conversation-item {{ $index===0 ? 'is-active' : '' }}" data-conversation-item data-search="{{ strtolower($conversation['name'].' '.$conversation['role'].' '.$conversation['preview']) }}" data-name="{{ $conversation['name'] }}" data-role="{{ $conversation['role'] }}" data-initials="{{ $conversation['initials'] }}">
                    <span class="avatar avatar-soft">{{ $conversation['initials'] }}</span>
                    <span class="conversation-copy"><span><strong>{{ $conversation['name'] }}</strong><time>{{ $conversation['time'] }}</time></span><small>{{ $conversation['role'] }} • {{ $conversation['preview'] }}</small></span>
                    @if($conversation['unread']>0)<span class="unread-count">{{ $conversation['unread'] }}</span>@endif
                </button>
            @endforeach
        </div>
    </aside>

    <div class="chat-pane">
        <header class="chat-header">
            <div class="identity-cell"><span class="avatar avatar-warm" data-chat-avatar>MR</span><div><strong data-chat-name>Mika Reyes</strong><small><span class="online-dot"></span><span data-chat-role>Buyer</span> • Active now</small></div></div>
            <div class="row-actions"><button class="icon-button" type="button" data-mock-action="Order context opened."><i data-lucide="package-search"></i></button><button class="icon-button" type="button" data-mock-action="Conversation options opened."><i data-lucide="ellipsis-vertical"></i></button></div>
        </header>
        <div class="chat-context"><i data-lucide="bike"></i><span>Courier communication preview • Use chat for delivery coordination and support.</span></div>
        <div class="message-thread" data-message-thread>
            <div class="thread-date">Today</div>
            @foreach($messages as $message)<div class="message-row message-{{ $message['from'] }}"><div class="message-bubble"><p>{{ $message['text'] }}</p><span>{{ $message['time'] }}</span></div></div>@endforeach
        </div>
        <div class="message-composer"><button class="icon-button" type="button" data-mock-action="Attachment picker opened."><i data-lucide="paperclip"></i></button><textarea rows="1" placeholder="Write a message..." data-message-input></textarea><button class="icon-button" type="button" data-mock-action="Quick delivery update menu opened."><i data-lucide="map-pin"></i></button><button class="send-button" type="button" data-send-message><i data-lucide="send"></i></button></div>
    </div>
</section>
@endsection
