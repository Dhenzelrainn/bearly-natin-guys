@extends('layouts.admin')

@section('title', 'Bearly Campaigns')
@section('page-title', 'Bearly Campaigns')

@section('content')
<section class="page-hero">
    <div>
        <span class="eyebrow">Marketing</span>
        <h1>Bearly campaign events</h1>
        <p>Create Flash Deals, Piso Sales, Payday Sales, Monthly Deals, and double-date events. Sellers join with eligible products, then Bearly reviews and activates the campaign schedule.</p>
    </div>
    <div class="hero-context-stat">
        <span class="hero-context-icon"><i data-lucide="calendar-range"></i></span>
        <div>
            <strong>{{ $campaigns->count() }}</strong>
            <span>Bearly campaigns</span>
        </div>
    </div>
</section>

@if (session('success'))
    <div class="info-box" role="status"><strong>Saved.</strong> {{ session('success') }}</div>
@endif

<section class="panel">
    <div class="panel-heading panel-heading-wrap">
        <div>
            <span class="eyebrow">Campaign calendar</span>
            <h2>Campaigns and Seller participations</h2>
        </div>
        <a class="button button-primary" href="{{ route('admin.campaigns.create') }}">
            <i data-lucide="plus"></i> Create campaign
        </a>
    </div>

    <div class="table-wrap">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Campaign</th>
                    <th>Type</th>
                    <th>Funding</th>
                    <th>Schedule</th>
                    <th>Join deadline</th>
                    <th>Participations</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($campaigns as $campaign)
                    <tr>
                        <td>
                            <strong>{{ $campaign->name }}</strong>
                            <br><small>{{ $campaign->scopeLabel() }}</small>
                        </td>
                        <td>{{ $campaign->campaignTypeLabel() }}</td>
                        <td>{{ $campaign->funding_type === \App\Models\PlatformPromotion::FUNDING_PLATFORM ? 'Bearly-funded' : 'Seller-funded' }}</td>
                            <td>{{ $campaign->schedulePeriod() }}</td>
                        <td>{{ $campaign->join_deadline?->format('M j, Y') ?? '—' }}</td>
                        <td>
                            {{ (int) $campaign->approved_participations_count }} approved
                            @if ((int) $campaign->pending_participations_count > 0)
                                <br><span class="badge badge-warning">{{ (int) $campaign->pending_participations_count }} pending</span>
                            @endif
                        </td>
                        <td>
                            @php
                                $campaignStatusClass = match ($campaign->statusKey()) {
                                    'active' => 'badge-success',
                                    'open', 'scheduled' => 'badge-info',
                                    'paused' => 'badge-warning',
                                    default => 'badge-neutral',
                                };
                            @endphp
                            <span class="status-badge {{ $campaignStatusClass }}">{{ ucfirst(str_replace('_', ' ', $campaign->statusKey())) }}</span>
                        </td>
                        <td><a class="button button-secondary" href="{{ route('admin.campaigns.show', $campaign) }}">Review</a></td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="table-empty">No Bearly campaigns yet. Create the first campaign event.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
@endsection
