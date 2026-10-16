@php
    $currentAccountStatus = strtolower($user['status']);
    $primaryStatus = $currentAccountStatus === 'active' ? 'suspended' : 'active';
    $primaryLabel = $primaryStatus === 'active' ? 'Activate' : 'Suspend';
    $primaryIcon = $primaryStatus === 'active' ? 'circle-check-big' : 'pause-circle';
    $menuId = 'user-status-menu-'.$user['database_id'];
    $allowedStatuses = $user['allowed_statuses'];
    $actionAccountName = $user['owner'] ?? $user['manager'] ?? $user['name'];
    $statusActions = [
        'active' => ['Activate account', 'circle-check-big'],
        'suspended' => ['Suspend account', 'pause-circle'],
        'deactivated' => ['Deactivate account', 'user-x'],
    ];
@endphp

<div class="row-actions account-action-group admin-user-row-actions" data-account-actions>
    <button
        type="button"
        class="button button-ghost button-small"
        data-user-primary-action
        data-user-status="{{ $primaryStatus }}"
        aria-label="{{ $primaryLabel }} {{ $actionAccountName }} account"
        @unless (in_array($primaryStatus, $allowedStatuses, true)) hidden @endunless
    >
        <i data-lucide="{{ $primaryIcon }}"></i>
        <span>{{ $primaryLabel }}</span>
    </button>

    <button
        type="button"
        class="icon-button table-more"
        data-registration-menu-toggle="{{ $menuId }}"
        aria-label="More account actions for {{ $actionAccountName }}"
        aria-expanded="false"
    >
        <i data-lucide="ellipsis"></i>
    </button>

    <div
        class="registration-action-menu user-account-menu"
        data-registration-menu="{{ $menuId }}"
        hidden
    >
        @foreach ($statusActions as $targetStatus => [$label, $icon])
            <button
                type="button"
                data-user-status="{{ $targetStatus }}"
                @if (
                    $targetStatus === $currentAccountStatus
                    || ! in_array($targetStatus, $allowedStatuses, true)
                    || $targetStatus === $primaryStatus
                ) hidden @endif
            >
                <i data-lucide="{{ $icon }}"></i>
                <span>{{ $label }}</span>
            </button>
        @endforeach
    </div>
</div>
