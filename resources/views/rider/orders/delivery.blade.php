@extends('rider.layouts.app')

@section('title', 'Delivery '.$job['id'])
@section('page-title', 'Delivery Assignment')

@section('content')
<div class="page-header">
    <div>
        <p class="page-kicker">
            Delivery assignment · {{ $job['id'] }}
        </p>

        <h2>
            {{ $job['purpose'] === 'return' ? 'Return parcel to ' : 'Deliver to ' }}{{ $job['customer'] }}
        </h2>

        <p>
            @if($job['status'] === 'Assigned')
                Review the shipment details before starting this delivery.
            @elseif($job['status'] === 'Out for Delivery')
                Confirm the handoff after the recipient has received the parcel.
            @elseif($job['status'] === 'Delivered')
                This handoff is complete. Delivery proof is recorded.
            @elseif($job['status'] === 'Delivery Failed')
                Review the previous attempt before retrying this delivery.
            @else
                Review the shipment, recipient, payment, and parcel manifest.
            @endif
        </p>
    </div>

    <div class="page-actions">
        <a
            class="button"
            href="{{ route('rider.dashboard.deliveries') }}"
        >
            <i data-lucide="arrow-left"></i>
            Back to route
        </a>
    </div>
</div>

<div class="workflow-steps">
    <div
        class="workflow-step
        {{
            $job['status'] === 'Assigned'
                ? 'is-current'
                : 'is-complete'
        }}"
    >
        <span>
            @if($job['status'] === 'Assigned')
                1
            @else
                <i data-lucide="check"></i>
            @endif
        </span>

        <strong>Assigned</strong>
    </div>

    <div
        class="workflow-step
        {{
            $job['status'] === 'Out for Delivery'
                ? 'is-current'
                : (
                    $job['status'] === 'Delivered'
                        ? 'is-complete'
                        : ''
                )
        }}"
    >
        <span>2</span>
        <strong>Out for delivery</strong>
    </div>

    <div
        class="workflow-step
        {{
            $job['status'] === 'Delivered'
                ? 'is-complete'
                : ''
        }}"
    >
        <span>3</span>
        <strong>{{ $job['purpose'] === 'return' ? 'Seller handoff' : 'Customer handoff' }}</strong>
    </div>
</div>

<div class="route-layout delivery-detail-layout">
    <section class="section-stack delivery-detail-main">
        <article class="panel delivery-dispatch-panel">
            <div class="panel-header">
                <div>
                    <h3>
                        {{ $job['purpose'] === 'return' ? 'Seller return destination' : 'Buyer delivery destination' }}
                    </h3>

                    <p>
                        {{ $job['purpose'] === 'return' ? 'Seller address recorded on the return shipment.' : 'Buyer address snapshot from the order.' }}
                    </p>
                </div>

                <span
                    class="status-badge"
                    data-status="{{ $job['status'] }}"
                >
                    {{ $job['status'] }}
                </span>
            </div>

            <div class="panel-body">
                <div class="detail-list">
                    <div class="detail-item">
                        <small>Recipient</small>

                        <strong>
                            {{ $job['customer'] }}
                        </strong>
                    </div>

                    <div class="detail-item">
                        <small>Contact</small>

                        <strong>
                            {{ $job['contact'] ?: 'Not provided' }}
                        </strong>
                    </div>

                    <div class="detail-item">
                        <small>{{ $job['purpose'] === 'return' ? 'Seller address' : 'Delivery address' }}</small>

                        <strong>
                            {{ $job['address'] }}
                        </strong>
                    </div>

                    <div class="detail-item">
                        <small>Zone</small>

                        <strong>
                            {{ $job['zone'] }}
                        </strong>
                    </div>
                </div>

                @if($job['contact'])
                    <div
                        class="page-actions"
                        style="margin-top:14px"
                    >
                        <a
                            class="button"
                            href="tel:{{ $job['contact'] }}"
                        >
                            <i data-lucide="phone"></i>
                            {{ $job['purpose'] === 'return' ? 'Call seller' : 'Call recipient' }}
                        </a>
                    </div>
                @endif
            </div>
        </article>

        <article class="panel">
            <div class="panel-header">
                <div>
                    <h3>
                        Dispatch assignment
                    </h3>

                    <p>
                        Logistics release information.
                    </p>
                </div>
            </div>

            <div class="panel-body detail-list delivery-dispatch-grid">
                <div class="detail-item">
                    <small>Dispatch batch</small>

                    <strong>
                        {{ $job['batch_no'] }}
                    </strong>
                </div>

                <div class="detail-item">
                    <small>Shipment</small>

                    <strong>
                        {{ $job['id'] }}
                    </strong>
                </div>

                <div class="detail-item">
                    <small>Waybill</small>

                    <strong>
                        {{ $job['waybill'] }}
                    </strong>
                </div>

                <div class="detail-item">
                    <small>Released</small>

                    <strong>
                        {{
                            $job['dispatched_at']
                                ? $job['dispatched_at']
                                    ->format(
                                        'M j, Y g:i A'
                                    )
                                : 'Not recorded'
                        }}
                    </strong>
                </div>
            </div>
        </article>
    </section>

    <aside class="section-stack delivery-detail-aside">
        <section class="panel delivery-payment-panel">
            <div class="panel-header">
                <div>
                    <h3>
                        Payment & collection
                    </h3>

                    <p>
                        Check the payment state and any cash due at handoff.
                    </p>
                </div>
            </div>

            <div class="delivery-payment-grid">
                <div class="delivery-payment-state">
                    <small>Payment status</small>

                    <span
                        class="status-badge"
                        data-status="{{ $job['payment_status'] }}"
                    >
                        {{
                            str($job['payment_status'])
                                ->replace('_', ' ')
                                ->title()
                        }}
                    </span>
                </div>

                <div class="delivery-cod-summary {{ $job['cod_minor'] > 0 ? 'is-cod' : '' }}">
                    <small>
                        {{ $job['cod_minor'] > 0 ? 'Collect from recipient' : 'Cash collection' }}
                    </small>

                    <strong>
                        @if($job['cod_minor'] > 0)
                            ₱{{ number_format($job['cod_minor'] / 100, 2) }}
                        @else
                            None due
                        @endif
                    </strong>

                    <span>
                        {{ $job['cod_minor'] > 0 ? 'Cash on delivery' : 'No COD for this shipment' }}
                    </span>
                </div>
            </div>
        </section>

        <section class="panel delivery-manifest-panel">
            <div class="panel-header">
                <div>
                    <h3>
                        Parcel manifest
                    </h3>

                    <p>
                        {{ $job['parcel_count'] }}
                        {{ Str::plural(
                            'parcel',
                            $job['parcel_count']
                        ) }}
                        assigned for this shipment.
                    </p>
                </div>
            </div>

            <div class="delivery-parcel-list">
                @foreach($job['parcels'] as $parcel)
                    <div class="delivery-parcel-row">
                        <span class="delivery-parcel-copy">
                            <strong>{{ $parcel['parcel_no'] }}</strong>

                            <small>
                                {{ $parcel['size_class'] ?: 'Unclassified' }}

                                @if($parcel['weight_kg'])
                                    · {{ $parcel['weight_kg'] }} kg
                                @endif
                            </small>
                        </span>

                        <span
                            class="status-badge"
                            data-status="{{ $parcel['status'] }}"
                        >
                            {{
                                str($parcel['status'])
                                    ->replace('_', ' ')
                                    ->title()
                            }}
                        </span>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="panel delivery-action-panel">
            <div class="panel-header">
                <div>
                    <h3>
                        @if($job['status'] === 'Assigned')
                            Start this delivery
                        @elseif($job['status'] === 'Out for Delivery')
                            Complete delivery
                        @elseif($job['status'] === 'Delivered')
                            Delivery complete
                        @elseif($job['status'] === 'Delivery Failed')
                            Retry delivery
                        @else
                            Delivery action
                        @endif
                    </h3>

                    <p>
                        @if($job['status'] === 'Assigned')
                            Begin the route when you are ready to travel.
                        @elseif($job['status'] === 'Out for Delivery')
                            Record who received the parcel and attach a handoff photo.
                        @elseif($job['status'] === 'Delivered')
                            This assignment is complete and read-only.
                        @elseif($job['status'] === 'Delivery Failed')
                            Review the previous attempt before retrying.
                        @else
                            Follow the current delivery status.
                        @endif
                    </p>
                </div>
            </div>

            <div class="panel-body delivery-action-body">
                @if($job['status'] === 'Assigned')
                    <div class="delivery-action-note">
                        <i data-lucide="navigation"></i>
                        <span>
                            <strong>Ready to head to the recipient?</strong>
                            <small>Starting marks the assigned parcels as out for delivery.</small>
                        </span>
                    </div>

                    <form
                        method="POST"
                        action="{{ route('rider.orders.delivery.start', $job['id']) }}"
                    >
                        @csrf

                        <button
                            class="button button-primary delivery-primary-action"
                            type="submit"
                        >
                            <i data-lucide="bike"></i>
                            Start delivery
                        </button>
                    </form>
                @elseif($job['status'] === 'Out for Delivery')
                    @if($job['cod_minor'] > 0)
                        <div class="delivery-cod-callout" role="note">
                            <i data-lucide="banknote"></i>
                            <span>
                                <strong>Collect ₱{{ number_format($job['cod_minor'] / 100, 2) }} in cash</strong>
                                <small>Cash on delivery. Confirm the amount with the recipient at handoff.</small>
                            </span>
                        </div>
                    @endif

                    <form
                        class="delivery-form"
                        method="POST"
                        action="{{ route('rider.orders.delivery.confirm', $job['id']) }}"
                        enctype="multipart/form-data"
                    >
                        @csrf

                        <div class="delivery-form-grid">
                            @if($job['purpose'] === 'outbound' && $job['cod_minor'] > 0)
                                <label class="delivery-form-field">
                                    <span class="delivery-field-label">Cash actually collected <span class="delivery-required">Required</span></span>
                                    <input
                                        class="delivery-control"
                                        id="delivery-cod-collected-amount"
                                        type="number"
                                        name="cod_collected_amount"
                                        value="{{ old('cod_collected_amount') }}"
                                        min="0"
                                        step="0.01"
                                        inputmode="decimal"
                                        placeholder="{{ number_format($job['cod_minor'] / 100, 2, '.', '') }}"
                                        aria-invalid="{{ $errors->has('cod_collected_amount') ? 'true' : 'false' }}"
                                        aria-describedby="delivery-cod-help{{ $errors->has('cod_collected_amount') ? ' delivery-cod-error' : '' }}"
                                        required
                                    >
                                    <small class="delivery-field-help" id="delivery-cod-help">Enter the cash received. It must match the ₱{{ number_format($job['cod_minor'] / 100, 2) }} COD amount exactly.</small>
                                    @error('cod_collected_amount')
                                        <small class="delivery-field-error" id="delivery-cod-error" role="alert">{{ $message }}</small>
                                    @enderror
                                </label>
                            @endif

                            <label class="delivery-form-field">
                                <span class="delivery-field-label">
                                    {{ $job['purpose'] === 'return' ? 'Seller / recipient name' : 'Received by' }} <span class="delivery-required">Required</span>
                                </span>

                                <input
                                    class="delivery-control"
                                    id="delivery-recipient-name"
                                    type="text"
                                    name="recipient_name"
                                    value="{{ old('recipient_name') }}"
                                    maxlength="160"
                                    autocomplete="name"
                                    aria-invalid="{{ $errors->has('recipient_name') ? 'true' : 'false' }}"
                                    aria-describedby="delivery-recipient-help{{ $errors->has('recipient_name') ? ' delivery-recipient-error' : '' }}"
                                    required
                                >

                                <small class="delivery-field-help" id="delivery-recipient-help">
                                    {{ $job['purpose'] === 'return' ? 'Enter the Seller representative who accepted the returned parcel.' : 'Enter the name of the person who accepted the parcel.' }}
                                </small>

                                @error('recipient_name')
                                    <small class="delivery-field-error" id="delivery-recipient-error" role="alert">{{ $message }}</small>
                                @enderror
                            </label>

                            <label class="delivery-form-field">
                                <span class="delivery-field-label">
                                    Delivery proof photo <span class="delivery-required">Required</span>
                                </span>

                                <input
                                    class="delivery-control delivery-file-control"
                                    id="delivery-proof-photo"
                                    type="file"
                                    name="proof_photo"
                                    accept="image/jpeg,image/png,image/webp"
                                    capture="environment"
                                    aria-invalid="{{ $errors->has('proof_photo') ? 'true' : 'false' }}"
                                    aria-describedby="delivery-proof-help{{ $errors->has('proof_photo') ? ' delivery-proof-error' : '' }}"
                                    required
                                >

                                <small class="delivery-field-help" id="delivery-proof-help">
                                    JPG, PNG, or WebP · up to 5 MB.
                                </small>

                                @error('proof_photo')
                                    <small class="delivery-field-error" id="delivery-proof-error" role="alert">{{ $message }}</small>
                                @enderror
                            </label>

                            <label class="delivery-form-field delivery-form-field-wide">
                                <span class="delivery-field-label">
                                    Handoff notes <span class="delivery-optional">Optional</span>
                                </span>

                                <textarea
                                    class="delivery-control delivery-textarea"
                                    id="delivery-notes"
                                    name="notes"
                                    rows="3"
                                    maxlength="1000"
                                    aria-invalid="{{ $errors->has('notes') ? 'true' : 'false' }}"
                                    aria-describedby="delivery-notes-help{{ $errors->has('notes') ? ' delivery-notes-error' : '' }}"
                                >{{ old('notes') }}</textarea>

                                <small class="delivery-field-help" id="delivery-notes-help">
                                    Add a short note only if it helps explain the handoff.
                                </small>

                                @error('notes')
                                    <small class="delivery-field-error" id="delivery-notes-error" role="alert">{{ $message }}</small>
                                @enderror
                            </label>
                        </div>

                        <button
                            class="button button-primary delivery-primary-action"
                            type="submit"
                        >
                            <i data-lucide="circle-check"></i>
                            Confirm delivery
                        </button>
                    </form>

                    <details
                        class="delivery-failure"
                        @if($errors->has('failure_reason') || $errors->has('next_attempt_at')) open @endif
                    >
                        <summary>
                            <span class="delivery-failure-heading">
                                <i data-lucide="triangle-alert"></i>
                                <span>
                                    <strong>Report a failed attempt</strong>
                                    <small>Use this only if the recipient could not accept the parcel.</small>
                                </span>
                            </span>
                            <i class="delivery-failure-chevron" data-lucide="chevron-down"></i>
                        </summary>

                        <form
                            class="delivery-form delivery-failure-form"
                            method="POST"
                            action="{{ route('rider.orders.delivery.fail', $job['id']) }}"
                        >
                            @csrf

                            <label class="delivery-form-field">
                                <span class="delivery-field-label">
                                    Reason for unsuccessful attempt <span class="delivery-required">Required</span>
                                </span>

                                <textarea
                                    class="delivery-control delivery-textarea"
                                    id="delivery-failure-reason"
                                    name="failure_reason"
                                    rows="3"
                                    maxlength="500"
                                    aria-invalid="{{ $errors->has('failure_reason') ? 'true' : 'false' }}"
                                    aria-describedby="delivery-failure-help{{ $errors->has('failure_reason') ? ' delivery-failure-error' : '' }}"
                                    required
                                >{{ old('failure_reason') }}</textarea>

                                <small class="delivery-field-help" id="delivery-failure-help">
                                    For example: recipient unavailable or address could not be located.
                                </small>

                                @error('failure_reason')
                                    <small class="delivery-field-error" id="delivery-failure-error" role="alert">{{ $message }}</small>
                                @enderror
                            </label>

                            <div class="delivery-form-grid">
                                <label class="delivery-form-field">
                                    <span class="delivery-field-label">
                                        Retry schedule <span class="delivery-optional">Optional</span>
                                    </span>

                                    <input
                                        class="delivery-control"
                                        id="delivery-next-attempt"
                                        type="datetime-local"
                                        name="next_attempt_at"
                                        value="{{ old('next_attempt_at') }}"
                                        aria-invalid="{{ $errors->has('next_attempt_at') ? 'true' : 'false' }}"
                                        aria-describedby="delivery-next-attempt-help{{ $errors->has('next_attempt_at') ? ' delivery-next-attempt-error' : '' }}"
                                    >

                                    <small class="delivery-field-help" id="delivery-next-attempt-help">
                                        Leave blank if no retry time is scheduled.
                                    </small>

                                    @error('next_attempt_at')
                                        <small class="delivery-field-error" id="delivery-next-attempt-error" role="alert">{{ $message }}</small>
                                    @enderror
                                </label>

                                <label class="delivery-form-field">
                                    <span class="delivery-field-label">
                                        Additional notes <span class="delivery-optional">Optional</span>
                                    </span>

                                    <textarea
                                        class="delivery-control delivery-textarea"
                                        id="delivery-failure-notes"
                                        name="notes"
                                        rows="2"
                                        maxlength="1000"
                                        aria-describedby="delivery-failure-notes-help"
                                    >{{ old('notes') }}</textarea>

                                    <small class="delivery-field-help" id="delivery-failure-notes-help">
                                        Up to 1,000 characters.
                                    </small>
                                </label>
                            </div>

                            <button class="button delivery-failure-submit" type="submit">
                                <i data-lucide="triangle-alert"></i>
                                Report failed delivery
                            </button>
                        </form>
                    </details>
                @elseif($job['status'] === 'Delivery Failed')
                    <div class="delivery-action-note is-warning">
                        <i data-lucide="rotate-ccw"></i>
                        <span>
                            <strong>Previous attempt was unsuccessful.</strong>
                            <small>Review the route and retry when another attempt is appropriate.</small>
                        </span>
                    </div>

                    <form
                        method="POST"
                        action="{{ route('rider.orders.delivery.retry', $job['id']) }}"
                    >
                        @csrf

                        <button class="button button-primary delivery-primary-action" type="submit">
                            <i data-lucide="rotate-ccw"></i>
                            Retry delivery
                        </button>
                    </form>
                @elseif($job['status'] === 'Delivered')
                    <div class="delivery-action-note is-success" role="status">
                        <i data-lucide="circle-check"></i>
                        <span>
                            <strong>Delivery completed.</strong>
                            <small>The recipient handoff and proof have been recorded.</small>
                        </span>
                    </div>
                @else
                    <div class="delivery-action-note is-warning" role="status">
                        <i data-lucide="triangle-alert"></i>
                        <span>
                            <strong>No action is available for this delivery.</strong>
                            <small>Return to your delivery list or contact Logistics if this looks unexpected.</small>
                        </span>
                    </div>
                @endif
            </div>
        </section>
    </aside>
</div>
@endsection
