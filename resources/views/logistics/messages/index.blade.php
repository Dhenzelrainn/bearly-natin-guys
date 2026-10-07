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
                data-modal-open="new-conversation"
                @if(empty($messageRecipients))
                    disabled
                    title="No eligible messaging contacts are available."
                @endif
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
    </section>

    <section
        class="modal"
        data-modal="new-conversation"
        hidden
    >
        <div class="modal-header">
            <div>
                <h3>
                    Start a conversation
                </h3>

                <p>
                    Contact an Administrator,
                    related Seller, or one of
                    your approved Riders.
                </p>
            </div>

            <button
                class="icon-button"
                type="button"
                data-modal-close
                aria-label="Close new conversation"
            >
                <i data-lucide="x"></i>
            </button>
        </div>

        <form
            class="modal-body"
            data-new-conversation-form
            data-store-url="{{
                route(
                    'logistics.messages.conversations.store'
                )
            }}"
        >
            <div class="field">
                <label for="message-recipient">
                    Recipient
                </label>

                <select
                    id="message-recipient"
                    name="recipient_id"
                    required
                >
                    <option value="">
                        Select recipient
                    </option>

                    @foreach(
                        $messageRecipients
                        as $recipient
                    )
                        <option
                            value="{{ $recipient['id'] }}"
                        >
                            {{ $recipient['label'] }}
                            —
                            {{ $recipient['name'] }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div
                class="field"
                style="margin-top:14px"
            >
                <label for="message-subject">
                    Subject
                </label>

                <input
                    id="message-subject"
                    type="text"
                    name="subject"
                    maxlength="180"
                    placeholder="Optional subject"
                >
            </div>

            <div
                class="field"
                style="margin-top:14px"
            >
                <label for="message-body">
                    Message
                </label>

                <textarea
                    id="message-body"
                    name="message"
                    maxlength="5000"
                    required
                    placeholder="Describe the operational concern"
                ></textarea>
            </div>

            <div class="form-actions">
                <button
                    class="button"
                    type="button"
                    data-modal-close
                >
                    Cancel
                </button>

                <button
                    class="button button-primary"
                    type="submit"
                    data-new-conversation-submit
                >
                    Start conversation
                </button>
            </div>
        </form>
    </section>

    <script>
        window.bearlyLogisticsConversations =
            {{ Illuminate\Support\Js::from(
                $conversationData
            ) }};
    </script>
@endsection
