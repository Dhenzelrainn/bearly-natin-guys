@php
    $normalizedStatus = strtolower($status);
    $statusLabel = ucfirst($normalizedStatus);
    $statusClass = match ($normalizedStatus) {
        'active' => 'is-active',
        'suspended' => 'is-suspended',
        'deactivated' => 'is-deactivated',
        default => 'is-unknown',
    };
    $initials = collect(preg_split('/\s+/', trim($profileName)) ?: [])
        ->filter()
        ->map(fn (string $part): string => strtoupper(substr($part, 0, 1)))
        ->take(2)
        ->implode('');
    $titleId = 'user-profile-title-'.$modalId;
    $informationId = 'user-profile-information-'.$modalId;
    $descriptionId = 'user-profile-description-'.$modalId;
    $statusIcon = match ($normalizedStatus) {
        'active' => 'circle-check-big',
        'suspended' => 'pause-circle',
        'deactivated' => 'user-x',
        default => 'circle-help',
    };
@endphp

<div class="modal-shell admin-user-profile-shell" data-modal="{{ $modalId }}" hidden>
    <button
        class="modal-backdrop"
        type="button"
        data-close-modal
        aria-label="Close {{ strtolower($profileRole) }} profile"
    ></button>

    <section
        class="modal-card modal-wide admin-user-profile-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="{{ $titleId }}"
        aria-describedby="{{ $descriptionId }}"
        tabindex="-1"
        data-user-status-context
        data-user-id="{{ $userId }}"
        data-user-name="{{ $profileName }}"
        data-status-url="{{ $statusUrl }}"
        data-current-status="{{ $normalizedStatus }}"
        data-allowed-statuses="{{ implode(',', $allowedStatuses) }}"
    >
        <header class="admin-user-profile-heading">
            <div>
                <span class="eyebrow">{{ $profileCode }}</span>
                <h2 id="{{ $titleId }}">{{ $profileTitle }}</h2>
                <p id="{{ $descriptionId }}">{{ $profileDescription }}</p>
            </div>

            <button
                class="icon-button"
                type="button"
                data-close-modal
                aria-label="Close profile"
            >
                <i data-lucide="x"></i>
            </button>
        </header>

        <div class="admin-user-profile-layout">
            <aside class="admin-user-profile-summary" aria-label="Profile summary">
                <div class="admin-user-profile-avatar-wrap">
                    <span class="avatar avatar-large avatar-warm" aria-hidden="true">
                        {{ $initials ?: 'U' }}
                    </span>
                    <span
                        class="admin-user-profile-status-dot {{ $statusClass }}"
                        data-user-status-dot
                        data-status="{{ $normalizedStatus }}"
                        aria-hidden="true"
                    ></span>
                </div>

                <h3>{{ $profileName }}</h3>
                <p>{{ $profileEmail }}</p>

                <span class="role-badge {{ $roleClass }}">
                    <i data-lucide="{{ $roleIcon }}" aria-hidden="true"></i>
                    {{ $profileRole }}
                </span>

                @if (! empty($summaryStats))
                    <div class="admin-user-profile-stats" aria-label="{{ $profileRole }} activity summary">
                        @foreach ($summaryStats as $stat)
                            <div class="admin-user-profile-stat">
                                <i data-lucide="{{ $stat['icon'] }}" aria-hidden="true"></i>
                                <div>
                                    <strong>{{ $stat['value'] }}</strong>
                                    <span>{{ $stat['label'] }}</span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </aside>

            <section class="admin-user-profile-information" aria-labelledby="{{ $informationId }}">
                <div class="admin-user-profile-information-heading">
                    <h3 id="{{ $informationId }}">Account information</h3>
                    <span
                        class="admin-user-status-pill {{ $statusClass }}"
                        data-user-status-indicator
                        data-status="{{ $normalizedStatus }}"
                    >
                        <i data-lucide="{{ $statusIcon }}" aria-hidden="true"></i>
                        <span data-user-current-status>{{ $statusLabel }}</span>
                    </span>
                </div>

                <div class="admin-user-profile-details">
                    @foreach ($details as $detail)
                        <article class="admin-user-profile-detail">
                            <span class="admin-user-profile-detail-icon" aria-hidden="true">
                                <i data-lucide="{{ $detail['icon'] }}"></i>
                            </span>
                            <div>
                                <span>{{ $detail['label'] }}</span>
                                <strong>{{ $detail['value'] }}</strong>
                            </div>
                        </article>
                    @endforeach

                    <article
                        class="admin-user-profile-detail admin-user-profile-status-detail {{ $statusClass }}"
                        data-user-status-card
                        data-status="{{ $normalizedStatus }}"
                    >
                        <span class="admin-user-profile-detail-icon" aria-hidden="true">
                            <i data-lucide="shield-check"></i>
                        </span>
                        <div>
                            <span>Account status</span>
                            <strong data-user-current-status>{{ $statusLabel }}</strong>
                        </div>
                    </article>
                </div>
            </section>
        </div>

        @include('admin.users._profile-status-actions', [
            'status' => $normalizedStatus,
            'allowedStatuses' => $allowedStatuses,
        ])
    </section>
</div>
