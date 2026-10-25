@extends('layouts.seller')

@section('title', 'Seller Wallet')
@section('page-title', 'Seller Wallet')

@section('content')
@php
    $money = fn (int $minor): string => '₱'.number_format($minor / 100, 2);
    $statusLabel = fn (string $status): string => str($status)->replace('_', ' ')->title()->toString();
    $ledgerStatus = fn (string $type): string => match ($type) {
        'sale_earning_pending' => 'Pending eligibility',
        'sale_earning_available' => 'Available',
        'withdrawal_reservation' => 'Reserved',
        'withdrawal_release' => 'Released',
        'withdrawal_completion', 'legacy_withdrawal_completion' => 'Withdrawn',
        'refund_adjustment' => 'Refund adjustment',
        'manual_adjustment' => 'Finance adjustment',
        default => $statusLabel($type),
    };
@endphp

<div class="page-heading wallet-page-heading">
    <div>
        <span class="section-kicker">Seller Finance</span>
        <h2>Seller Wallet</h2>
        <p>Paid earnings may move to Available after the 3-calendar-day return/refund period ends, counted from Seller Order completion. Payment, open cases, and COD reconciliation can keep funds pending longer.</p>
    </div>
    <div class="wallet-heading-actions">
        <a class="seller-secondary-button" href="{{ route('seller.finance.bank-accounts') }}"><i data-lucide="landmark" aria-hidden="true"></i>Bank Accounts</a>
        <button class="seller-primary-button" type="button" data-modal-open="wallet-withdrawal" @disabled((int) $wallet->available_balance_minor < 1 || $bankAccounts->isEmpty() || $outstandingRecoveryMinor > 0)>
            <i data-lucide="arrow-up-right" aria-hidden="true"></i>Withdraw Funds
        </button>
    </div>
</div>

<section class="seller-wallet-summary" aria-label="Wallet balances">
    <div class="seller-wallet-available">
        <span>Available Balance</span>
        <strong>{{ $money((int) $wallet->available_balance_minor) }}</strong>
        <small>Eligible to request for withdrawal</small>
    </div>
    <dl class="seller-wallet-secondary-balances">
        <div><dt>Pending Balance</dt><dd>{{ $money((int) $wallet->pending_balance_minor) }}</dd><small>Return period: 3 calendar days after order completion; other checks may extend the wait.</small></div>
        <div><dt>Reserved for Withdrawal</dt><dd>{{ $money((int) $wallet->reserved_balance_minor) }}</dd><small>Held on open requests</small></div>
        <div><dt>Total Withdrawn</dt><dd>{{ $money((int) $wallet->total_withdrawn_minor) }}</dd><small>Verified payouts, including legacy history</small></div>
    </dl>
</section>

@if($bankAccounts->isEmpty())
    <div class="seller-wallet-notice" role="status">
        <div><strong>Link a bank account to request a withdrawal.</strong><p>Bearly stores the account number encrypted and shows only its last four digits in account lists.</p></div>
        <a class="seller-secondary-button" href="{{ route('seller.finance.bank-accounts') }}">Manage Bank Accounts</a>
    </div>
@endif

@if($outstandingRecoveryMinor > 0)
    <div class="seller-wallet-notice" role="alert">
        <div>
            <strong>Refund recovery outstanding: {{ $money((int) $outstandingRecoveryMinor) }}</strong>
            <p>New withdrawals are blocked until this Seller-liable refund balance is fully deducted or settled. The wallet will apply the recovery automatically when this sale has enough pending or available funds.</p>
        </div>
    </div>
@endif

@if($recoveries->isNotEmpty())
    <section class="seller-panel seller-wallet-section" aria-labelledby="refund-recoveries-title">
        <div class="seller-panel-heading">
            <div><span class="section-kicker">Return &amp; refund</span><h3 id="refund-recoveries-title">Seller Recoveries</h3><p>Completed Seller-liable refunds are deducted from this wallet when funds are available. Any remaining balance stays visible here.</p></div>
        </div>
        <div class="seller-wallet-table-wrap">
            <table class="seller-wallet-table">
                <thead><tr><th>Refund</th><th>Seller Order</th><th>Assigned</th><th>Recovered</th><th>Outstanding</th><th>Status</th></tr></thead>
                <tbody>
                @foreach($recoveries as $recovery)
                    <tr>
                        <td><strong>{{ $recovery->refund?->refund_no ?? 'Refund unavailable' }}</strong><small>{{ $recovery->refund?->returnRequest?->return_no }}</small></td>
                        <td>{{ $recovery->refund?->returnRequest?->sellerOrder?->seller_order_no ?? '—' }}</td>
                        <td>{{ $money((int) $recovery->amount_minor) }}</td>
                        <td>{{ $money((int) $recovery->recovered_amount_minor) }}</td>
                        <td><strong>{{ $money($recovery->outstandingMinor()) }}</strong></td>
                        <td><span class="wallet-status wallet-status--{{ str_replace('_', '-', $recovery->status) }}">{{ $statusLabel($recovery->status) }}</span></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </section>
@endif

<section class="seller-panel seller-wallet-section" aria-labelledby="withdrawals-title">
    <div class="seller-panel-heading">
        <div><span class="section-kicker">Payout tracking</span><h3 id="withdrawals-title">Withdrawal Requests</h3><p>Finance reviews each request, records the actual bank transfer, then verifies it before completion.</p></div>
    </div>
    <div class="seller-wallet-table-wrap">
        <table class="seller-wallet-table">
            <thead><tr><th>Withdrawal</th><th>Amount</th><th>Bank account</th><th>Requested</th><th>Status / next step</th></tr></thead>
            <tbody>
            @forelse($withdrawals as $withdrawal)
                <tr>
                    <td><strong>{{ $withdrawal->withdrawal_no }}</strong><small>{{ $withdrawal->transfer?->bank_reference ?: '—' }}</small></td>
                    <td><strong>{{ $money((int) $withdrawal->amount_minor) }}</strong></td>
                    <td>{{ $withdrawal->bank_name_snapshot }}<small>{{ $withdrawal->account_name_snapshot }} · •••• {{ $withdrawal->account_number_last4 }}</small></td>
                    <td>{{ $withdrawal->requested_at?->format('M j, Y g:i A') }}</td>
                    <td>
                        <span class="wallet-status wallet-status--{{ $withdrawal->status }}">{{ $statusLabel($withdrawal->status) }}</span>
                        <small class="wallet-next-step">
                            @switch($withdrawal->status)
                                @case('pending_review') Finance is reviewing your request. @break
                                @case('approved') Finance approved it; bank transfer is next. @break
                                @case('processing') Finance is arranging and verifying the bank transfer. @break
                                @case('completed') Transfer verified{{ $withdrawal->transfer?->bank_reference ? ' · '.$withdrawal->transfer->bank_reference : '' }}. @break
                                @case('rejected') {{ $withdrawal->rejection_reason ?: 'The reserved funds were returned to Available Balance.' }} @break
                                @case('failed') {{ $withdrawal->failure_reason ?: 'The reserved funds were returned to Available Balance.' }} @break
                            @endswitch
                        </small>
                        @if($withdrawal->status === 'completed' && $withdrawal->transfer?->proof_path)
                            <a class="wallet-proof-link" href="{{ route('seller.finance.withdrawals.proof', $withdrawal) }}">Download transfer proof</a>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="5"><p class="seller-wallet-empty">No withdrawal requests yet. Available wallet funds can be requested for bank transfer.</p></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="seller-wallet-pagination">{{ $withdrawals->links() }}</div>
</section>

<section class="seller-panel seller-wallet-section" aria-labelledby="wallet-ledger-title">
    <div class="seller-panel-heading wallet-ledger-heading">
        <div><span class="section-kicker">Auditable wallet ledger</span><h3 id="wallet-ledger-title">Wallet Activity</h3><p>Each row records a centavo-based balance movement and its reference.</p></div>
        <a href="{{ route('seller.finance.transactions') }}">Earnings history</a>
    </div>
    <div class="seller-wallet-table-wrap">
        <table class="seller-wallet-table">
            <thead><tr><th>Transaction / reference</th><th>Order</th><th>Type</th><th>Amount</th><th>Date</th><th>Status</th></tr></thead>
            <tbody>
            @forelse($transactions as $transaction)
                @php
                    $order = $transaction->covered_order_label;
                    $withdrawal = $transaction->withdrawal;
                    $amountSign = collect([
                        (int) $transaction->available_delta_minor,
                        (int) $transaction->pending_delta_minor,
                        (int) $transaction->reserved_delta_minor,
                        (int) $transaction->withdrawn_delta_minor,
                    ])->sum() < 0 ? '−' : '';
                    $status = $transaction->type === 'sale_earning_pending'
                        ? 'Pending eligibility'
                        : ($transaction->type === 'sale_earning_available' ? 'Available' : ($withdrawal ? $statusLabel($withdrawal->status) : $ledgerStatus($transaction->type)));
                    $returnWindowEndsAt = $transaction->return_window_ends_at;
                @endphp
                <tr>
                    <td><strong>{{ $transaction->transaction_no }}</strong><small>{{ $transaction->reference ?: $transaction->description ?: '—' }}</small></td>
                    <td>
                        @if($order)
                            <strong>{{ $order }}</strong>
                            @if(count($transaction->order_item_previews))
                                @include('partials.order-item-preview-list', ['items' => $transaction->order_item_previews])
                            @endif
                        @else
                            —
                        @endif
                    </td>
                    <td>{{ $statusLabel($transaction->type) }}</td>
                    <td class="{{ $amountSign === '−' ? 'is-negative' : '' }}">{{ $amountSign }}{{ $money((int) $transaction->amount_minor) }}</td>
                    <td>{{ $transaction->created_at?->format('M j, Y g:i A') }}</td>
                    <td>
                        <span class="wallet-status wallet-status--{{ str_replace('_', '-', $transaction->type) }}">{{ $status }}</span>
                        @if($transaction->type === 'sale_earning_pending')
                            <small class="wallet-next-step">
                                @if($returnWindowEndsAt)
                                    @if(now()->greaterThan($returnWindowEndsAt))
                                        Return period ended {{ $returnWindowEndsAt->format('M j, Y') }}; other settlement checks may still be pending.
                                    @else
                                        Return period ends {{ $returnWindowEndsAt->format('M j, Y') }}; may become Available after that if all other checks pass.
                                    @endif
                                @else
                                    Waiting for Seller Order completion before the return-period date can be determined.
                                @endif
                            </small>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6"><p class="seller-wallet-empty">Wallet ledger entries will appear when paid Seller earnings or withdrawal activity is recorded.</p></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="seller-wallet-pagination">{{ $transactions->links() }}</div>
</section>

<div class="seller-modal wallet-withdrawal-modal" data-modal="wallet-withdrawal" @if(!$errors->any()) hidden @endif>
    <button class="seller-modal-backdrop" type="button" data-modal-close aria-label="Close withdrawal form"></button>
    <section class="seller-modal-panel" role="dialog" aria-modal="true" aria-labelledby="wallet-withdrawal-title" data-wallet-withdrawal-modal>
        <button class="seller-modal-close" type="button" data-modal-close aria-label="Close"><i data-lucide="x" aria-hidden="true"></i></button>
        <span class="section-kicker">Seller Wallet</span>
        <h3 id="wallet-withdrawal-title">Withdraw Funds</h3>
        <p class="wallet-modal-intro">Your available balance is <strong data-wallet-available-display>{{ $money((int) $wallet->available_balance_minor) }}</strong>. Requested funds are reserved until Finance completes or releases the request.</p>
        @if($errors->any())
            <div class="seller-wallet-errors" role="alert"><strong>Check these details:</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif
        <form method="POST" action="{{ route('seller.finance.withdrawals.store') }}" class="seller-wallet-withdrawal-form" data-wallet-withdrawal-form data-available-minor="{{ (int) $wallet->available_balance_minor }}">
            @csrf
            <input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">
            <label for="wallet-withdrawal-amount">Withdrawal Amount (₱)</label>
            <div class="wallet-amount-control">
                <input id="wallet-withdrawal-amount" type="number" name="amount" min="0.01" step="0.01" max="{{ number_format((int) $wallet->available_balance_minor / 100, 2, '.', '') }}" value="{{ old('amount') }}" inputmode="decimal" required data-wallet-amount>
                <button type="button" class="seller-secondary-button" data-wallet-withdraw-all>Withdraw All</button>
            </div>
            @error('amount')<small class="seller-wallet-field-error">{{ $message }}</small>@enderror

            <label for="wallet-bank-account">Linked Bank Account</label>
            <select id="wallet-bank-account" name="seller_bank_account_id" required data-wallet-bank-account>
                <option value="">Choose a bank account</option>
                @foreach($bankAccounts as $account)
                    <option value="{{ $account->id }}" data-bank-name="{{ $account->bank_name }}" data-account-name="{{ $account->account_name }}" data-last4="{{ $account->account_number_last4 }}" @selected((string) old('seller_bank_account_id', $bankAccounts->firstWhere('is_default', true)?->id ?? $bankAccounts->first()?->id) === (string) $account->id)>
                        {{ $account->bank_name }} · •••• {{ $account->account_number_last4 }}
                    </option>
                @endforeach
            </select>
            @error('seller_bank_account_id')<small class="seller-wallet-field-error">{{ $message }}</small>@enderror

            <dl class="wallet-withdrawal-summary">
                <div><dt>Account name</dt><dd data-wallet-account-name>Choose an account</dd></div>
                <div><dt>Bank</dt><dd data-wallet-bank-name>—</dd></div>
                <div><dt>Account number</dt><dd data-wallet-account-number>•••• —</dd></div>
                <div><dt>Amount requested</dt><dd data-wallet-amount-summary>₱0.00</dd></div>
                <div><dt>Available after request</dt><dd data-wallet-remaining-summary>{{ $money((int) $wallet->available_balance_minor) }}</dd></div>
            </dl>
            <p class="wallet-bank-transfer-note">Bearly will not send an automatic transfer. Finance will review the request and manually process the bank transfer.</p>
            <div class="wallet-modal-actions">
                <button class="seller-secondary-button" type="button" data-modal-close>Cancel</button>
                <button class="seller-primary-button" type="submit" @disabled($bankAccounts->isEmpty() || (int) $wallet->available_balance_minor < 1 || $outstandingRecoveryMinor > 0)>Confirm Withdrawal</button>
            </div>
        </form>
    </section>
</div>
@endsection
