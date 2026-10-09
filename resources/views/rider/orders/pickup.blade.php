@extends('rider.layouts.app')

@section('title', 'Pickup Details')
@section('page-title', 'Pickup Details')

@section('content')
<div class="page-header">
    <div>
        <p class="page-kicker">
            Seller pickup assignment
        </p>

        <h2>
            {{ $job['id'] }}
        </h2>

        <p>
            Review the seller location, pickup window,
            assignment notes, and parcel manifest.
        </p>
    </div>

    <div class="page-actions">
        <a
            class="button"
            href="{{ route('rider.dashboard.pickups') }}"
        >
            <i data-lucide="arrow-left"></i>
            Back to pickup queue
        </a>
    </div>
</div>

<div class="workflow-steps">
    <div
        class="workflow-step
            {{ in_array(
                $job['status_raw'],
                ['assigned', 'accepted', 'arrived', 'picked_up'],
                true
            ) ? 'is-complete' : '' }}"
    >
        <span>
            <i data-lucide="check"></i>
        </span>

        <strong>
            Assigned
        </strong>
    </div>

    <div
        class="workflow-step
            {{ in_array(
                $job['status_raw'],
                ['accepted', 'arrived', 'picked_up'],
                true
            ) ? 'is-complete' : '' }}"
    >
        <span>2</span>

        <strong>
            Accepted
        </strong>
    </div>

    <div
        class="workflow-step
            {{ $job['status_raw'] === 'picked_up'
                ? 'is-complete'
                : '' }}"
    >
        <span>3</span>

        <strong>
            Picked up
        </strong>
    </div>
</div>

<div class="route-layout">
    <section class="section-stack">
        <article class="panel">
            <div class="panel-header">
                <div>
                    <h3>
                        Seller location
                    </h3>

                    <p>
                        Pickup window:
                        {{ $job['window'] }}
                    </p>
                </div>

                <span
                    class="status-badge"
                    data-status="{{ $job['status_raw'] }}"
                >
                    {{ $job['status'] }}
                </span>
            </div>

            <div class="panel-body">
                <div class="route-map">
                    <span class="map-pin">
                        <i data-lucide="store"></i>
                    </span>

                    <div class="map-meta">
                        <span>
                            <strong>
                                {{ $job['seller'] }}
                            </strong>

                            <br>

                            {{ $job['address'] }}
                        </span>

                        <span>
                            <strong>
                                {{ $job['contact'] }}
                            </strong>

                            <br>

                            Seller contact
                        </span>
                    </div>
                </div>
            </div>
        </article>

        <article class="panel">
            <div class="panel-header">
                <div>
                    <h3>
                        Package manifest
                    </h3>

                    <p>
                        Parcels attached to this pickup request.
                    </p>
                </div>
            </div>

            <div class="panel-body manifest-list">
                @forelse($job['manifest'] as $item)
                    <div class="manifest-item">
                        <span>
                            <strong>
                                {{ $item['waybill'] }}
                            </strong>

                            <small>
                                {{ $item['parcel_no'] }}
                                ·
                                {{ $item['size'] }}
                            </small>
                        </span>

                        <span
                            class="status-badge"
                            data-status="{{ Str::slug(
                                $item['status'],
                                '_'
                            ) }}"
                        >
                            {{ $item['status'] }}
                        </span>
                    </div>
                @empty
                    <p class="empty-copy">
                        No parcels are attached to this
                        pickup request.
                    </p>
                @endforelse
            </div>
        </article>
    </section>

    <aside class="section-stack">
        <section class="panel">
            <div class="panel-header">
                <div>
                    <h3>
                        Assignment summary
                    </h3>

                    <p>
                        Current Rider pickup assignment.
                    </p>
                </div>
            </div>

            <div class="panel-body detail-list">
                <div class="detail-item">
                    <small>
                        Seller
                    </small>

                    <strong>
                        {{ $job['seller'] }}
                    </strong>
                </div>

                <div class="detail-item">
                    <small>
                        Contact
                    </small>

                    <strong>
                        {{ $job['contact'] }}
                    </strong>
                </div>

                <div class="detail-item">
                    <small>
                        Parcels
                    </small>

                    <strong>
                        {{ $job['parcels'] }}
                    </strong>
                </div>

                <div class="detail-item">
                    <small>
                        Status
                    </small>

                    <strong>
                        {{ $job['status'] }}
                    </strong>
                </div>

                <div class="detail-item">
                    <small>
                        Assigned at
                    </small>

                    <strong>
                        {{ $job['assigned_at'] }}
                    </strong>
                </div>
            </div>

            @if($job['status_raw'] === 'assigned')
                <div class="panel-footer">
                    <form
                        method="POST"
                        action="{{ route(
                            'rider.orders.pickup.accept',
                            $job['id']
                        ) }}"
                        style="width:100%;"
                    >
                        @csrf

                        <button
                            type="submit"
                            class="button button-primary"
                            style="width:100%;"
                        >
                            <i data-lucide="check-circle"></i>
                            Accept pickup assignment
                        </button>
                    </form>
                </div>
            @elseif($job['status_raw'] === 'accepted')
                <div class="panel-footer">
                    <div
                        style="
                            width:100%;
                            display:grid;
                            gap:12px;
                        "
                    >
                        <div
                            class="flash-banner"
                            style="width:100%;"
                        >
                            <i data-lucide="circle-check"></i>

                            <span>
                                Pickup accepted. Verify the physical
                                parcels with the seller before confirming
                                collection.
                            </span>
                        </div>

                        <form
                            method="POST"
                            action="{{ route(
                                'rider.orders.pickup.confirm',
                                $job['id']
                            ) }}"
                        >
                            @csrf

                            <button
                                type="submit"
                                class="button button-primary"
                                style="width:100%;"
                            >
                                <i data-lucide="package-check"></i>
                                Confirm parcels picked up
                            </button>
                        </form>
                    </div>
                </div>
            @elseif($job['status_raw'] === 'picked_up')
                <div class="panel-footer">
                    <div
                        class="flash-banner"
                        style="width:100%;"
                    >
                        <i data-lucide="circle-check"></i>

                        <span>
                            Parcels collected from the seller.
                            Return them to your assigned Sorting Center
                            for intake.
                        </span>
                    </div>
                </div>
            @endif
        </section>

        <section class="panel">
            <div class="panel-header">
                <div>
                    <h3>
                        Seller instructions
                    </h3>
                </div>
            </div>

            <div class="panel-body">
                <p
                    style="
                        margin:0;
                        color:var(--muted);
                        font-size:11px;
                        line-height:1.7;
                    "
                >
                    {{ $job['instructions'] }}
                </p>
            </div>
        </section>

        @if($job['assignment_notes'])
            <section class="panel">
                <div class="panel-header">
                    <div>
                        <h3>
                            Logistics notes
                        </h3>
                    </div>
                </div>

                <div class="panel-body">
                    <p
                        style="
                            margin:0;
                            color:var(--muted);
                            font-size:11px;
                            line-height:1.7;
                        "
                    >
                        {{ $job['assignment_notes'] }}
                    </p>
                </div>
            </section>
        @endif
    </aside>
</div>
@endsection