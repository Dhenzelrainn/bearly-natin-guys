@php
    $currentAccountStatus = strtolower($status);
    $allowedStatuses = $allowedStatuses ?? [];
    $statusActions = [
        'active' => ['Activate account', 'circle-check-big', 'button-primary'],
        'suspended' => ['Suspend account', 'pause-circle', 'button-secondary'],
        'deactivated' => ['Deactivate account', 'user-x', 'button-danger'],
    ];
@endphp

<div class="modal-footer admin-user-profile-actions">
    @foreach ($statusActions as $targetStatus => [$label, $icon, $buttonClass])
        <button
            type="button"
            class="button {{ $buttonClass }}"
            data-user-status="{{ $targetStatus }}"
            @if (
                $targetStatus === $currentAccountStatus
                || ! in_array($targetStatus, $allowedStatuses, true)
            ) hidden @endif
        >
            <i data-lucide="{{ $icon }}"></i>
            {{ $label }}
        </button>
    @endforeach
</div>
