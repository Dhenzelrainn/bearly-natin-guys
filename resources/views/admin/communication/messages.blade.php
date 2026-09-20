@extends('layouts.admin')

@section('title', 'Chat / Messaging')
@section('page-title', 'Chat / Messaging')

@section('content')

<section class="page-hero message-hero">
    <div>
        <span class="eyebrow">Communication</span>

        <h1>Chat and messaging</h1>

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
                <span class="eyebrow">Inbox</span>
                <h2>Conversations</h2>
            </div>

            <span
                class="status-badge badge-info"
                data-unread-summary
                @if(collect($conversations)->sum('unread') === 0) hidden @endif
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
            >
        </label>


        <div
            class="conversation-list"
            data-conversation-list
        >
            @foreach ($conversations as $index => $conversation)

                <button
                    type="button"
                    class="conversation-item {{ $index === 0 ? 'is-active' : '' }}"
                    data-conversation-item
                    data-conversation-id="{{ \Illuminate\Support\Str::slug($conversation['name']) }}"
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

                            <span aria-hidden="true">•</span>

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
                    {{ $conversations[0]['initials'] ?? '—' }}
                </span>


                <div>
                    <strong data-chat-name>
                        {{ $conversations[0]['name'] ?? 'Select a conversation' }}
                    </strong>

                    <small>
                        <span data-chat-role>
                            {{ $conversations[0]['role'] ?? 'Support' }}
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
                >
                    <i data-lucide="info"></i>
                </button>


                <button
                    class="icon-button"
                    type="button"
                    data-chat-mark-unread
                    aria-label="Mark conversation unread"
                    title="Mark unread"
                >
                    <i data-lucide="mail-plus"></i>
                </button>

            </div>

        </header>


        <div class="chat-context">
            <i data-lucide="shield-check"></i>

            <span>
                Admin support conversation • Messages in this front-end demo
                are saved locally in this browser.
            </span>
        </div>


        <div
            class="message-thread"
            data-message-thread
        >

            <div class="thread-date">
                Today
            </div>


            @foreach ($messages as $message)

                <div class="message-row message-{{ $message['from'] }}">

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

        </div>


        {{-- Composer --}}
        <div class="message-composer">

            <button
                class="icon-button"
                type="button"
                data-chat-attachment-button
                aria-label="Attach a file"
                title="Attach file"
            >
                <i data-lucide="paperclip"></i>
            </button>


            <input
                type="file"
                data-chat-attachment
                hidden
            >


            <div class="message-input-wrap">

                <textarea
                    rows="1"
                    placeholder="Write a message..."
                    data-message-input
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
            >
                <i data-lucide="smile"></i>
            </button>


            <button
                class="send-button"
                type="button"
                data-send-message
                aria-label="Send message"
                title="Send message"
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
            <span>Recipient name</span>

            <input
                type="text"
                placeholder="Name or business"
                data-new-chat-name
            >
        </label>


        <label class="form-field">
            <span>Role</span>

            <select data-new-chat-role>
                <option value="Buyer">
                    Buyer
                </option>

                <option value="Seller">
                    Seller
                </option>

                <option value="Logistics Center">
                    Logistics Center
                </option>

                <option value="Rider">
                    Rider
                </option>
            </select>
        </label>


        <label class="form-field">
            <span>First message</span>

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
            >
                <i data-lucide="send"></i>
                Start conversation
            </button>

        </div>

    </section>

</div>

@endsection