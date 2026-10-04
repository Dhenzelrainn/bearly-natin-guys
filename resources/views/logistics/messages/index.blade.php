@extends('logistics.layouts.app')

@section('title', 'Messages')
@section('page-title', 'Messages')

@section('content')
    <div class="page-header">
        <div>
            <p class="page-kicker">
                Coordination hub
            </p>

            <h2>
                Operations messaging
            </h2>

            <p>
                Coordinate pickups, delivery runs,
                and approval concerns with Admins,
                Sellers, and Riders.
            </p>
        </div>

        <div class="page-actions">
            <button
                class="button button-primary"
                type="button"
                disabled
                title="New conversations will be enabled after messaging write integration."
            >
                <i data-lucide="square-pen"></i>
                New conversation
            </button>
        </div>
    </div>

    <section class="chat-shell">
        <aside class="chat-sidebar">
            <div class="chat-sidebar-head">
                <div class="search-field">
                    <i data-lucide="search"></i>

                    <input
                        type="search"
                        placeholder="Search conversations"
                        @if(empty($conversations))
                            disabled
                        @endif
                    >
                </div>
            </div>

            @forelse(
                $conversations
                as $index => $conversation
            )
                <button
                    class="conversation {{
                        $index === 0
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

                <div class="row-actions">
                    <button
                        class="icon-button"
                        type="button"
                        title="View contact details"
                        @if(empty($conversations))
                            disabled
                        @endif
                    >
                        <i data-lucide="info"></i>
                    </button>
                </div>
            </header>

            <div
                class="chat-messages"
                data-chat-messages
            >
                @if(empty($conversations))
                    <div class="table-empty">
                        Your Logistics conversations
                        will appear here.
                    </div>
                @endif
            </div>

            <div class="chat-compose">
                <button
                    class="icon-button"
                    type="button"
                    title="Attach file"
                    disabled
                >
                    <i data-lucide="paperclip"></i>
                </button>

                <input
                    type="text"
                    data-chat-input
                    placeholder="Replies will be enabled shortly"
                    disabled
                >

                <button
                    class="button button-primary"
                    type="button"
                    data-chat-send
                    disabled
                >
                    <i data-lucide="send"></i>
                    Send
                </button>
            </div>
        </div>
    </section>

    <script>
        window.bearlyLogisticsConversations =
            {{ Illuminate\Support\Js::from(
                $conversationData
            ) }};
    </script>
@endsection