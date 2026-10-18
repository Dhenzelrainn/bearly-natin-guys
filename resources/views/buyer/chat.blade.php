<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Chat | Bearly</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&family=Material+Symbols+Outlined:wght@400&display=swap" rel="stylesheet">
    @include('buyer.partials.account-context-script')
    @vite(['resources/css/buyer.css','resources/css/bearly-chat.css','resources/js/bearly-chat.js'])
</head>
<body class="bh bearly-chat-page" data-realtime-user-id="{{ auth()->id() }}">
@include('buyer.partials.buyer-header', ['headerActive' => 'chat'])
<main class="bearly-chat-page-shell" data-bearly-chat-root data-chat-mode="page" data-chat-inbox-url="{{ route('buyer.chat.inbox') }}">
    <script type="application/json" data-chat-bootstrap>{!! json_encode($chatBootstrap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) !!}</script>
    <header class="bearly-chat-page-head">
        <div class="bearly-chat-page-title">
            <h1>Messages</h1>
            <p>Talk with your sellers about products and orders.</p>
        </div>
        <div class="bearly-chat-head-tools">
            <label class="bearly-chat-search"><span class="material-symbols-outlined">search</span><input type="search" data-chat-search placeholder="Search conversations..."></label>
            <div class="bearly-chat-filters" role="tablist" aria-label="Conversation filters">
                <button type="button" class="is-active" data-chat-filter="all" role="tab" aria-selected="true">All</button>
                <button type="button" data-chat-filter="unread" role="tab" aria-selected="false">Unread <b data-chat-unread-count>{{ count(array_filter($chatBootstrap['conversations'], fn ($conversation) => ($conversation['unread'] ?? 0) > 0)) }}</b></button>
            </div>
        </div>
    </header>
    <div class="bearly-chat-workspace">
        <aside class="bearly-chat-page-sidebar">
            <div class="bearly-chat-list" data-chat-list></div>
            <div class="bearly-chat-list-empty" data-chat-list-empty hidden>
                <span class="material-symbols-outlined" aria-hidden="true">forum</span>
                <strong data-chat-empty-title>No conversations yet</strong>
                <span data-chat-empty-copy>Open a product or store page and use “Chat with seller” to start one.</span>
            </div>
        </aside>
        <section class="bearly-chat-thread bearly-chat-page-thread">
            <div class="bearly-chat-thread-head" data-chat-thread-head></div>
            <div class="bearly-chat-messages" data-chat-messages></div>
            <div class="bearly-chat-quick" data-chat-quick></div>
            <form class="bearly-chat-composer" data-chat-form>
                <div class="bearly-chat-composer-field">
                    <button type="button" class="bearly-chat-tool" data-chat-attachment-button aria-label="Attach a file">
                        <span class="material-symbols-outlined" aria-hidden="true">attach_file</span>
                    </button>
                    <input type="text" data-chat-input placeholder="Write a message..." autocomplete="off" maxlength="240">
                    <button type="button" class="bearly-chat-tool" data-chat-emoji aria-label="Add emoji">
                        <span class="material-symbols-outlined" aria-hidden="true">sentiment_satisfied</span>
                    </button>
                    <input type="file" data-chat-file hidden accept=".jpg,.jpeg,.png,.webp,.pdf,.doc,.docx,.xls,.xlsx,.txt,.csv,.zip">
                </div>
                <button type="submit" class="bearly-chat-send" aria-label="Send message"><span class="material-symbols-outlined" aria-hidden="true">send</span></button>
            </form>
        </section>
        <aside class="bearly-chat-details" data-chat-details></aside>
    </div>
</main>
<noscript><p class="bearly-chat-noscript">Chat needs JavaScript to load your conversations. Please enable JavaScript and reload this page.</p></noscript>
</body>
</html>
