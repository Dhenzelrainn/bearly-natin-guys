@extends('layouts.admin')

@section('title', 'Shipping Fees')
@section('page-title', 'Shipping Fees')

@section('content')
<section class="page-hero">
    <div>
        <span class="eyebrow">Bearly Logistics</span>
        <h1>Shipping fee settings</h1>
        <p>Set the base and per-zone surcharge used for each combined Seller Order shipment. Confirmed orders keep their saved quote.</p>
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
                <h2>Zone-based fee per Seller Order</h2>
                <p>Each combined Seller Order shipment is charged once, regardless of product quantity or parcel count. The default base is ₱50 with a ₱5 increment per zone level.</p>
            </div>
        </div>
        <div class="form-field">
            <label for="shipping-base-fee">Base fee (₱)</label>
            <input id="shipping-base-fee" name="base_fee" type="number" min="0" max="9999999.99" step="0.01" value="{{ old('base_fee', $baseFee) }}" required>
            <small>Zone 0 (same municipality) is the base fee.</small>
            @error('base_fee')<small class="field-error">{{ $message }}</small>@enderror
        </div>
        <div class="form-field">
            <label for="zone-surcharge-step">Surcharge per zone level (₱)</label>
            <input id="zone-surcharge-step" name="zone_surcharge_per_level" type="number" min="0" max="9999999.99" step="0.01" value="{{ old('zone_surcharge_per_level', $zoneSurchargePerLevel) }}" required>
            <small>Zone 1 adds one increment; Zone 2 adds two; Zone 3 adds three; Zone 4 adds four. Defaults produce ₱50, ₱55, ₱60, ₱65, and ₱70.</small>
            @error('zone_surcharge_per_level')<small class="field-error">{{ $message }}</small>@enderror
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
            <small>Volumetric weight (kg) = combined parcel L × W × H (cm) ÷ this divisor. It does not change the zone fee. Default: 5000.</small>
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
