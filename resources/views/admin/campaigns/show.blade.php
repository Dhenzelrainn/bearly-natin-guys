@extends('layouts.admin')

@section('title', $campaign->name)
@section('page-title', $campaign->name)

@section('content')
@php
    $isPisoSale = $campaign->campaign_type === \App\Models\PlatformPromotion::CAMPAIGN_PISO_SALE;
    $formatCampaignMinor = static function (?int $minor): string {
        if ($minor === null) {
            return '—';
        }

        $absolute = abs($minor);
        $formatted = '₱'.number_format(intdiv($absolute, 100), 0, '.', ',').'.'.str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);

        return $minor < 0 ? '-'.$formatted : $formatted;
    };
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

@include('admin.partials.success-toast')
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
            <a class="button button-secondary" href="{{ route('admin.campaigns') }}">
                <i data-lucide="arrow-left" aria-hidden="true"></i> Back to campaigns
            </a>
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
        <div><span>Funding</span><strong>{{ $campaign->funding_type === \App\Models\PlatformPromotion::FUNDING_PLATFORM ? 'Bearly-funded' : 'Seller-funded (legacy)' }}</strong></div>
        <div><span>{{ $isPisoSale ? 'Campaign price' : 'Minimum Seller discount' }}</span><strong>{{ $isPisoSale ? 'Fixed ₱1.00 (no percentage)' : ($campaign->minimum_discount_percent !== null ? ((int) $campaign->minimum_discount_percent).'% off' : 'Not configured (legacy)') }}</strong></div>
        <div><span>Schedule</span><strong>{{ $campaign->schedulePeriod() }}</strong></div>
        <div><span>Join deadline</span><strong>{{ $campaign->join_deadline?->format('M j, Y') ?? '—' }}</strong></div>
        <div><span>Platform budget</span><strong>{{ $campaign->funding_type === \App\Models\PlatformPromotion::FUNDING_SELLER ? 'No Bearly budget applies' : ($campaign->budget_minor !== null ? $formatCampaignMinor((int) $campaign->budget_minor) : 'No budget cap') }}</strong></div>
        <div><span>Seller limit</span><strong>{{ $campaign->max_participating_stores ?? 'Unlimited' }}</strong></div>
        <div><span>Product limit per Seller</span><strong>{{ $campaign->max_products_per_store ?? 'Unlimited' }}</strong></div>
        <div><span>Approval</span><strong>{{ $campaign->requires_approval ? 'Required' : 'Automatic' }}</strong></div>
    </div>

    @if ($campaign->terms)
        <div class="campaign-terms">{{ $campaign->terms }}</div>
    @endif
    @if (filled($campaign->campaign_notes))
        <div class="campaign-internal-notes" aria-label="Admin-only campaign notes">
            <strong>Internal Admin notes</strong>
            <p>{{ $campaign->campaign_notes }}</p>
        </div>
    @endif
</section>

<section class="panel campaign-budget-estimate-panel" aria-labelledby="campaign-budget-estimate-title">
    <div class="panel-heading panel-heading-wrap">
        <div>
            <span class="eyebrow">Admin estimate</span>
            <h2 id="campaign-budget-estimate-title">Bearly campaign budget</h2>
        </div>
        @php
            $budgetStatusClass = match ($budgetEstimate['status_key']) {
                'projected_over_budget' => 'badge-danger',
                'actual_at_or_over_budget' => 'badge-warning',
                'within_budget' => 'badge-success',
                default => 'badge-neutral',
            };
            $budgetStatusLabel = match ($budgetEstimate['status_key']) {
                'projected_over_budget' => 'Projected exposure exceeds cap',
                'actual_at_or_over_budget' => 'Actual spend reached cap',
                'within_budget' => 'Projected within budget',
                'no_cap' => 'No budget cap',
                default => 'No Bearly budget applies',
            };
        @endphp
        <span class="status-badge {{ $budgetStatusClass }}">{{ $budgetStatusLabel }}</span>
    </div>

    @unless ($budgetEstimate['applies'])
        <p class="campaign-budget-estimate-note">This campaign is Seller-funded. No Bearly campaign budget or Bearly-funded exposure applies.</p>
    @else
        <div class="admin-detail-grid campaign-budget-estimate-grid">
            <div>
                <span>Configured budget</span>
                <strong>{{ $budgetEstimate['budget_minor'] !== null ? $formatCampaignMinor($budgetEstimate['budget_minor']) : 'No budget cap' }}</strong>
            </div>
            <div>
                <span>Actual Bearly spend to date</span>
                <strong>{{ $formatCampaignMinor($budgetEstimate['actual_spend_minor']) }}</strong>
            </div>
            <div>
                <span>Approved remaining exposure</span>
                <strong>{{ $formatCampaignMinor($budgetEstimate['approved_remaining_exposure_minor']) }}</strong>
            </div>
            <div>
                <span>Projected total</span>
                <strong>{{ $formatCampaignMinor($budgetEstimate['projected_total_minor']) }}</strong>
            </div>
            <div>
                <span>Remaining headroom</span>
                <strong>
                    @if ($budgetEstimate['remaining_headroom_minor'] === null)
                        No budget cap
                    @elseif ($budgetEstimate['remaining_headroom_minor'] < 0)
                        {{ $formatCampaignMinor(abs($budgetEstimate['remaining_headroom_minor'])) }} over budget
                    @else
                        {{ $formatCampaignMinor($budgetEstimate['remaining_headroom_minor']) }}
                    @endif
                </strong>
            </div>
        </div>

        <p class="campaign-budget-estimate-note">
            Estimate only—not reserved or allocated. Actual spend follows the checkout cap’s order-item snapshots: cancelled Seller Orders and fully refunded Orders are excluded; partial refunds remain counted. Checkout still enforces the budget under lock.
            @if ($budgetEstimate['approved_inventory_based_items_count'] > 0)
                {{ $budgetEstimate['approved_inventory_based_items_count'] }} approved item(s) use current inventory without a campaign stock limit; this estimate can change as inventory changes.
            @endif
        </p>

        @if ($budgetEstimate['actual_at_or_over_budget'])
            <div class="campaign-budget-warning is-warning" role="status">
                Actual Bearly spend has reached or exceeded the configured budget.
            </div>
        @endif
        @if ($budgetEstimate['projected_exceeds_budget'])
            <div class="campaign-budget-warning is-danger" role="status">
                Approved remaining exposure puts the projected total {{ $formatCampaignMinor(abs($budgetEstimate['remaining_headroom_minor'])) }} over the configured budget.
            </div>
        @elseif ($budgetEstimate['within_budget'])
            <div class="campaign-budget-warning is-success" role="status">
                Approved remaining exposure is within the configured budget, with {{ $formatCampaignMinor($budgetEstimate['remaining_headroom_minor']) }} projected headroom.
            </div>
        @elseif ($budgetEstimate['budget_minor'] === null)
            <div class="campaign-budget-estimate-note">No budget cap is configured; projected exposure is informational only.</div>
        @endif
    @endunless
</section>

<section
    class="panel"
    data-admin-campaign-participations
    data-campaign-id="{{ (int) $campaign->id }}"
    data-refresh-url="{{ route('admin.campaigns.participations.refresh', $campaign) }}"
    data-poll-interval="15000"
>
    <div class="panel-heading">
        <div>
            <span class="eyebrow">Seller review</span>
            <h2>Participations</h2>
            <span class="sr-only" role="status" aria-live="polite" data-campaign-participation-live-status></span>
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
            @include('admin.campaigns.partials.participation-rows', [
                'campaign' => $campaign,
                'budgetEstimate' => $budgetEstimate,
                'isPisoSale' => $isPisoSale,
                'formatCampaignMinor' => $formatCampaignMinor,
            ])
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
