{{--
    Shared full-page Notification Center body.

    Each role wrapper supplies its own layout, heading copy, links, and
    category tabs. Filtering, search, unread state, and pagination are handled
    by notification-center-page.js against the authenticated user's own
    /notifications history.
--}}
@php
    $links = $links ?? [];
    $isBuyer = $role === 'buyer';
    $titleTag = $titleTag ?? (in_array($role, ['buyer', 'admin'], true) ? 'h1' : 'h2');
    $emptyTitle = $emptyTitle ?? 'No notifications yet';
    $emptyBody = $emptyBody ?? 'New Bearly updates will appear here.';
    $emptyFilteredTitle = $emptyFilteredTitle ?? 'No notifications match';
    $emptyFilteredBody = $emptyFilteredBody ?? 'Try another filter or search term.';
@endphp

<section
    class="nc-page nc-page--{{ $role }}"
    data-notification-center-page
    data-skin="{{ $role }}"
    data-per-page="{{ $perPage }}"
    data-empty-title="{{ $emptyTitle }}"
    data-empty-body="{{ $emptyBody }}"
    data-empty-filtered-title="{{ $emptyFilteredTitle }}"
    data-empty-filtered-body="{{ $emptyFilteredBody }}"
    aria-labelledby="nc-title"
>
    <header class="nc-head">
        <div class="nc-head-copy">
            <{{ $titleTag }} id="nc-title">{{ $heading }}</{{ $titleTag }}>
            <p>{{ $description }}</p>
        </div>

        <div class="nc-head-actions">
            @foreach ($links as $link)
                <a class="nc-link" href="{{ $link['url'] }}">
                    @if ($isBuyer)
                        <span class="material-symbols-outlined" aria-hidden="true">{{ $link['icon'] ?? 'arrow_forward' }}</span>
                    @else
                        <i data-lucide="{{ $link['icon'] ?? 'arrow-up-right' }}" aria-hidden="true"></i>
                    @endif
                    <span>{{ $link['label'] }}</span>
                </a>
            @endforeach

            <button type="button" class="nc-mark-all" data-nc-mark-all hidden>
                @if ($isBuyer)
                    <span class="material-symbols-outlined" aria-hidden="true">mark_email_read</span>
                @else
                    <i data-lucide="check-check" aria-hidden="true"></i>
                @endif
                <span>Mark all as read</span>
            </button>
        </div>
    </header>

    <div class="nc-toolbar">
        <div class="nc-tabs" role="tablist" aria-label="Notification filters">
            @foreach ($tabs as $tab)
                <button
                    type="button"
                    role="tab"
                    id="nc-tab-{{ $tab['key'] }}"
                    class="nc-tab{{ $loop->first ? ' is-active' : '' }}"
                    data-nc-category="{{ $tab['key'] }}"
                    aria-selected="{{ $loop->first ? 'true' : 'false' }}"
                    aria-controls="nc-list"
                    tabindex="{{ $loop->first ? '0' : '-1' }}"
                >{{ $tab['label'] }}</button>
            @endforeach
        </div>

        <div class="nc-search">
            <label class="nc-sr" for="nc-search">Search notifications</label>
            @if ($isBuyer)
                <span class="material-symbols-outlined" aria-hidden="true">search</span>
            @else
                <i data-lucide="search" aria-hidden="true"></i>
            @endif
            <input id="nc-search" type="search" placeholder="Search notifications" autocomplete="off" maxlength="100" data-nc-search>
        </div>
    </div>

    <p class="nc-sr" data-nc-status role="status" aria-live="polite"></p>

    <div class="nc-list" id="nc-list" data-nc-list role="tabpanel" aria-label="Notifications" aria-busy="false"></div>

    <div class="nc-empty" data-nc-empty hidden>
        <h2 data-nc-empty-title></h2>
        <p data-nc-empty-body></p>
    </div>

    <div class="nc-empty nc-error" data-nc-error hidden>
        <h2>Couldn&rsquo;t load notifications</h2>
        <p>Something went wrong while loading your notifications. Check your connection and try again.</p>
        <button type="button" class="nc-retry" data-nc-retry>Try again</button>
    </div>

    <nav class="nc-pager" data-nc-pager hidden aria-label="Notification pages">
        <button type="button" class="nc-page-button" data-nc-prev>Previous</button>
        <span class="nc-page-info" data-nc-page-info></span>
        <button type="button" class="nc-page-button" data-nc-next>Next</button>
    </nav>
</section>
