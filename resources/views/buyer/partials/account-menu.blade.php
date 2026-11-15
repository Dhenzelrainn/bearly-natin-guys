@php
    $accountMenuId = $accountMenuId ?? 'buyer-account-dropdown';
    $headerActive = $headerActive ?? '';
    $headerAvatarId = $headerAvatarId ?? '';
@endphp

<div class="buyer-account-menu" data-buyer-account-menu>
    <a class="account-action{{ $headerActive === 'profile' ? ' active' : '' }}" href="{{ route('buyer.profile') }}" aria-label="Open {{ $buyerName }} profile">
        <span @if ($headerAvatarId) id="{{ $headerAvatarId }}" @endif class="account-avatar{{ $headerAvatarId ? ' navbar-profile-avatar' : '' }}" aria-hidden="true">
            @if (filled($buyerProfilePayload['photo'] ?? null))
                <img class="avatar-photo" src="{{ $buyerProfilePayload['photo'] }}" alt="">
            @else
                <span class="buyer-avatar-initials">{{ $buyerProfilePayload['initials'] ?? 'BY' }}</span>
            @endif
        </span>
        <span class="account-name" data-buyer-account-name>{{ $buyerName }}</span>
    </a>

    <button
        class="buyer-account-trigger"
        type="button"
        data-buyer-account-trigger
        aria-label="Open Buyer account menu"
        aria-expanded="false"
        aria-controls="{{ $accountMenuId }}"
    >
        <span class="material-symbols-outlined account-chevron" aria-hidden="true">expand_more</span>
    </button>

    <div class="buyer-account-dropdown" id="{{ $accountMenuId }}" data-buyer-account-dropdown hidden>
        <span class="buyer-account-menu-notch" aria-hidden="true"></span>
        <div class="buyer-account-menu-identity" data-buyer-account-menu-identity>
            <span class="buyer-account-menu-avatar" data-buyer-account-avatar aria-hidden="true">
                @if (filled($buyerProfilePayload['photo'] ?? null))
                    <img class="avatar-photo" src="{{ $buyerProfilePayload['photo'] }}" alt="">
                @else
                    <span class="buyer-avatar-initials">{{ $buyerProfilePayload['initials'] ?? 'BY' }}</span>
                @endif
            </span>
            <strong data-buyer-account-name>{{ $buyerName }}</strong>
        </div>

        <div class="buyer-account-menu-divider" aria-hidden="true"></div>

        <a href="{{ route('buyer.profile') }}" data-buyer-account-link="profile">
            <span class="material-symbols-outlined" aria-hidden="true">person</span>
            <span>Profile</span>
            <span class="material-symbols-outlined buyer-account-menu-chevron" aria-hidden="true">chevron_right</span>
        </a>
        <a href="{{ route('buyer.profile') }}#addresses" data-buyer-account-link="addresses">
            <span class="material-symbols-outlined" aria-hidden="true">location_on</span>
            <span>Addresses</span>
            <span class="material-symbols-outlined buyer-account-menu-chevron" aria-hidden="true">chevron_right</span>
        </a>
        <a href="{{ route('buyer.profile') }}#likes" data-buyer-account-link="likes">
            <span class="material-symbols-outlined" aria-hidden="true">favorite</span>
            <span>My Likes</span>
            <span class="buyer-account-menu-count" data-buyer-account-likes-count hidden>0</span>
            <span class="material-symbols-outlined buyer-account-menu-chevron" aria-hidden="true">chevron_right</span>
        </a>
        <a href="{{ route('buyer.profile') }}#purchases" data-buyer-account-link="purchases">
            <span class="material-symbols-outlined" aria-hidden="true">receipt_long</span>
            <span>My Purchases</span>
            <span class="material-symbols-outlined buyer-account-menu-chevron" aria-hidden="true">chevron_right</span>
        </a>
        <a href="{{ route('buyer.profile') }}#reviews" data-buyer-account-link="reviews">
            <span class="material-symbols-outlined" aria-hidden="true">star</span>
            <span>Reviews &amp; Ratings</span>
            <span class="material-symbols-outlined buyer-account-menu-chevron" aria-hidden="true">chevron_right</span>
        </a>
        <a href="{{ route('notifications.center', ['source' => 'profile']) }}" data-buyer-account-link="notifications">
            <span class="material-symbols-outlined" aria-hidden="true">notifications</span>
            <span>Notifications</span>
            <span class="buyer-account-notification-dot" data-notification-badge="dot" role="img" aria-label="Unread notifications" hidden></span>
            <span class="material-symbols-outlined buyer-account-menu-chevron" aria-hidden="true">chevron_right</span>
        </a>
        <a href="{{ route('buyer.profile') }}#vouchers" data-buyer-account-link="vouchers">
            <span class="material-symbols-outlined" aria-hidden="true">confirmation_number</span>
            <span>My Vouchers</span>
            <span class="buyer-account-menu-count" data-buyer-account-vouchers-count hidden>0</span>
            <span class="material-symbols-outlined buyer-account-menu-chevron" aria-hidden="true">chevron_right</span>
        </a>
        <a href="{{ route('buyer.profile') }}#help" data-buyer-account-link="help">
            <span class="material-symbols-outlined" aria-hidden="true">help</span>
            <span>Help Center</span>
            <span class="material-symbols-outlined buyer-account-menu-chevron" aria-hidden="true">chevron_right</span>
        </a>

        <div class="buyer-account-menu-divider" aria-hidden="true"></div>

        <form class="buyer-account-logout-form" method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit">
                <span class="material-symbols-outlined" aria-hidden="true">logout</span>
                <span>Log Out</span>
            </button>
        </form>
    </div>
</div>
