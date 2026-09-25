@extends('layouts.admin')

@section('title', $user->name)
@section('page-title', 'User Details')

@section('content')
<section class="page-hero">
    <div><span class="eyebrow">User Management</span><h1>{{ $user->name }}</h1><p>Review identity, role information, application history, and account access.</p></div>
    <div class="hero-actions"><a class="button button-ghost" href="{{ route('admin.users') }}"><i data-lucide="arrow-left"></i> Back to users</a></div>
</section>

<section class="panel">
    <div class="review-grid">
        <div class="review-profile">
            <span class="avatar avatar-large avatar-warm">{{ collect(explode(' ', $user->name))->map(fn ($part) => strtoupper(substr($part, 0, 1)))->take(2)->implode('') }}</span>
            <h3>{{ $user->name }}</h3><p>{{ $user->email }}</p>
            <span class="role-badge role-{{ $user->role }}">{{ ucfirst($user->role) }}</span>
            <span class="status-badge {{ $user->status === 'active' ? 'badge-success' : ($user->status === 'suspended' ? 'badge-danger' : 'badge-neutral') }}">{{ ucfirst($user->status) }}</span>
        </div>
        <div class="review-details">
            <div class="detail-grid">
                <div><span>Contact</span><strong>{{ $user->contact_number ?: '—' }}</strong></div>
                <div><span>Birthday</span><strong>{{ $user->birthday?->format('M j, Y') ?? '—' }}</strong></div>
                <div><span>Location</span><strong>{{ collect([$user->barangay, $user->city, $user->province])->filter()->implode(', ') ?: '—' }}</strong></div>
                <div><span>Joined</span><strong>{{ $user->created_at?->format('M j, Y') ?? '—' }}</strong></div>
                <div><span>Last login</span><strong>{{ $user->last_login_at?->format('M j, Y g:i A') ?? 'Never' }}</strong></div>
                <div><span>Business / Store</span><strong>{{ $user->sellerProfile?->store?->name ?? $user->logisticsProfile?->display_name ?? $user->business_name ?? '—' }}</strong></div>
            </div>
        </div>
    </div>
</section>

<section class="panel">
    <div class="panel-heading"><div><span class="eyebrow">Account access</span><h2>Change account status</h2></div></div>
    <div class="document-preview-grid">
        @foreach ($allowedStatuses as $targetStatus)
            <form method="POST" action="{{ route('admin.users.status', $user) }}" class="document-preview">
                @csrf
                @method('PATCH')
                <span><i data-lucide="{{ $targetStatus === 'active' ? 'circle-check-big' : ($targetStatus === 'suspended' ? 'pause-circle' : 'user-x') }}"></i></span>
                <div><strong>{{ $targetStatus === 'active' ? 'Activate / Reactivate' : ucfirst($targetStatus) }}</strong><small>A reason is required and will be recorded in the audit trail.</small><input class="select-field" name="reason" maxlength="1000" required placeholder="Reason for this change"></div>
                <input type="hidden" name="status" value="{{ $targetStatus }}">
                <button class="button {{ $targetStatus === 'active' ? 'button-primary' : 'button-danger-soft' }} button-small" type="submit">Apply</button>
            </form>
        @endforeach
    </div>
</section>

<section class="panel">
    <div class="panel-heading"><div><span class="eyebrow">History</span><h2>Recent account actions</h2></div></div>
    <div class="table-wrap"><table class="admin-table"><thead><tr><th>Action</th><th>Description</th><th>Reason</th><th>Date</th></tr></thead><tbody>
        @forelse ($auditLogs as $log)
            <tr><td>{{ str($log->action)->replace('.', ' ')->title() }}</td><td>{{ $log->description }}</td><td>{{ data_get($log->new_values, 'reason', '—') }}</td><td>{{ $log->created_at?->format('M j, Y g:i A') }}</td></tr>
        @empty
            <tr><td colspan="4">No account actions have been recorded.</td></tr>
        @endforelse
    </tbody></table></div>
</section>
@endsection
