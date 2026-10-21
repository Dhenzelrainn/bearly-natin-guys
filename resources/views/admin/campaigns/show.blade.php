@extends('layouts.admin')

@section('title', $campaign->name)
@section('page-title', $campaign->name)

@section('content')
@php
    $nextStatuses = match ($campaign->status) {
        'draft' => ['open' => 'Open for Seller joining', 'ended' => 'End campaign'],
        'open' => ['active' => 'Activate campaign', 'paused' => 'Pause campaign', 'ended' => 'End campaign'],
        'active' => ['paused' => 'Pause campaign', 'ended' => 'End campaign'],
        'paused' => ['active' => 'Resume campaign', 'ended' => 'End campaign'],
        default => [],
    };
@endphp

<section class="page-hero">
    <div>
        <span class="eyebrow">{{ $campaign->campaignTypeLabel() }}</span>
        <h1>{{ $campaign->name }}</h1>
        <p>{{ $campaign->description ?: 'Bearly campaign event.' }}</p>
    </div>
    <div class="hero-context-stat">
        <span class="hero-context-icon"><i data-lucide="calendar-range"></i></span>
        <div>
            <strong>{{ ucfirst(str_replace('_', ' ', $campaign->statusKey())) }}</strong>
            <span>{{ $campaign->startsAt()?->format('M j, g:i A') }} - {{ $campaign->endsAt()?->format('M j, Y g:i A') }} PHT</span>
        </div>
    </div>
</section>

@if (session('success'))
    <div class="info-box" role="status"><strong>Saved.</strong> {{ session('success') }}</div>
@endif
@if ($errors->any())
    <div class="panel" role="alert">
        <strong>Check the campaign action.</strong>
        <ul>
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<section class="panel">
    <div class="panel-heading panel-heading-wrap">
        <div>
            <span class="eyebrow">Campaign status</span>
            <h2>Schedule and funding</h2>
        </div>
        <div class="promotion-admin-actions">
            <a class="button button-secondary" href="{{ route('admin.campaigns.edit', $campaign) }}">
                <i data-lucide="pencil"></i> Edit campaign
            </a>
            @foreach ($nextStatuses as $status => $label)
                <form method="POST" action="{{ route('admin.campaigns.status', $campaign) }}">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="status" value="{{ $status }}">
                    <button class="button {{ $status === 'ended' ? 'button-danger' : 'button-secondary' }}" type="submit">{{ $label }}</button>
                </form>
            @endforeach
        </div>
    </div>

    <div class="admin-detail-grid">
        <div><span>Type</span><strong>{{ $campaign->campaignTypeLabel() }}</strong></div>
        <div><span>Funding</span><strong>{{ $campaign->funding_type === \App\Models\PlatformPromotion::FUNDING_PLATFORM ? 'Bearly-funded' : 'Seller-funded' }}</strong></div>
                <div><span>Schedule</span><strong>{{ $campaign->schedulePeriod() }}</strong></div>
        <div><span>Join deadline</span><strong>{{ $campaign->join_deadline?->format('M j, Y') ?? '—' }}</strong></div>
        <div><span>Platform budget</span><strong>{{ $campaign->budget_minor ? '₱'.number_format($campaign->budget_minor / 100, 2) : 'No cap' }}</strong></div>
        <div><span>Seller limit</span><strong>{{ $campaign->max_participating_stores ?? 'Unlimited' }}</strong></div>
        <div><span>Product limit per Seller</span><strong>{{ $campaign->max_products_per_store ?? 'Unlimited' }}</strong></div>
        <div><span>Approval</span><strong>{{ $campaign->requires_approval ? 'Required' : 'Automatic' }}</strong></div>
    </div>

    @if ($campaign->terms)
        <div class="campaign-terms">{{ $campaign->terms }}</div>
    @endif
</section>

<section class="panel">
    <div class="panel-heading">
        <div>
            <span class="eyebrow">Seller review</span>
            <h2>Participations</h2>
        </div>
    </div>

    <div class="table-wrap">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Seller / Store</th>
                    <th>Products</th>
                    <th>Submitted</th>
                    <th>Status</th>
                    <th>Review</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($campaign->participations as $participation)
                    <tr>
                        <td>
                            <strong>{{ $participation->store?->name }}</strong>
                            <br><small>{{ $participation->submitter?->name }}</small>
                        </td>
                        <td>
                            @foreach ($participation->items as $item)
                                <div class="campaign-participation-item">
                                    <strong>{{ $item->product?->name }}</strong>
                                    <small>{{ $item->variant?->sku }} · ₱{{ number_format($item->campaign_price_minor / 100, 2) }} · stock {{ $item->stock_limit ?? '∞' }}</small>
                                </div>
                            @endforeach
                        </td>
                        <td>{{ $participation->submitted_at?->format('M j, Y g:i A') ?? '—' }}</td>
                        <td>
                            @php
                                $participationStatusClass = match ($participation->status) {
                                    'approved' => 'badge-success',
                                    'pending_review' => 'badge-warning',
                                    'rejected' => 'badge-danger',
                                    default => 'badge-neutral',
                                };
                            @endphp
                            <span class="status-badge {{ $participationStatusClass }}">{{ $participation->statusLabel() }}</span>
                        </td>
                        <td>
                            @if ($participation->status === 'pending_review')
                                <form method="POST" action="{{ route('admin.campaigns.participations.review', $participation) }}" class="campaign-review-form">
                                    @csrf
                                    @method('PATCH')
                                    <label>
                                        <span class="sr-only">Review note</span>
                                        <input class="text-field" name="reason" maxlength="1000" placeholder="Review note (optional)">
                                    </label>
                                    <button class="button button-primary" type="submit" name="decision" value="approve">Approve</button>
                                    <button class="button button-danger" type="submit" name="decision" value="reject">Reject</button>
                                </form>
                            @else
                                <small>{{ $participation->review_reason ?: 'Reviewed' }}@if ($participation->reviewer) · {{ $participation->reviewer->name }} @endif</small>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="table-empty">No Seller participations yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>

<section class="panel">
    <div class="panel-heading">
        <div>
            <span class="eyebrow">Audit</span>
            <h2>Recent campaign activity</h2>
        </div>
    </div>
    <div class="table-wrap">
        <table class="admin-table">
            <thead>
                <tr><th>When</th><th>Actor</th><th>Action</th><th>Description</th></tr>
            </thead>
            <tbody>
                @forelse ($auditLogs as $log)
                    <tr>
                        <td>{{ $log->created_at?->format('M j, Y g:i A') }}</td>
                        <td>{{ $log->actor?->name ?? 'System' }}</td>
                        <td>{{ $log->action }}</td>
                        <td>{{ $log->description }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="table-empty">No campaign audit activity yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
@endsection
