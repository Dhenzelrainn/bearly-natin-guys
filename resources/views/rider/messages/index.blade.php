@extends('rider.layouts.app')

@section('title', 'Messages')
@section('page-title', 'Messages')

@section('content')
    <div class="page-header">
        <div>
            <p class="page-kicker">
                Communication hub
            </p>

            <h2>
                Messages
            </h2>

            <p>
                Coordinate pickup handovers and delivery instructions with your
                Logistics Center and Bearly Support. Conversations are started by
                them — replies from you appear in the same thread.
            </p>
        </div>
    </div>

    <section class="chat-shell messages-standard-page" data-standard-role="rider">
        <aside class="chat-sidebar">
            <div class="chat-sidebar-head">
                <div class="search-field">
                    <i data-lucide="search"></i>

                    <input
                        type="search"
                        data-chat-search
                        placeholder="Search conversations"
                        @if(empty($conversations))
                            disabled
                        @endif
                    >
                </div>
            </div>

            @forelse($conversations as $conversation)
                <button
                    class="conversation {{
                        (int) $selectedConversationId === $conversation['id']
                            ? 'is-active'
                            : ''
                    }}"
                    type="button"
                    data-conversation="{{ $conversation['id'] }}"
                    data-name="{{ $conversation['name'] }}"
                    data-role="{{ $conversation['role'] }}"
                    data-unread="{{ $conversation['unread'] }}"
                >
                    <span class="avatar">
                        {{ $conversation['initials'] }}
                    </span>

                    <span class="conversation-copy">
                        <strong>
                            {{ $conversation['name'] }}
                        </strong>

                        <small>
                            {{ $conversation['preview'] }}
                        </small>
                    </span>

                    <span class="conversation-time">
                        {{ $conversation['time'] }}
                    </span>
                </button>
            @empty
                <div class="table-empty">
                    No conversations yet.
                </div>
            @endforelse
        </aside>

        <div class="chat-main">
            <header class="chat-head">
                <span class="chat-person">
                    <strong data-chat-name>
                        @if(empty($conversations))
                            No conversation selected
                        @endif
                    </strong>

                    <small data-chat-role></small>
                </span>
            </header>

            <div
                class="chat-messages"
                data-chat-messages
            >
                @if(empty($conversations))
                    <div class="table-empty">
                        Conversations started by your Logistics Center or
                        Bearly Support will appear here.
                    </div>
                @endif
            </div>

            <div class="chat-compose">
                <button
                    class="icon-button"
                    type="button"
                    data-chat-attachment-button
                    title="Attach a file"
                    @if(empty($conversations))
                        disabled
                    @endif
                >
                    <i data-lucide="paperclip"></i>
                </button>

                <input
                    type="file"
                    data-chat-attachment
                    accept=".jpg,.jpeg,.png,.webp,.pdf,.doc,.docx,.xls,.xlsx,.txt,.csv,.zip"
                    hidden
                    @if(empty($conversations))
                        disabled
                    @endif
                >

                <span
                    class="chat-selected-file"
                    data-chat-attachment-name
                    hidden
                ></span>

                <input
                    type="text"
                    data-chat-input
                    placeholder="Write a message…"
                    @if(empty($conversations))
                        disabled
                    @endif
                >

                <button
                    class="button button-primary"
                    type="button"
                    data-chat-send
                    @if(empty($conversations))
                        disabled
                    @endif
                >
                    <i data-lucide="send"></i>
                    Send
                </button>
            </div>
        </div>

        <aside class="messages-standard-details" data-standard-details>
            <h3>Conversation details</h3>
            <div data-standard-details-body>
                <div class="table-empty">Select a conversation to see its details.</div>
            </div>
        </aside>
    </section>

    <script>
        window.bearlyRiderConversations =
            {{ Illuminate\Support\Js::from($conversationData) }};

        window.bearlyRiderSelectedConversation =
            {{ Illuminate\Support\Js::from($selectedConversationId) }};
    </script>
@endsection
