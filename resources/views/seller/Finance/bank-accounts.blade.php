@extends('layouts.seller')

@section('title', 'Bank Accounts')
@section('page-title', 'Bank Accounts')

@section('content')
<div class="page-heading wallet-page-heading">
    <div><span class="section-kicker">Seller Finance</span><h2>Linked Bank Accounts</h2><p>Choose where Finance should send an approved withdrawal. Full account numbers are encrypted and are not shown in account lists.</p></div>
    <a class="seller-secondary-button" href="{{ route('seller.finance.wallet') }}"><i data-lucide="arrow-left" aria-hidden="true"></i>Back to Wallet</a>
</div>

<section class="seller-panel seller-bank-account-panel" aria-labelledby="linked-accounts-title">
    <div class="seller-panel-heading"><div><span class="section-kicker">Withdrawal destination</span><h3 id="linked-accounts-title">Your Bank Accounts</h3><p>Deactivating an account prevents new withdrawal requests from using it. Existing withdrawal history is retained.</p></div></div>
    <div class="seller-bank-account-list">
        @forelse($accounts as $account)
            <article class="seller-bank-account-row">
                <div><strong>{{ $account->bank_name }}</strong><span>{{ $account->account_name }}</span><small>Account ending in {{ $account->account_number_last4 }} · {{ ucfirst($account->status) }}@if($account->is_default) · Default @endif</small></div>
                @if($account->status === 'active')
                    <form method="POST" action="{{ route('seller.finance.bank-accounts.deactivate', $account) }}" onsubmit="return confirm('Deactivate this bank account? Existing withdrawal records will remain unchanged.')">
                        @csrf @method('PATCH')
                        <button class="seller-secondary-button" type="submit">Deactivate</button>
                    </form>
                @endif
            </article>
        @empty
            <p class="seller-wallet-empty">No bank account is linked. Add one to request a withdrawal.</p>
        @endforelse
    </div>
</section>

<section class="seller-panel seller-bank-account-panel" aria-labelledby="add-bank-account-title">
    <div class="seller-panel-heading"><div><span class="section-kicker">Add destination</span><h3 id="add-bank-account-title">Link a Bank Account</h3><p>Bearly does not verify account ownership automatically. Check every detail before submitting a withdrawal request.</p></div></div>
    @if($errors->any())<div class="seller-wallet-errors" role="alert"><strong>Check these details:</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <form method="POST" action="{{ route('seller.finance.bank-accounts.store') }}" class="seller-bank-account-form">
        @csrf
        <label for="bank-name">Bank name</label>
        <input id="bank-name" type="text" name="bank_name" value="{{ old('bank_name') }}" maxlength="120" autocomplete="organization" required>
        @error('bank_name')<small class="seller-wallet-field-error">{{ $message }}</small>@enderror

        <label for="bank-account-name">Account name</label>
        <input id="bank-account-name" type="text" name="account_name" value="{{ old('account_name') }}" maxlength="160" autocomplete="name" required>
        @error('account_name')<small class="seller-wallet-field-error">{{ $message }}</small>@enderror

        <label for="bank-account-number">Account number</label>
        <input id="bank-account-number" type="password" name="account_number" inputmode="numeric" autocomplete="off" minlength="6" maxlength="42" required>
        <small>Use 6 to 34 digits. Spaces and hyphens are accepted and removed before storage.</small>
        @error('account_number')<small class="seller-wallet-field-error">{{ $message }}</small>@enderror

        <button class="seller-primary-button" type="submit">Save Bank Account</button>
    </form>
</section>
@endsection
