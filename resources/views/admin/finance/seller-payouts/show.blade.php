@extends('layouts.admin')

@section('title', 'Review Seller Withdrawal')
@section('page-title', 'Review Seller Withdrawal')

@section('content')
@php
    $money = fn (int $minor): string => '₱'.number_format($minor / 100, 2);
    $label = fn (string $status): string => str($status)->replace('_', ' ')->title()->toString();
    $canReject = in_array($withdrawal->status, ['pending_review', 'approved'], true);
@endphp

<section class="page-hero finance-withdrawal-detail-heading">
    <div><span class="eyebrow">Finance · Seller Payouts</span><h1>{{ $withdrawal->withdrawal_no }}</h1><p>Review account details and audit history. A transfer reference alone does not complete a withdrawal.</p></div>
    <div class="hero-actions"><a class="button button-secondary" href="{{ route('admin.seller-payouts.index') }}"><i data-lucide="arrow-left" aria-hidden="true"></i>Back to queue</a></div>
</section>

<section class="finance-withdrawal-detail-summary">
    <div><span>Requested amount</span><strong>{{ $money((int) $withdrawal->amount_minor) }}</strong></div>
    <div><span>Current status</span><strong><span class="status-badge {{ match($withdrawal->status) { 'completed' => 'badge-success', 'rejected', 'failed' => 'badge-danger', 'processing' => 'badge-info', default => 'badge-warning' } }}">{{ $label($withdrawal->status) }}</span></strong></div>
    <div><span>Reserved balance</span><strong>{{ in_array($withdrawal->status, ['pending_review', 'approved', 'processing'], true) ? $money((int) $withdrawal->amount_minor) : $money(0) }}</strong></div>
</section>

<div class="finance-withdrawal-detail-grid">
    <section class="panel finance-withdrawal-information">
        <div class="panel-heading"><div><span class="eyebrow">Request details</span><h2>Seller and bank account</h2></div></div>
        <dl class="finance-withdrawal-facts">
            <div><dt>Seller</dt><dd>{{ $withdrawal->sellerProfile?->store?->name ?: 'Seller' }}<small>{{ $withdrawal->sellerProfile?->user?->name }}</small></dd></div>
            <div><dt>Request date</dt><dd>{{ $withdrawal->requested_at?->format('M j, Y g:i A') }}</dd></div>
            <div><dt>Bank</dt><dd>{{ $withdrawal->bank_name_snapshot }}</dd></div>
            <div><dt>Account name</dt><dd>{{ $withdrawal->account_name_snapshot }}</dd></div>
            <div><dt>Masked account</dt><dd>•••• {{ $withdrawal->account_number_last4 }}</dd></div>
            <div class="finance-withdrawal-full-account"><dt>Transfer account</dt><dd><details><summary>Reveal full bank account number</summary><code>{{ $withdrawal->bankAccount?->account_number ?: 'Unavailable' }}</code></details></dd></div>
        </dl>
        <p class="finance-withdrawal-warning">The full account number is shown only on this authorized Finance review page. Confirm the account details before sending money.</p>
    </section>

    <section class="panel finance-withdrawal-actions">
        <div class="panel-heading"><div><span class="eyebrow">Finance action</span><h2>Next valid step</h2></div></div>

        @if($withdrawal->status === 'pending_review')
            <p>Confirm the Seller, exact amount, and linked bank account before approving or rejecting.</p>
            <form method="POST" action="{{ route('admin.seller-payouts.approve', $withdrawal) }}" class="finance-withdrawal-action-form">
                @csrf
                <label for="review-note">Review note <span>(optional)</span></label>
                <textarea id="review-note" name="review_note" maxlength="1000">{{ old('review_note') }}</textarea>
                <button class="button button-primary" type="submit">Approve request</button>
            </form>
        @elseif($withdrawal->status === 'approved')
            <p>Request is approved. Start the manual bank-transfer process when Finance is ready to arrange payment.</p>
            <form method="POST" action="{{ route('admin.seller-payouts.processing', $withdrawal) }}" class="finance-withdrawal-action-form">
                @csrf
                <label for="processing-note">Processing note</label>
                <textarea id="processing-note" name="processing_note" minlength="5" maxlength="1000" required>{{ old('processing_note') }}</textarea>
                @error('processing_note')<small class="field-error">{{ $message }}</small>@enderror
                <button class="button button-primary" type="submit">Start processing</button>
            </form>
        @elseif($withdrawal->status === 'processing' && ! $withdrawal->transfer)
            <p>Use your bank outside Bearly. Record the actual transfer details only after attempting the payment. Successful transfers still require a separate verification step.</p>
            @if($errors->any())<div class="finance-withdrawal-errors" role="alert"><strong>Please fix this:</strong><ul>@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></div>@endif
            <form method="POST" action="{{ route('admin.seller-payouts.transfer', $withdrawal) }}" class="finance-withdrawal-action-form" enctype="multipart/form-data" data-withdrawal-transfer-form>
                @csrf
                <label for="transfer-amount">Actual transfer amount (₱)</label>
                <input id="transfer-amount" type="number" min="0.01" step="0.01" name="transfer_amount" value="{{ old('transfer_amount', number_format((int) $withdrawal->amount_minor / 100, 2, '.', '')) }}" required>
                @error('transfer_amount')<small class="field-error">{{ $message }}</small>@enderror
                <label>Bank name<input type="text" value="{{ $withdrawal->bank_name_snapshot }}" readonly></label>
                <label>Bank transaction reference<input type="text" name="bank_reference" value="{{ old('bank_reference') }}" maxlength="150" autocomplete="off"><small>Required for a successful transfer. Do not use CODH-, L2F-, or BFR- cash-custody references.</small></label>
                @error('bank_reference')<small class="field-error">{{ $message }}</small>@enderror
                <label for="transfer-date">Transfer date</label>
                <input id="transfer-date" type="date" name="transfer_date" max="{{ now()->toDateString() }}" value="{{ old('transfer_date', now()->toDateString()) }}" required>
                @error('transfer_date')<small class="field-error">{{ $message }}</small>@enderror
                <label for="transfer-outcome">Transfer result</label>
                <select id="transfer-outcome" name="transfer_outcome" required data-transfer-outcome>
                    <option value="succeeded" @selected(old('transfer_outcome', 'succeeded') === 'succeeded')>Transfer sent — requires verification</option>
                    <option value="failed" @selected(old('transfer_outcome') === 'failed')>Failed — no successful transfer occurred</option>
                </select>
                @error('transfer_outcome')<small class="field-error">{{ $message }}</small>@enderror
                <label>Supporting proof <span>(optional)</span><input type="file" name="proof" accept=".jpg,.jpeg,.png,.webp,.pdf"></label>
                @error('proof')<small class="field-error">{{ $message }}</small>@enderror
                <div class="finance-transfer-failure-fields" data-transfer-failure-fields @if(old('transfer_outcome') !== 'failed') hidden @endif>
                    <label for="failure-reason">Why did the transfer fail?</label>
                    <textarea id="failure-reason" name="failure_reason" minlength="5" maxlength="1000">{{ old('failure_reason') }}</textarea>
                    @error('failure_reason')<small class="field-error">{{ $message }}</small>@enderror
                    <label class="finance-confirm"><input type="checkbox" name="no_successful_transfer_confirmed" value="1" @checked(old('no_successful_transfer_confirmed'))> I confirm no successful transfer occurred. Release the reserved funds only after this confirmation.</label>
                    @error('no_successful_transfer_confirmed')<small class="field-error">{{ $message }}</small>@enderror
                </div>
                <button class="button button-primary" type="submit">Record transfer result</button>
            </form>
        @elseif($withdrawal->status === 'processing' && $withdrawal->transfer?->status === 'pending_verification')
            <p>The transfer record is not completion proof by itself. Independently check the bank account activity; the requested amount and reference must match.</p>
            <dl class="finance-transfer-facts">
                <div><dt>Transfer reference</dt><dd><code>{{ $withdrawal->transfer->bank_reference }}</code></dd></div>
                <div><dt>Transfer amount</dt><dd>{{ $money((int) $withdrawal->transfer->amount_minor) }}</dd></div>
                <div><dt>Transfer date</dt><dd>{{ $withdrawal->transfer->transfer_date?->format('M j, Y') }}</dd></div>
                <div><dt>Recorded by</dt><dd>{{ $withdrawal->transfer->recorder?->name ?? 'Finance' }}</dd></div>
            </dl>
            @if($withdrawal->transfer->proof_path)<a class="button button-secondary button-small" href="{{ route('admin.seller-payouts.proof', $withdrawal) }}">Review transfer proof</a>@else<p class="finance-withdrawal-warning">No file proof was uploaded. Record how you independently verified the bank transfer below.</p>@endif
            <form method="POST" action="{{ route('admin.seller-payouts.complete', $withdrawal) }}" class="finance-withdrawal-action-form">
                @csrf
                <label for="verification-note">Verification notes</label>
                <textarea id="verification-note" name="verification_note" minlength="5" maxlength="1000" required>{{ old('verification_note') }}</textarea>
                @error('verification_note')<small class="field-error">{{ $message }}</small>@enderror
                <label class="finance-confirm"><input type="checkbox" name="transfer_verified" value="1" @checked(old('transfer_verified'))> I independently verified that the exact bank transfer succeeded.</label>
                @error('transfer_verified')<small class="field-error">{{ $message }}</small>@enderror
                <button class="button button-primary" type="submit">Verify and mark completed</button>
            </form>
            <details class="finance-withdrawal-reject finance-withdrawal-fail-transfer">
                <summary>Confirm transfer failed and release funds</summary>
                <p>Use only after verifying that no successful bank transfer occurred. This is terminal and cannot be reversed through this workflow.</p>
                <form method="POST" action="{{ route('admin.seller-payouts.fail-transfer', $withdrawal) }}" class="finance-withdrawal-action-form">
                    @csrf
                    <label for="recorded-transfer-failure-reason">Why did the attempted transfer fail?</label>
                    <textarea id="recorded-transfer-failure-reason" name="failure_reason" minlength="5" maxlength="1000" required>{{ old('failure_reason') }}</textarea>
                    @error('failure_reason')<small class="field-error">{{ $message }}</small>@enderror
                    <label class="finance-confirm"><input type="checkbox" name="no_successful_transfer_confirmed" value="1" @checked(old('no_successful_transfer_confirmed'))> I independently confirm no successful transfer occurred; release the reservation.</label>
                    @error('no_successful_transfer_confirmed')<small class="field-error">{{ $message }}</small>@enderror
                    <button class="button button-danger" type="submit">Confirm failure and release</button>
                </form>
            </details>
        @elseif($withdrawal->status === 'completed')
            <p class="finance-withdrawal-complete">Finance verified the bank transfer and recorded this withdrawal as completed.</p>
            <dl class="finance-transfer-facts">
                <div><dt>Bank reference</dt><dd><code>{{ $withdrawal->transfer?->bank_reference ?: '—' }}</code></dd></div>
                <div><dt>Transferred on</dt><dd>{{ $withdrawal->transfer?->transfer_date?->format('M j, Y') ?? '—' }}</dd></div>
                <div><dt>Verified by</dt><dd>{{ $withdrawal->transfer?->verifier?->name ?? '—' }}</dd></div>
                <div><dt>Verification note</dt><dd>{{ $withdrawal->transfer?->verification_note ?: '—' }}</dd></div>
            </dl>
            @if($withdrawal->transfer?->proof_path)<a class="button button-secondary button-small" href="{{ route('admin.seller-payouts.proof', $withdrawal) }}">Download transfer proof</a>@endif
        @elseif($withdrawal->status === 'rejected')
            <p class="finance-withdrawal-terminal">Rejected. Reserved funds were released to the Seller's Available Balance.</p>
            <p><strong>Reason:</strong> {{ $withdrawal->rejection_reason }}</p>
        @elseif($withdrawal->status === 'failed')
            <p class="finance-withdrawal-terminal">Failed. Finance confirmed no successful transfer occurred and released the reserved funds.</p>
            <p><strong>Reason:</strong> {{ $withdrawal->failure_reason }}</p>
            @if($withdrawal->transfer?->proof_path)<a class="button button-secondary button-small" href="{{ route('admin.seller-payouts.proof', $withdrawal) }}">Review transfer attempt proof</a>@endif
        @endif

        @if($canReject)
            <details class="finance-withdrawal-reject">
                <summary>Reject request and release funds</summary>
                <form method="POST" action="{{ route('admin.seller-payouts.reject', $withdrawal) }}" class="finance-withdrawal-action-form">
                    @csrf
                    <label for="rejection-reason">Rejection reason</label>
                    <textarea id="rejection-reason" name="rejection_reason" minlength="5" maxlength="1000" required>{{ old('rejection_reason') }}</textarea>
                    @error('rejection_reason')<small class="field-error">{{ $message }}</small>@enderror
                    <button class="button button-danger" type="submit">Reject and release</button>
                </form>
            </details>
        @endif
    </section>

    <section class="panel finance-withdrawal-history">
        <div class="panel-heading"><div><span class="eyebrow">Audit trail</span><h2>Status history</h2></div></div>
        <ol class="finance-withdrawal-timeline">
            @foreach($withdrawal->histories as $history)
                <li><span class="finance-history-dot"></span><div><strong>{{ $label($history->to_status) }}</strong><small>{{ $history->occurred_at?->format('M j, Y g:i A') }} · {{ $history->actor?->name ?? 'System' }}</small>@if($history->reason)<p>{{ $history->reason }}</p>@endif</div></li>
            @endforeach
        </ol>
    </section>
</div>
@endsection
