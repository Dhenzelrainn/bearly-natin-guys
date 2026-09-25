@foreach ([
    'active' => ['button-primary', 'circle-check', 'Activate'],
    'suspended' => ['button-danger-soft', 'pause-circle', 'Suspend'],
    'deactivated' => ['button-danger', 'user-x', 'Deactivate'],
] as $targetStatus => [$buttonClass, $icon, $label])
    @if (in_array($targetStatus, $user['allowed_statuses'], true))
        <form method="POST" action="{{ route('admin.users.status', $user['database_id']) }}" style="display: contents" onsubmit="const reason = prompt('Reason for changing this account to {{ $targetStatus }}:'); if (!reason) return false; this.querySelector('[name=reason]').value = reason;">
            @csrf
            @method('PATCH')
            <input type="hidden" name="status" value="{{ $targetStatus }}">
            <input type="hidden" name="reason" value="">
            <button type="submit" class="button {{ $buttonClass }}"><i data-lucide="{{ $icon }}"></i> {{ $label }}</button>
        </form>
    @endif
@endforeach
