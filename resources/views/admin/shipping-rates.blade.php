@extends('layouts.admin')

@section('title', 'Shipping Rates')
@section('page-title', 'Shipping Rates')

@section('content')
<section class="page-hero">
    <div>
        <span class="eyebrow">Bearly Logistics</span>
        <h1>Shipping rate management</h1>
        <p>Configure Bearly's initial estimated shipping fees by route. These are Bearly rates, not official Shopee tariffs.</p>
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
                <span class="eyebrow">Internal Delivery Provider</span>
                <h2>Bearly Logistics</h2>
                <p>Shipping is priced once per Seller Order. One Seller's products are combined into an estimated parcel.</p>
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
        <div class="panel-heading">
            <div>
                <span class="eyebrow">Route Tariffs</span>
                <h2>First kilogram and additional kilograms</h2>
                <p>Leave an additional-kilogram surcharge blank to prevent checkout for parcels over 1 billable kg on that route.</p>
            </div>
        </div>
        <div class="shipping-rate-table-wrap">
            <table class="admin-table">
                <thead><tr><th>Route tier</th><th>First billable kg (₱)</th><th>Each additional kg (₱)</th></tr></thead>
                <tbody>
                @foreach ($tiers as $tier)
                    <tr>
                        <td><strong>{{ $tier['label'] }}</strong><small>{{ $tier['description'] }}</small></td>
                        <td>
                            <label class="sr-only" for="base-{{ $tier['key'] }}">{{ $tier['label'] }} first kilogram fee</label>
                            <input id="base-{{ $tier['key'] }}" name="rates[{{ $tier['key'] }}][first_kg_fee]" type="number" min="0" max="9999999.99" step="0.01" value="{{ old("rates.{$tier['key']}.first_kg_fee", $tier['base_fee']) }}" required>
                            @error("rates.{$tier['key']}.first_kg_fee")<small class="field-error">{{ $message }}</small>@enderror
                        </td>
                        <td>
                            <label class="sr-only" for="extra-{{ $tier['key'] }}">{{ $tier['label'] }} additional kilogram surcharge</label>
                            <input id="extra-{{ $tier['key'] }}" name="rates[{{ $tier['key'] }}][additional_kg_surcharge]" type="number" min="0" max="9999999.99" step="0.01" value="{{ old("rates.{$tier['key']}.additional_kg_surcharge", $tier['additional_fee']) }}" placeholder="Not configured">
                            @error("rates.{$tier['key']}.additional_kg_surcharge")<small class="field-error">{{ $message }}</small>@enderror
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <p class="settings-note">Each quote snapshots the provider, route, rates, divisor, package estimate, and calculated fee. Later rate edits do not change existing orders.</p>
    </section>
    <section class="panel">
        <div class="form-field">
            <label for="shipping-change-reason">Change reason</label>
            <textarea id="shipping-change-reason" name="reason" rows="3" maxlength="255" required>{{ old('reason') }}</textarea>
            <small>This reason and the before/after rates are recorded in the Admin audit log.</small>
            @error('reason')<small class="field-error">{{ $message }}</small>@enderror
        </div>
        <button class="button button-primary" type="submit">Save shipping rates</button>
    </section>
</form>
@endsection
