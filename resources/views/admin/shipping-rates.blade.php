@extends('layouts.admin')

@section('title', 'Shipping Fees')
@section('page-title', 'Shipping Fees')

@section('content')
<section class="page-hero">
    <div>
        <span class="eyebrow">Bearly Logistics</span>
        <h1>Shipping fee settings</h1>
        <p>Set one standard Bearly shipping fee for each Seller Order, regardless of destination route or billable weight.</p>
    </div>
</section>

@if (session('success'))<div class="panel" role="status">{{ session('success') }}</div>@endif
@if ($errors->any())
    <div class="panel" role="alert">
        <strong>Review the shipping settings below.</strong>
        <ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
    </div>
@endif

<form method="post" action="{{ route('admin.shipping-rates.update') }}">
    @csrf
    @method('PUT')
    <section class="panel">
        <div class="panel-heading">
            <div>
                <span class="eyebrow">Buyer shipping fee</span>
                <h2>Standard fee per Seller Order</h2>
                <p>A checkout with multiple Sellers pays this fee once for each Seller Order/parcel. The default fee is ₱50.</p>
            </div>
        </div>
        <div class="form-field">
            <label for="seller-order-fee">Standard fee (₱)</label>
            <input id="seller-order-fee" name="seller_order_fee" type="number" min="0" max="9999999.99" step="0.01" value="{{ old('seller_order_fee', $sellerOrderFee) }}" required>
            <small>Distance and billable weight do not change this fee. Existing orders keep their saved shipping totals.</small>
            @error('seller_order_fee')<small class="field-error">{{ $message }}</small>@enderror
        </div>
    </section>

    <section class="panel">
        <div class="panel-heading">
            <div>
                <span class="eyebrow">Package estimate</span>
                <h2>Volumetric divisor</h2>
                <p>This affects the billable-weight estimate shown with the quote, not the standard Buyer shipping fee.</p>
            </div>
        </div>
        <div class="form-field">
            <label for="volumetric-divisor">Volumetric divisor</label>
            <input id="volumetric-divisor" name="volumetric_divisor" type="number" min="1" max="1000000" step="1" value="{{ old('volumetric_divisor', $volumetricDivisor) }}" required>
            <small>Volumetric weight (kg) = combined parcel L × W × H (cm) ÷ this divisor. Default: 5000.</small>
            @error('volumetric_divisor')<small class="field-error">{{ $message }}</small>@enderror
        </div>
    </section>

    <section class="panel">
        <div class="form-field">
            <label for="shipping-change-reason">Change reason</label>
            <textarea id="shipping-change-reason" name="reason" rows="3" maxlength="255" required>{{ old('reason') }}</textarea>
            <small>The actor, reason, and before/after settings are recorded in the Admin audit log.</small>
            @error('reason')<small class="field-error">{{ $message }}</small>@enderror
        </div>
        <button class="button button-primary" type="submit">Save shipping settings</button>
    </section>
</form>
@endsection
