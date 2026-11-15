@php
    $assistantAvatar = asset('images/chatbotbearly-head.png').'?v='.filemtime(public_path('images/chatbotbearly-head.png'));
@endphp

<div
    class="bearly-assistant"
    data-bearly-assistant
    data-endpoint="{{ route('chatbot.message', [], false) }}"
    data-assistant-avatar="{{ $assistantAvatar }}"
>
    <button
        class="bearly-assistant-launcher"
        type="button"
        aria-label="Open Bearly shopping assistant"
        aria-expanded="false"
        aria-controls="bearly-assistant-panel"
        data-assistant-open
    >
        <img
            class="bearly-assistant-launcher-icon"
            src="{{ $assistantAvatar }}"
            alt=""
            width="72"
            height="72"
            data-assistant-icon
        >
        <span class="bearly-assistant-icon-fallback" aria-hidden="true" data-assistant-icon-fallback hidden>B</span>
    </button>

    <section
        class="bearly-assistant-panel"
        id="bearly-assistant-panel"
        role="dialog"
        aria-modal="false"
        aria-labelledby="bearly-assistant-title"
        aria-hidden="true"
        data-assistant-panel
        hidden
    >
        <header class="bearly-assistant-header">
            <div class="bearly-assistant-header-brand">
                <img
                    class="bearly-assistant-header-avatar"
                    src="{{ $assistantAvatar }}"
                    alt=""
                    width="58"
                    height="58"
                >
                <span class="bearly-assistant-header-divider" aria-hidden="true"></span>
                <div class="bearly-assistant-header-copy">
                    <h2 class="bearly-assistant-header-title" id="bearly-assistant-title">Bearly Assistant</h2>
                    <p class="bearly-assistant-header-subtitle">
                        <span class="bearly-assistant-header-dot" aria-hidden="true"></span>
                        <span>Shopping help · AI assistant</span>
                    </p>
                </div>
            </div>
            <div class="bearly-assistant-header-actions">
                <button
                    class="bearly-assistant-header-action"
                    type="button"
                    aria-label="Minimize assistant"
                    data-assistant-minimize
                >
                    <span aria-hidden="true">−</span>
                </button>
                <button
                    class="bearly-assistant-header-action"
                    type="button"
                    aria-label="Close assistant"
                    data-assistant-close
                >
                    <span aria-hidden="true">×</span>
                </button>
            </div>
        </header>

        <div class="bearly-assistant-disclosure" data-assistant-disclosure role="note">
            <span class="bearly-assistant-disclosure-icon" aria-hidden="true">i</span>
            <p class="bearly-assistant-disclosure-copy">
                Bearly Assistant provides general shopping help and product information. Messages and relevant public product details may be sent to Google Gemini to generate replies. Bearly does not save chatbot conversations. Please don’t share passwords, verification codes, order details, or payment information.
            </p>
        </div>

        <div class="bearly-assistant-messages" data-assistant-messages role="log" aria-live="polite" aria-relevant="additions text">
            <div class="bearly-assistant-message-row">
                <img
                    class="bearly-assistant-avatar"
                    src="{{ $assistantAvatar }}"
                    alt="Bearly assistant"
                    width="32"
                    height="32"
                >
                <p class="bearly-assistant-message bearly-assistant-message--bot" data-assistant-intro>{{ config('bearly_chatbot.welcome') }}</p>
            </div>

            <section class="bearly-assistant-quick-help" data-assistant-quick-help aria-labelledby="bearly-assistant-quick-help-title">
                <h3 class="bearly-assistant-quick-help-title" id="bearly-assistant-quick-help-title">Quick Help</h3>
                <p class="bearly-assistant-sr-only">Choose a topic to add a question to the message box.</p>
                <div class="bearly-assistant-quick-help-grid">
                    <button
                        class="bearly-assistant-quick-help-button"
                        type="button"
                        data-assistant-suggestion="Where is my order?"
                    >
                        <svg class="bearly-assistant-quick-help-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                            <path d="m12 3 9 4.8v8.4L12 21l-9-4.8V7.8L12 3Z" />
                            <path d="m3.4 7.9 8.6 4.7 8.6-4.7M12 12.6V21M8 5.2l9 4.8" />
                        </svg>
                        <span>Track my order</span>
                        <svg class="bearly-assistant-quick-help-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                            <path d="m9 18 6-6-6-6" />
                        </svg>
                    </button>

                    <button
                        class="bearly-assistant-quick-help-button"
                        type="button"
                        data-assistant-suggestion="How can I find shipping information?"
                    >
                        <svg class="bearly-assistant-quick-help-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                            <path d="M3 6h11v11H3zM14 10h4l3 3v4h-7z" />
                            <circle cx="7.5" cy="18" r="2" />
                            <circle cx="18" cy="18" r="2" />
                        </svg>
                        <span>Shipping info</span>
                        <svg class="bearly-assistant-quick-help-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                            <path d="m9 18 6-6-6-6" />
                        </svg>
                    </button>

                    <button
                        class="bearly-assistant-quick-help-button"
                        type="button"
                        data-assistant-suggestion="How can I find current vouchers and deals on Bearly?"
                    >
                        <svg class="bearly-assistant-quick-help-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                            <path d="m3.5 8 13-3.5 4 14-13 3.5-4-14Z" />
                            <path d="m9 16 6-6M9 10h.01M15 16h.01" />
                        </svg>
                        <span>Vouchers &amp; deals</span>
                        <svg class="bearly-assistant-quick-help-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                            <path d="m9 18 6-6-6-6" />
                        </svg>
                    </button>

                    <button
                        class="bearly-assistant-quick-help-button"
                        type="button"
                        data-assistant-suggestion="How do returns and refunds work?"
                    >
                        <svg class="bearly-assistant-quick-help-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                            <path d="M3 11a9 9 0 1 1 2.7 6.4M3 4v7h7" />
                        </svg>
                        <span>Return &amp; Refund</span>
                        <svg class="bearly-assistant-quick-help-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                            <path d="m9 18 6-6-6-6" />
                        </svg>
                    </button>
                </div>
            </section>
        </div>

        <div class="bearly-assistant-bottom">
            <form class="bearly-assistant-form" data-assistant-form>
                <label class="bearly-assistant-sr-only" for="bearly-assistant-input">Your message</label>
                <textarea
                    id="bearly-assistant-input"
                    name="message"
                    rows="1"
                    maxlength="1000"
                    placeholder="Ask about Bearly or a product…"
                    autocomplete="off"
                    required
                    data-assistant-input
                ></textarea>
                <button class="bearly-assistant-send" type="submit" aria-label="Send message" data-assistant-send>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                        <path d="m22 2-7 20-4-9-9-4 20-7Z" />
                        <path d="M22 2 11 13" />
                    </svg>
                </button>
            </form>
            <a class="bearly-assistant-contact" href="{{ route('contact') }}">Contact Bearly Support</a>
        </div>
    </section>
</div>

@vite('resources/js/bearly-chatbot.js')
