@extends('layouts.admin')

@section('title', 'Logistics payout destination')
@section('page-title', 'Logistics payout destination')

@section('content')
<section class="page-hero">
    <div>
        <span class="eyebrow">Finance · sensitive payout details</span>
        <h1>{{ $payoutAccount->provider_name }} destination</h1>
        <p>Full destination details are shown only to active Admin/Finance users. Access to this page is audited. Verify the destination with the Logistics provider before approving it.</p>
    </div>
    <div class="hero-actions">
        <a class="button button-secondary" href="{{ route('admin.shipping-settlements.index') }}">Back to Shipping Settlements</a>
    </div>
</section>

<section class="panel">
    <div class="panel-heading">
        <div>
            <span class="eyebrow">{{ $payoutAccount->logisticsProfile?->display_name ?? 'Logistics provider' }}</span>
            <h2>Destination details</h2>
        </div>
        <span class="status-badge" data-status="{{ $payoutAccount->status }}">{{ str($payoutAccount->status)->replace('_', ' ')->title() }}</span>
    </div>
    <div class="panel-body">
        <dl class="finance-cash-meta">
            <div><dt>Method</dt><dd>{{ $payoutAccount->channel === 'gcash' ? 'GCash' : 'Bank transfer' }}</dd></div>
            <div><dt>Provider</dt><dd>{{ $payoutAccount->provider_name }}</dd></div>
            <div><dt>Account holder</dt><dd>{{ $payoutAccount->account_name }}</dd></div>
            <div><dt>Full destination</dt><dd><code>{{ $payoutAccount->destination_value }}</code></dd></div>
            <div><dt>Last four digits</dt><dd>•••• {{ $payoutAccount->destination_last4 }}</dd></div>
            <div><dt>Submitted</dt><dd>{{ $payoutAccount->created_at?->format('M j, Y g:i A') }}</dd></div>
            @if($payoutAccount->reviewed_at)
                <div><dt>Reviewed by</dt><dd>{{ $payoutAccount->reviewer?->name ?? 'Finance' }} · {{ $payoutAccount->reviewed_at->format('M j, Y g:i A') }}</dd></div>
            @endif
            @if($payoutAccount->review_note)
                <div><dt>Review note</dt><dd>{{ $payoutAccount->review_note }}</dd></div>
            @endif
        </dl>
        <p class="table-subline">The destination number is encrypted at rest. Do not copy it into notes, references, or audit comments. Remittance remains a manual external transfer; Bearly records the destination and actual transfer reference.</p>
    </div>
</section>

@if($payoutAccount->status === 'pending_review')
    <section class="panel">
        <div class="panel-heading">
            <div>
                <span class="eyebrow">Independent confirmation required</span>
                <h2>Finance decision</h2>
                <p>Verify the account details with the Logistics provider using an approved contact channel. Verification makes this destination selectable for shipping remittance.</p>
            </div>
        </div>
        <div class="panel-body">
            <form method="POST" action="{{ route('admin.logistics-payout-accounts.verify', $payoutAccount->id) }}" class="finance-action-form">
                @csrf
                <label for="payout-verification-note">Verification note
                    <textarea id="payout-verification-note" name="verification_note" minlength="5" maxlength="1000" rows="3" required>{{ old('verification_note') }}</textarea>
                </label>
                <label class="finance-confirm"><input type="checkbox" name="destination_verified" value="1" @checked(old('destination_verified')) required> I independently confirmed this destination with the Logistics provider and it is suitable for remittance.</label>
                <button class="button button-primary" type="submit">Verify destination</button>
            </form>

            <form method="POST" action="{{ route('admin.logistics-payout-accounts.reject', $payoutAccount->id) }}" class="finance-action-form" style="margin-top: 18px">
                @csrf
                <label for="payout-review-reason">Reason for rejection
                    <textarea id="payout-review-reason" name="review_reason" minlength="5" maxlength="1000" rows="2" required>{{ old('review_reason') }}</textarea>
                </label>
                <button class="button button-secondary" type="submit">Reject destination</button>
            </form>
        </div>
    </section>
@endif
@endsection
