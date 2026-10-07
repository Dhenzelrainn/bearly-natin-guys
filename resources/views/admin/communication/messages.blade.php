@extends('layouts.admin')

@section('title', 'Chat / Messaging')

@section('page-title', 'Chat / Messaging')

@section('content')

@php
    $hasConversations = count($conversations) > 0;

    $active = $activeConversation ?? ($conversations[0] ?? null);

    $activeInitials = $active['initials'] ?? '—';
    $activeName = $active['name'] ?? 'No conversation selected';
    $activeRole = $active['role'] ?? 'Support';
@endphp


<section class="page-hero message-hero">

    <div>

        <span class="eyebrow">
            Communication
        </span>

        <h1>
            Chat and messaging
        </h1>

        <p>
            Use a split-screen support inbox for Admin conversations with
            Buyers, Sellers, Logistics Centers, and Riders.
        </p>

    </div>


    <div class="hero-actions">

        <button
            class="button button-primary"
            type="button"
            data-open-modal="new-conversation"
        >
            <i data-lucide="square-pen"></i>

            New message
        </button>

    </div>

</section>



<section class="messaging-shell panel">

    {{-- Conversation sidebar --}}
    <aside class="conversation-sidebar">

        <div class="conversation-sidebar-head">

            <div>

                <span class="eyebrow">
                    Inbox
                </span>

                <h2>
                    Conversations
                </h2>

            </div>


            <span
                class="status-badge badge-info"
                data-unread-summary
                @if (collect($conversations)->sum('unread') === 0)
                    hidden
                @endif
            >
                {{ collect($conversations)->sum('unread') }} unread
            </span>

        </div>


        <label class="field-with-icon">

            <i data-lucide="search"></i>

            <input
                type="search"
                placeholder="Search conversations..."
                data-conversation-search
                @if (! $hasConversations) disabled @endif
            >

        </label>


        <div
            class="conversation-list"
            data-conversation-list
        >

            @foreach ($conversations as $index => $conversation)

                <button
                    type="button"
                    class="conversation-item {{
                        (
                            $activeConversation
                            && (int) $activeConversation['database_id']
                                === (int) $conversation['database_id']
                        )
                            ? 'is-active'
                            : ''
                    }}"
                    data-conversation-item
                    data-conversation-id="{{ $conversation['database_id'] }}"
                    data-search="{{
                        strtolower(
                            $conversation['name'].' '.
                            $conversation['role'].' '.
                            $conversation['preview']
                        )
                    }}"
                    data-name="{{ $conversation['name'] }}"
                    data-role="{{ $conversation['role'] }}"
                    data-initials="{{ $conversation['initials'] }}"
                    data-preview="{{ $conversation['preview'] }}"
                    data-time="{{ $conversation['time'] }}"
                >

                    <span class="avatar avatar-soft">
                        {{ $conversation['initials'] }}
                    </span>


                    <span class="conversation-copy">

                        <span class="conversation-meta">

                            <strong>
                                {{ $conversation['name'] }}
                            </strong>

                            <time>
                                {{ $conversation['time'] }}
                            </time>

                        </span>


                        <small class="conversation-preview">

                            <span class="conversation-role">
                                {{ $conversation['role'] }}
                            </span>

                            <span aria-hidden="true">
                                •
                            </span>

                            <span>
                                {{ $conversation['preview'] }}
                            </span>

                        </small>

                    </span>


                    @if ($conversation['unread'] > 0)

                        <span class="unread-count">
                            {{ $conversation['unread'] }}
                        </span>

                    @endif

                </button>

            @endforeach


            @if (! $hasConversations)

                <div class="table-empty">

                    <i data-lucide="messages-square"></i>

                    <strong>
                        No conversations yet
                    </strong>

                    <span>
                        Admin support conversations will appear here once
                        messaging records exist.
                    </span>

                </div>

            @endif

        </div>

    </aside>



    {{-- Active chat --}}
    <div class="chat-pane">

        <header class="chat-header">

            <div class="identity-cell">

                <span
                    class="avatar avatar-warm"
                    data-chat-avatar
                >
                    {{ $activeInitials }}
                </span>


                <div>

                    <strong data-chat-name>
                        {{ $activeName }}
                    </strong>

                    <small>

                        <span data-chat-role>
                            {{ $activeRole }}
                        </span>

                        <span>
                            • Support conversation
                        </span>

                    </small>

                </div>

            </div>


            <div class="row-actions">

                <button
                    class="icon-button"
                    type="button"
                    data-chat-details
                    aria-label="Show conversation details"
                    title="Conversation details"
                    @if (! $hasConversations) disabled @endif
                >
                    <i data-lucide="info"></i>
                </button>


                <button
                    class="icon-button"
                    type="button"
                    data-chat-mark-unread
                    aria-label="Mark conversation unread"
                    title="Mark unread"
                    @if (! $hasConversations) disabled @endif
                >
                    <i data-lucide="mail-plus"></i>
                </button>

            </div>

        </header>


        <div class="chat-context">

            <i data-lucide="shield-check"></i>

            <span>
                Admin support conversation • Messages shown here are loaded
                from the Bearly messaging database.
            </span>

        </div>


        <div
            class="message-thread"
            data-message-thread
        >

            @if ($hasConversations)

                <div class="thread-date">
                    Conversation
                </div>


                @foreach ($messages as $message)

                    <div
                        class="message-row
                        message-{{ $message['from'] }}"
                    >

                        <div class="message-bubble">

                            <p>
                                {{ $message['text'] }}
                            </p>

                            <span>
                                {{ $message['time'] }}
                            </span>

                        </div>

                    </div>

                @endforeach


                @if (empty($messages))

                    <div class="table-empty">

                        <i data-lucide="message-circle"></i>

                        <strong>
                            No messages yet
                        </strong>

                        <span>
                            This conversation has not received any messages yet.
                        </span>

                    </div>

                @endif

            @else

                <div class="table-empty">

                    <i data-lucide="message-square-off"></i>

                    <strong>
                        Select a conversation
                    </strong>

                    <span>
                        There are currently no support conversations available.
                    </span>

                </div>

            @endif

        </div>



        {{-- Composer --}}
        <div class="message-composer">

            <button
                class="icon-button"
                type="button"
                data-chat-attachment-button
                aria-label="Attach a file"
                title="Attach file"
                @if (! $hasConversations) disabled @endif
            >
                <i data-lucide="paperclip"></i>
            </button>


            <input
                type="file"
                data-chat-attachment
                hidden
                @if (! $hasConversations) disabled @endif
            >


            <div class="message-input-wrap">

                <textarea
                    rows="1"
                    placeholder="{{
                        $hasConversations
                            ? 'Write a message...'
                            : 'No active conversation'
                    }}"
                    data-message-input
                    @if (! $hasConversations) disabled @endif
                ></textarea>


                <span
                    class="attachment-preview"
                    data-chat-attachment-name
                    hidden
                ></span>

            </div>


            <button
                class="icon-button"
                type="button"
                data-chat-emoji
                aria-label="Insert emoji"
                title="Insert emoji"
                @if (! $hasConversations) disabled @endif
            >
                <i data-lucide="smile"></i>
            </button>


            <button
                class="send-button"
                type="button"
                data-send-message
                aria-label="Send message"
                title="Send message"
                @if (! $hasConversations) disabled @endif
            >
                <i data-lucide="send"></i>
            </button>

        </div>

    </div>

</section>



{{-- New conversation modal --}}
<div
    class="modal-shell"
    data-modal="new-conversation"
    hidden
>

    <button
        class="modal-backdrop"
        type="button"
        data-close-modal
        aria-label="Close new conversation modal"
    ></button>


    <section
        class="modal-card"
        role="dialog"
        aria-modal="true"
        aria-labelledby="new-conversation-title"
    >

        <div class="modal-heading">

            <div>

                <span class="eyebrow">
                    New support thread
                </span>

                <h2 id="new-conversation-title">
                    Start a conversation
                </h2>

            </div>


            <button
                class="icon-button"
                type="button"
                data-close-modal
                aria-label="Close"
            >
                <i data-lucide="x"></i>
            </button>

        </div>


        <label class="form-field">

            <span>
                Recipient
            </span>

            <select
                data-new-chat-recipient
                @if (empty($recipients)) disabled @endif
            >

                <option value="">
                    Select a user
                </option>

                @foreach ($recipients as $recipient)

                    <option
                        value="{{ $recipient['id'] }}"
                        data-role="{{ $recipient['role'] }}"
                    >
                        {{ $recipient['name'] }}
                        — {{ ucfirst($recipient['role']) }}
                    </option>

                @endforeach

            </select>

            @if (empty($recipients))

                <small class="field-hint">
                    No active Buyer, Seller, Logistics, or Rider accounts are available yet.
                </small>

            @endif

        </label>


        <label class="form-field">

            <span>
                First message
            </span>

            <textarea
                rows="5"
                placeholder="Write your message..."
                data-new-chat-message
            ></textarea>

        </label>


        <div class="modal-footer">

            <button
                class="button button-secondary"
                type="button"
                data-close-modal
            >
                Cancel
            </button>


            <button
                class="button button-primary"
                type="button"
                data-create-conversation
                @if (empty($recipients)) disabled @endif
            >
                <i data-lucide="send"></i>

                Start conversation
            </button>

        </div>

    </section>

</div>



{{-- Messaging endpoint configuration for admin.js --}}
<script
    type="application/json"
    id="admin-message-config"
>
{!! json_encode([
    'create_url' => route(
        'admin.messages.conversations.store'
    ),
], JSON_UNESCAPED_SLASHES) !!}
</script>


{{-- Database-backed conversation payload for admin.js --}}
<script
    type="application/json"
    id="admin-conversation-data"
>
{!! json_encode(
    collect($conversations)->mapWithKeys(function ($conversation) {
        return [
            (string) $conversation['database_id'] => [
                'database_id' => $conversation['database_id'],

                'id' => $conversation['id'],

                'name' => $conversation['name'],

                'role' => $conversation['role'],

                'preview' => $conversation['preview'],

                'time' => $conversation['time'],

                'unread' => $conversation['unread'],

                'initials' => $conversation['initials'],

                'subject' => $conversation['subject'] ?? null,

                'status' => $conversation['status'] ?? 'open',

                'type' => $conversation['type'] ?? 'direct',

                'send_url' => route(
                    'admin.messages.send',
                    $conversation['database_id']
                ),

                'read_url' => route(
                    'admin.messages.read',
                    $conversation['database_id']
                ),

                'unread_url' => route(
                    'admin.messages.unread',
                    $conversation['database_id']
                ),

                'messages' => $conversation['messages'] ?? [],
            ],
        ];
    }),
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
) !!}
</script>


@endsection