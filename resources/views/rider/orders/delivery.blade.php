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
            Deliver to {{ $job['customer'] }}
        </h2>

        <p>
            Review the shipment, recipient, payment,
            and parcel manifest before beginning
            the delivery workflow.
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
        <strong>Customer handoff</strong>
    </div>
</div>

<div class="route-layout">
    <section class="section-stack">
        <article class="panel">
            <div class="panel-header">
                <div>
                    <h3>
                        Delivery destination
                    </h3>

                    <p>
                        Real recipient snapshot from
                        the order.
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
                        <small>Delivery address</small>

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
                            Call recipient
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

            <div class="panel-body detail-list">
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

    <aside class="section-stack">
        <section class="panel">
            <div class="panel-header">
                <div>
                    <h3>
                        Payment details
                    </h3>

                    <p>
                        Order and shipment payment state.
                    </p>
                </div>
            </div>

            <div class="panel-body detail-list">
                <div class="detail-item">
                    <small>Payment status</small>

                    <strong>
                        {{
                            str($job['payment_status'])
                                ->replace('_', ' ')
                                ->title()
                        }}
                    </strong>
                </div>

                <div class="detail-item">
                    <small>COD to collect</small>

                    <strong>
                        @if($job['cod_minor'] > 0)
                            ₱{{ number_format(
                                $job['cod_minor'] / 100,
                                2
                            ) }}
                        @else
                            None
                        @endif
                    </strong>
                </div>
            </div>
        </section>

        <section class="panel">
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

            <div class="panel-body detail-list">
                @foreach($job['parcels'] as $parcel)
                    <div class="detail-item">
                        <small>
                            {{ $parcel['parcel_no'] }}
                        </small>

                        <strong>
                            {{
                                str($parcel['status'])
                                    ->replace('_', ' ')
                                    ->title()
                            }}
                        </strong>

                        <small>
                            {{
                                $parcel['size_class']
                                    ?: 'Unclassified'
                            }}

                            @if($parcel['weight_kg'])
                                ·
                                {{ $parcel['weight_kg'] }} kg
                            @endif
                        </small>
                    </div>
                @endforeach
            </div>

            <div class="panel-footer">
                @if($job['status'] === 'Assigned')
                    <form
                        method="POST"
                        action="{{
                            route(
                                'rider.orders.delivery.start',
                                $job['id']
                            )
                        }}"
                    >
                        @csrf

                        <button
                            class="button button-primary"
                            style="width:100%"
                            type="submit"
                        >
                            <i data-lucide="bike"></i>
                            Start delivery
                        </button>
                    </form>
                @elseif($job['status'] === 'Out for Delivery')
                    <form
                        method="POST"
                        action="{{
                            route(
                                'rider.orders.delivery.confirm',
                                $job['id']
                            )
                        }}"
                        enctype="multipart/form-data"
                        style="width:100%"
                    >
                        @csrf

                        <div
                            style="
                                display:grid;
                                gap:12px;
                                margin-bottom:14px;
                            "
                        >
                            <label>
                                <span>Received by</span>

                                <input
                                    type="text"
                                    name="recipient_name"
                                    value="{{ old('recipient_name') }}"
                                    maxlength="160"
                                    required
                                >
                            </label>

                            <label>
                                <span>Delivery proof photo</span>

                                <input
                                    type="file"
                                    name="proof_photo"
                                    accept="image/jpeg,image/png,image/webp"
                                    capture="environment"
                                    required
                                >
                            </label>

                            <label>
                                <span>Notes (optional)</span>

                                <textarea
                                    name="notes"
                                    rows="3"
                                    maxlength="1000"
                                >{{ old('notes') }}</textarea>
                            </label>
                        </div>

                        <button
                            class="button button-primary"
                            style="width:100%"
                            type="submit"
                        >
                            <i data-lucide="circle-check"></i>
                            Confirm delivery
                        </button>
                    </form>
                    <form
                        method="POST"
                        action="{{ route(
                            'rider.orders.delivery.fail',
                            $job['id']
                        ) }}"
                        style="width:100%; margin-top:14px"
                    >
                        @csrf

                        <div
                            style="
                                display:grid;
                                gap:12px;
                                margin-bottom:14px;
                            "
                        >
                            <label>
                                <span>Failure reason</span>

                                <textarea
                                    name="failure_reason"
                                    rows="3"
                                    maxlength="500"
                                    required
                                >{{ old('failure_reason') }}</textarea>
                            </label>

                            <label>
                                <span>Retry schedule (optional)</span>

                                <input
                                    type="datetime-local"
                                    name="next_attempt_at"
                                    value="{{ old('next_attempt_at') }}"
                                >
                            </label>

                            <label>
                                <span>Notes (optional)</span>

                                <textarea
                                    name="notes"
                                    rows="3"
                                    maxlength="1000"
                                >{{ old('notes') }}</textarea>
                            </label>
                        </div>

                        <button
                            class="button"
                            style="width:100%"
                            type="submit"
                        >
                            <i data-lucide="triangle-alert"></i>
                            Report failed delivery
                        </button>
                    </form>

                @elseif($job['status'] === 'Delivery Failed')
                    <form
                        method="POST"
                        action="{{ route(
                            'rider.orders.delivery.retry',
                            $job['id']
                        ) }}"
                        style="width:100%"
                    >
                        @csrf

                        <button
                            class="button button-primary"
                            style="width:100%"
                            type="submit"
                        >
                            <i data-lucide="rotate-ccw"></i>
                            Retry delivery
                        </button>
                    </form>

                @elseif($job['status'] === 'Delivered')
                    <button
                        class="button button-primary"
                        style="width:100%"
                        type="button"
                        disabled
                    >
                        <i data-lucide="circle-check"></i>
                        Delivery completed
                    </button>
                @else
                    <button
                        class="button button-primary"
                        style="width:100%"
                        type="button"
                        disabled
                    >
                        <i data-lucide="triangle-alert"></i>
                        Delivery unavailable
                    </button>
                @endif
            </div>
        </section>
    </aside>
</div>
@endsection