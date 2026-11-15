@extends('layouts.seller')

@section('title', 'Packing Plan')
@section('page-title', 'Packing Plan')

@section('content')
<div class="page-heading packing-plan-heading">
    <div>
        <p class="section-kicker">Seller fulfillment · {{ $sellerOrder->seller_order_no }}</p>
        <h2>Plan the physical packages</h2>
        <p>Choose which ordered units go in each package, then confirm each package’s outside measurements.</p>
    </div>
    <a class="packing-plan-back" href="{{ route('seller.orders', ['status' => 'to-prepare']) }}">Back to Orders</a>
</div>

@if ($errors->any())
    <div class="packing-plan-errors" role="alert">
        <strong>Review the packing plan before continuing.</strong>
        <ul>
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<section class="packing-plan-note" aria-label="Packing plan rules">
    <span class="packing-plan-note-icon"><i data-lucide="package-check" aria-hidden="true"></i></span>
    <div>
        <strong>One Seller Order, one Shipment, one Waybill</strong>
        <p>By default, each ordered unit starts in its own parcel. You can combine units into fewer packages or add packages and assign the exact quantities. The checkout shipping fee does not change.</p>
    </div>
</section>

<section class="packing-plan-policy-note" aria-labelledby="packing-policy-title">
    <div class="packing-plan-policy-heading">
        <strong id="packing-policy-title">Bearly Logistics handling policies</strong>
        @if (count($shippingPolicies) > 0)
            <span>{{ count($shippingPolicies) }} published {{ count($shippingPolicies) === 1 ? 'policy' : 'policies' }}</span>
        @endif
    </div>

    @forelse ($shippingPolicies as $policy)
        <details class="packing-plan-policy-item">
            <summary>{{ $policy['title'] }} <small>{{ $policy['category'] }} · {{ $policy['published']['version'] }}</small></summary>
            @if ($policy['published']['summary'])
                <p><strong>{{ $policy['published']['summary'] }}</strong></p>
            @endif
            <div class="packing-plan-policy-body">{!! nl2br(e($policy['published']['body'])) !!}</div>
            <p class="packing-plan-policy-source"><strong>Source:</strong> {{ $policy['published']['source_reference'] }}</p>
        </details>
    @empty
        <p>No Bearly Logistics handling policies are published yet. Confirm restricted-item, packaging, or product-combination rules with Logistics before shipping special-handling goods.</p>
    @endforelse

    <p class="packing-plan-policy-boundary">This packing plan records item quantities and package measurements. It does not automatically classify restricted goods or check product compatibility.</p>
</section>

<section class="packing-plan-related-orders" aria-labelledby="packing-related-orders-title">
    <div class="packing-plan-related-orders-heading">
        <div>
            <strong id="packing-related-orders-title">Add variants from another order</strong>
            <p>Only accepted orders for this Store, Buyer, destination, and the same Product are eligible.</p>
        </div>
        @if (count($candidateSellerOrders) > 0)
            <span>{{ count($candidateSellerOrders) }} eligible {{ count($candidateSellerOrders) === 1 ? 'order' : 'orders' }}</span>
        @endif
    </div>

    @if (count($candidateSellerOrders) > 0)
        <form method="GET" action="{{ route('seller.orders.packing', $sellerOrder->id) }}" class="packing-plan-related-orders-form">
            <div class="packing-plan-related-order-list">
                @foreach ($candidateSellerOrders as $candidate)
                    @php
                        $candidateVariants = $candidate->items
                            ->map(fn ($item) => trim($item->variant_name.' · SKU '.$item->sku.' · Qty '.$item->quantity))
                            ->implode(' / ');
                    @endphp
                    <label class="packing-plan-related-order">
                        <input
                            type="checkbox"
                            name="additional_seller_order_ids[]"
                            value="{{ $candidate->id }}"
                            @checked(in_array((int) $candidate->id, $selectedAdditionalSellerOrderIds, true))
                        >
                        <span>
                            <strong>{{ $candidate->seller_order_no }}</strong>
                            <small>{{ $candidate->order?->order_no }} · {{ $candidateVariants }}</small>
                        </span>
                    </label>
                @endforeach
            </div>
            <button class="packing-plan-tool is-primary" type="submit">Add selected variants to plan</button>
        </form>
    @else
        <p class="packing-plan-related-orders-empty">No other eligible Seller Orders for this Product are available to combine.</p>
    @endif
</section>

<form class="packing-plan-form" method="POST" action="{{ route('seller.orders.packing.store', $sellerOrder->id) }}" data-packing-plan-form>
    @csrf
    @foreach ($selectedAdditionalSellerOrderIds as $additionalSellerOrderId)
        <input type="hidden" name="additional_seller_order_ids[]" value="{{ $additionalSellerOrderId }}">
    @endforeach

    <section class="packing-logistics-provider" aria-labelledby="packing-logistics-provider-title">
        <div>
            <span class="section-kicker">Pickup provider</span>
            <h3 id="packing-logistics-provider-title">
                {{ $assignedPickupProvider ? 'Assigned for your pickup city' : 'Choose a nearby Logistics provider' }}
            </h3>
            @if ($assignedPickupProvider)
                <p>{{ $assignedPickupProvider->display_name }} is assigned to your pickup municipality. The assignment stays visible even when no Rider is currently available.</p>
            @else
                <p>No active provider is assigned to your pickup municipality. Select a nearby active provider; they will review the pickup request and may accept or reject it.</p>
            @endif
        </div>

        @if ($providerOptions->isNotEmpty())
            <label class="packing-logistics-provider-field" for="packing-logistics-provider-select">
                <span>Logistics provider</span>
                <select id="packing-logistics-provider-select" name="logistics_profile_id" required data-packing-provider-select>
                    @if (! $assignedPickupProvider)
                        <option value="">Choose a nearby provider</option>
                    @endif
                    @foreach ($providerOptions as $option)
                        <option
                            value="{{ $option['id'] }}"
                            data-rider-count="{{ $option['available_rider_count'] }}"
                            data-provider-location="{{ $option['location'] }}"
                            data-distance-label="{{ $option['distance_label'] ?? '' }}"
                            @selected((string) old('logistics_profile_id', $assignedPickupProvider?->id) === (string) $option['id'])
                        >{{ $option['name'] }} · {{ $option['location'] }}@if ($option['distance_label']) · {{ $option['distance_label'] }}@endif</option>
                    @endforeach
                </select>
            </label>
            <p @class([
                'packing-logistics-provider-status',
                'is-warning' => $providerOptions->contains(fn (array $option): bool => $option['available_rider_count'] === 0),
            ]) data-packing-provider-status role="status" aria-live="polite">
                @if ($assignedPickupProvider && ($providerOptions->first()['available_rider_count'] ?? 0) === 0)
                    No Riders are currently marked available. You can still create the Waybill and submit a pickup request; Logistics will review it.
                @elseif ($assignedPickupProvider)
                    {{ $providerOptions->first()['available_rider_count'] }} pickup-capable Rider(s) are currently marked available. Availability can change before assignment.
                @elseif ($providerOptions->contains(fn (array $option): bool => $option['available_rider_count'] === 0))
                    No Riders are currently marked available for {{ $providerOptions->filter(fn (array $option): bool => $option['available_rider_count'] === 0)->pluck('name')->implode(', ') }}. You can still create the Waybill and submit a pickup request; Logistics will review it.
                @else
                    Choose a provider to see its current pickup Rider availability. No-Rider warnings do not block your choice.
                @endif
            </p>
        @else
            <p class="packing-logistics-provider-error" role="alert">{{ $providerError ?: 'No active Logistics provider is available for this pickup address.' }}</p>
        @endif
    </section>

    <section class="packing-plan-order-card" aria-labelledby="packing-order-title">
        <div class="packing-plan-order-heading">
            <div>
                <span class="section-kicker">Order contents</span>
                <h3 id="packing-order-title">{{ $sellerOrder->seller_order_no }}</h3>
            </div>
            <span class="packing-plan-unit-count">{{ collect($packingItems)->sum('quantity') }} ordered units</span>
        </div>

        <div class="packing-plan-item-list">
            @foreach ($packingItems as $item)
                <article class="packing-plan-order-item">
                    <span class="packing-plan-item-image">
                        @if (filled($item['image']))
                            <img src="{{ $item['image'] }}" alt="" loading="lazy" decoding="async">
                        @else
                            <i data-lucide="package" aria-hidden="true"></i>
                        @endif
                    </span>
                    <span class="packing-plan-item-copy">
                        <strong>{{ $item['name'] }}</strong>
                        <small>{{ $item['variant'] ?: 'Variant not specified' }} · SKU {{ $item['sku'] }}@if (!empty($item['seller_order_no'])) · {{ $item['seller_order_no'] }}@endif</small>
                    </span>
                    <span class="packing-plan-item-quantity">Qty {{ $item['quantity'] }}</span>
                </article>
            @endforeach
        </div>
    </section>

    <section class="packing-plan-editor" aria-labelledby="packing-editor-title">
        <div class="packing-plan-editor-heading">
            <div>
                <span class="section-kicker">Physical package layout</span>
                <h3 id="packing-editor-title">{{ count($initialParcels) }} {{ count($initialParcels) === 1 ? 'parcel' : 'parcels' }} in this plan</h3>
                <p data-packing-allocation-status role="status" aria-live="polite">Check that every ordered unit is allocated exactly once.</p>
            </div>
            <div class="packing-plan-tools" aria-label="Packing plan options">
                <button class="packing-plan-tool" type="button" data-packing-reset>Separate each unit</button>
                <button class="packing-plan-tool" type="button" data-packing-combine>Combine all into one</button>
                <button class="packing-plan-tool is-primary" type="button" data-packing-add><i data-lucide="plus" aria-hidden="true"></i>Add package</button>
            </div>
        </div>

        <div class="packing-plan-parcels" data-packing-parcels>
            @foreach ($initialParcels as $parcelIndex => $parcel)
                <fieldset class="packing-parcel-card" data-parcel-card>
                    <legend>Parcel {{ $parcelIndex + 1 }}</legend>
                    <div class="packing-parcel-heading">
                        <span><i data-lucide="package" aria-hidden="true"></i><strong data-parcel-title>Parcel {{ $parcelIndex + 1 }}</strong></span>
                        <button class="packing-parcel-remove" type="button" data-packing-remove aria-label="Remove parcel {{ $parcelIndex + 1 }}" @disabled(count($initialParcels) === 1)>Remove</button>
                    </div>

                    <div class="packing-parcel-items">
                        @foreach ($packingItems as $item)
                            <label class="packing-parcel-item">
                                <span><strong>{{ $item['name'] }}</strong><small>SKU {{ $item['sku'] }} · {{ $item['quantity'] }} ordered</small></span>
                                <span class="packing-parcel-quantity-field">Units in this parcel
                                    <input type="number" name="parcels[{{ $parcelIndex }}][items][{{ $item['id'] }}]" value="{{ $parcel['items'][$item['id']] ?? 0 }}" min="0" max="{{ $item['quantity'] }}" step="1" inputmode="numeric" data-packing-quantity data-item-id="{{ $item['id'] }}" aria-label="{{ $item['name'] }} units in parcel {{ $parcelIndex + 1 }}">
                                </span>
                            </label>
                        @endforeach
                    </div>

                    <div class="packing-parcel-measurements">
                        <label>Weight (kg)
                            <input type="number" name="parcels[{{ $parcelIndex }}][weight_kg]" value="{{ $parcel['weight_kg'] ?? '' }}" min="0.001" max="9999999.999" step="0.001" inputmode="decimal" required data-parcel-measurement="weight_kg">
                        </label>
                        <label>Length (cm)
                            <input type="number" name="parcels[{{ $parcelIndex }}][length_cm]" value="{{ $parcel['length_cm'] ?? '' }}" min="0.01" max="99999999.99" step="0.01" inputmode="decimal" required data-parcel-measurement="length_cm">
                        </label>
                        <label>Width (cm)
                            <input type="number" name="parcels[{{ $parcelIndex }}][width_cm]" value="{{ $parcel['width_cm'] ?? '' }}" min="0.01" max="99999999.99" step="0.01" inputmode="decimal" required data-parcel-measurement="width_cm">
                        </label>
                        <label>Height (cm)
                            <input type="number" name="parcels[{{ $parcelIndex }}][height_cm]" value="{{ $parcel['height_cm'] ?? '' }}" min="0.01" max="99999999.99" step="0.01" inputmode="decimal" required data-parcel-measurement="height_cm">
                        </label>
                    </div>
                </fieldset>
            @endforeach
        </div>
    </section>

    <section class="packing-plan-confirmation">
        <i data-lucide="scan-line" aria-hidden="true"></i>
        <p>Each parcel will get its own Parcel number and barcode. All parcel labels share the same Waybill reference. Confirm actual package measurements before generating the labels.</p>
    </section>

    <script type="application/json" data-packing-items>@json($packingItems)</script>
    <script type="application/json" data-packing-default>@json($initialParcels)</script>

    <div class="packing-plan-actions">
        <a class="packing-plan-cancel" href="{{ route('seller.orders', ['status' => 'to-prepare']) }}">Cancel</a>
        <button class="packing-plan-submit" type="submit" @disabled($providerOptions->isEmpty())><i data-lucide="clipboard-check" aria-hidden="true"></i>Save plan and create Waybill</button>
    </div>
</form>
@endsection
