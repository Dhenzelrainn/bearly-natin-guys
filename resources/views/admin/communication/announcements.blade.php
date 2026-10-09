@extends('layouts.admin')

@section('title', 'Announcements')
@section('page-title', 'Announcements')

@section('content')
<section class="page-hero">
    <div>
        <span class="eyebrow">Communication</span>
        <h1>Platform announcements</h1>
        <p>Create, schedule, publish, and manage platform-wide announcements for Buyers, Sellers, Logistics Centers, Riders, or all users.</p>
    </div>
    <div class="hero-context-stat">
        <span class="hero-context-icon"><i data-lucide="megaphone"></i></span>
        <div><strong>{{ $announcements->total() }}</strong><span>Announcement records</span></div>
    </div>
</section>

<section class="panel">
    <div class="panel-heading panel-heading-wrap">
        <div><span class="eyebrow">Announcement management</span><h2>Announcement records</h2></div>
        <form method="GET" action="{{ route('admin.announcements') }}" class="table-toolbar">
            <label class="field-with-icon compact-field"><i data-lucide="search"></i><input type="search" name="search" value="{{ $filters['search'] }}" placeholder="Search announcement..."></label>
            <select class="select-field" name="audience">
                <option value="">All audiences</option>
                @foreach ($roles as $roleOption)<option value="{{ $roleOption->name }}" @selected($filters['audience'] === $roleOption->name)>{{ $roleOption->display_name }}</option>@endforeach
            </select>
            <select class="select-field" name="status">
                <option value="">All statuses</option>
                @foreach (['published', 'scheduled', 'draft', 'archived'] as $statusOption)<option value="{{ $statusOption }}" @selected($filters['status'] === $statusOption)>{{ ucfirst($statusOption) }}</option>@endforeach
            </select>
            <button class="button button-secondary button-small" type="submit">Apply</button>
            <button type="button" class="button button-primary" data-open-modal="create-announcement"><i data-lucide="plus"></i> New Announcement</button>
        </form>
    </div>

    <div class="table-wrap">
        <table class="admin-table" id="announcements-table">
            <thead><tr><th>Announcement</th><th>Audience</th><th>Author</th><th>Publish Date</th><th>Status</th><th class="align-right">Actions</th></tr></thead>
            <tbody>
                @forelse ($announcements as $announcement)
                    @php
                        $roleNames = $announcement->roles->pluck('name')->all();
                        $audienceLabel = count($roleNames) === 4 ? 'All Users' : $announcement->roles->pluck('display_name')->implode(', ');
                    @endphp
                    <tr>
                        <td><div class="identity-cell"><span class="avatar avatar-soft"><i data-lucide="megaphone"></i></span><div class="table-primary-secondary"><strong>{{ $announcement->title }}</strong><small>{{ $announcement->announcement_no }}</small></div></div></td>
                        <td>{{ $audienceLabel }}</td>
                        <td>{{ $announcement->creator?->name ?? 'System' }}</td>
                        <td><div class="table-primary-secondary"><strong>{{ $announcement->publish_at?->format('M j, Y') ?? '—' }}</strong>@if ($announcement->publish_at)<small>{{ $announcement->publish_at->format('g:i A') }}</small>@endif</div></td>
                        <td><span class="status-badge {{ $announcement->status === 'published' ? 'badge-success' : ($announcement->status === 'scheduled' ? 'badge-warning' : ($announcement->status === 'archived' ? 'badge-neutral' : 'badge-info')) }}">{{ ucfirst($announcement->status) }}</span></td>
                        <td class="align-right"><button type="button" class="button button-ghost button-small" data-open-modal="announcement-{{ $announcement->id }}"><i data-lucide="eye"></i> View</button></td>
                    </tr>
                @empty
                    <tr><td colspan="6"><div class="table-empty"><i data-lucide="search-x"></i><strong>No announcements found</strong><span>No records match the current search or filters.</span></div></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($announcements->hasPages())<div class="panel-footer">{{ $announcements->links() }}</div>@endif
</section>

@foreach ($announcements as $announcement)
    @php
        $roleNames = $announcement->roles->pluck('name')->all();
        $audienceValue = count($roleNames) === 4 ? 'all' : (count($roleNames) === 1 ? $roleNames[0] : null);
        $audienceLabel = count($roleNames) === 4 ? 'All Users' : $announcement->roles->pluck('display_name')->implode(', ');
    @endphp
    <div class="modal-shell" data-modal="announcement-{{ $announcement->id }}" hidden>
        <button type="button" class="modal-backdrop" data-close-modal aria-label="Close announcement"></button>
        <section class="modal-card modal-wide" role="dialog" aria-modal="true" aria-labelledby="announcement-title-{{ $announcement->id }}">
            <div class="modal-heading">
                <div><span class="eyebrow">{{ $announcement->announcement_no }}</span><h2 id="announcement-title-{{ $announcement->id }}">{{ $announcement->title }}</h2></div>
                <button type="button" class="icon-button" data-close-modal aria-label="Close"><i data-lucide="x"></i></button>
            </div>
            <form method="POST" action="{{ route('admin.announcements.update', $announcement) }}">
                @csrf
                @method('PATCH')
                <div class="review-details">
                    <h3 class="section-subtitle">Announcement information</h3>
                    <div class="detail-grid">
                        <label class="form-field"><span>Announcement title</span><input class="text-field" name="title" value="{{ $announcement->title }}" maxlength="180" required></label>
                        <label class="form-field"><span>Audience</span><select class="select-field" name="audience" required>@if ($audienceValue === null)<option value="" selected disabled>{{ $audienceLabel }} (custom)</option>@endif<option value="all" @selected($audienceValue === 'all')>All Users</option>@foreach ($roles as $roleOption)<option value="{{ $roleOption->name }}" @selected($audienceValue === $roleOption->name)>{{ $roleOption->display_name }}</option>@endforeach</select></label>
                        <label class="form-field"><span>Status</span><select class="select-field" name="status" required>@foreach (['draft', 'scheduled', 'published', 'archived'] as $statusOption)<option value="{{ $statusOption }}" @selected($announcement->status === $statusOption)>{{ ucfirst($statusOption) }}</option>@endforeach</select></label>
                        <label class="form-field"><span>Publish date and time</span><input class="text-field" type="datetime-local" name="publish_at" value="{{ $announcement->publish_at?->format('Y-m-d\TH:i') }}"></label>
                        <label class="form-field"><span>Expiration date and time</span><input class="text-field" type="datetime-local" name="expires_at" value="{{ $announcement->expires_at?->format('Y-m-d\TH:i') }}"></label>
                        <div><span>Author</span><strong>{{ $announcement->creator?->name ?? 'System' }}</strong></div>
                    </div>
                    <label class="form-field announcement-message-field"><span>Announcement message</span><textarea class="text-field" name="body" rows="7" maxlength="10000" required>{{ $announcement->body }}</textarea></label>
                </div>
                <div class="modal-footer decision-footer"><button type="submit" class="button button-primary"><i data-lucide="save"></i> Save Changes</button><button type="button" class="button button-ghost" data-close-modal>Cancel</button></div>
            </form>
            <form method="POST" action="{{ route('admin.announcements.destroy', $announcement) }}" onsubmit="return confirm('Delete this announcement permanently?')">
                @csrf
                @method('DELETE')
                <div class="modal-footer"><button class="button button-danger-soft" type="submit"><i data-lucide="trash-2"></i> Delete Announcement</button></div>
            </form>
        </section>
    </div>
@endforeach

<div class="modal-shell" data-modal="create-announcement" @if (! $errors->any()) hidden @endif>
    <button type="button" class="modal-backdrop" data-close-modal aria-label="Close announcement editor"></button>
    <section class="modal-card modal-wide" role="dialog" aria-modal="true" aria-labelledby="announcement-editor-title">
        <div class="modal-heading">
            <div><span class="eyebrow">Communication</span><h2 id="announcement-editor-title">Create announcement</h2></div>
            <button type="button" class="icon-button" data-close-modal aria-label="Close"><i data-lucide="x"></i></button>
        </div>
        <form method="POST" action="{{ route('admin.announcements.store') }}">
            @csrf
            <div class="announcement-form">
                @if ($errors->any())<div class="detail-note"><strong>Please correct the following:</strong><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
                <div class="form-grid two-column-form">
                    <label class="form-field"><span>Announcement title</span><input type="text" class="text-field" name="title" value="{{ old('title') }}" maxlength="180" placeholder="Enter announcement title" required></label>
                    <label class="form-field"><span>Audience</span><select class="select-field" name="audience" required><option value="all" @selected(old('audience', 'all') === 'all')>All Users</option>@foreach ($roles as $roleOption)<option value="{{ $roleOption->name }}" @selected(old('audience') === $roleOption->name)>{{ $roleOption->display_name }}</option>@endforeach</select></label>
                    <label class="form-field"><span>Status</span><select class="select-field" name="status" required><option value="draft" @selected(old('status') === 'draft')>Draft</option><option value="scheduled" @selected(old('status') === 'scheduled')>Scheduled</option><option value="published" @selected(old('status') === 'published')>Publish now</option></select></label>
                    <label class="form-field"><span>Publish date and time</span><input type="datetime-local" class="text-field" name="publish_at" value="{{ old('publish_at') }}"></label>
                    <label class="form-field"><span>Expiration date and time</span><input type="datetime-local" class="text-field" name="expires_at" value="{{ old('expires_at') }}"></label>
                </div>
                <label class="form-field announcement-message-field"><span>Announcement message</span><textarea class="text-field" rows="7" name="body" maxlength="10000" placeholder="Write the announcement..." required>{{ old('body') }}</textarea></label>
            </div>
            <div class="modal-footer decision-footer"><button type="submit" class="button button-primary"><i data-lucide="save"></i> Save Announcement</button><button type="button" class="button button-ghost" data-close-modal>Cancel</button></div>
        </form>
    </section>
</div>
@endsection
