@extends('layouts.admin')

@section('title', 'Complaints & Disputes')

@section('page-title', 'Manage Complaints & Disputes')

@section('content')

@php
    $hasDisputes = count($disputes) > 0;

    $active = $disputes[0] ?? [
        'database_id' => null,
        'id' => 'No open cases',
        'subject' => 'Resolution queue is clear',
        'buyer' => '—',
        'seller' => '—',
        'courier' => '—',
        'amount' => '—',
        'priority' => 'Normal',
        'status' => 'Open',
        'opened' => '—',
        'summary' => 'There are currently no open dispute cases requiring administrative review.',
        'assignee' => 'Unassigned',
        'response_due' => null,
        'resolution' => null,
        'note_url' => null,
        'resolve_url' => null,
        'evidence' => [],
        'timeline' => [],
        'internalNote' => '',
        'resolutionOutcome' => '',
    ];
@endphp


<section class="page-hero">

    <div>
        <span class="eyebrow">
            Compliance & Disputes
        </span>

        <h1>
            Manage complaints and disputes
        </h1>

        <p>
            Review complaint details and evidence, then coordinate with Buyer,
            Seller, Logistics, and Rider from one resolution workspace.
        </p>
    </div>


    <div class="hero-actions">

        <button
            class="button button-secondary"
            type="button"
            data-dispute-refresh
        >
            <i data-lucide="refresh-cw"></i>
            Refresh cases
        </button>

    </div>

</section>


<section class="dispute-layout">

    <aside class="panel dispute-list-panel">

        <div class="panel-heading">

            <div>
                <span class="eyebrow">
                    Open cases
                </span>

                <h2>
                    Resolution queue
                </h2>
            </div>


            <span class="status-badge badge-warning">

                <span data-dispute-open-count>
                    {{ count($disputes) }}
                </span>

                open

            </span>

        </div>


        <label class="field-with-icon">

            <i data-lucide="search"></i>

            <input
                type="search"
                placeholder="Search case ID or subject..."
                data-dispute-search
            >

        </label>


        <div class="case-list">

            @foreach ($disputes as $index => $dispute)

                <button
                    type="button"
                    class="case-card {{ $index === 0 ? 'is-active' : '' }}"
                    data-case-card
                    data-case-id="{{ $dispute['id'] }}"
                    data-case-search="{{
                        strtolower(
                            $dispute['id'].' '.
                            $dispute['subject'].' '.
                            $dispute['buyer'].' '.
                            $dispute['seller']
                        )
                    }}"
                >

                    <div class="case-card-top">

                        <span>
                            {{ $dispute['id'] }}
                        </span>

                        <span
                            class="priority-dot
                            priority-{{ strtolower($dispute['priority']) }}"
                        >
                            {{ $dispute['priority'] }}
                        </span>

                    </div>


                    <strong>
                        {{ $dispute['subject'] }}
                    </strong>


                    <small>
                        {{ $dispute['buyer'] }}
                        vs
                        {{ $dispute['seller'] }}
                    </small>


                    <div class="case-card-bottom">

                        <span>
                            {{ $dispute['opened'] }}
                        </span>

                        <span>
                            {{ $dispute['status'] }}
                        </span>

                    </div>

                </button>

            @endforeach


            @if (! $hasDisputes)

                <div class="table-empty">

                    <i data-lucide="inbox"></i>

                    <strong>
                        No open disputes
                    </strong>

                    <span>
                        There are currently no complaint or dispute cases
                        requiring administrative review.
                    </span>

                </div>

            @endif

        </div>

    </aside>


    <div class="dispute-workspace">

        <article class="panel dispute-detail-card">

            <div class="panel-heading">

                <div>

                    <span
                        class="eyebrow"
                        data-dispute-id
                    >
                        {{ $active['id'] }}
                    </span>


                    <h2 data-dispute-subject>
                        {{ $active['subject'] }}
                    </h2>


                    <p>

                        <span data-dispute-status>
                            {{ $active['status'] }}
                        </span>

                        •

                        <span data-dispute-opened>
                            {{ $active['opened'] }}
                        </span>

                    </p>

                </div>


                <div class="row-actions">

                    <span
                        class="status-badge
                        {{
                            in_array(
                                strtolower($active['priority']),
                                ['high', 'urgent'],
                                true
                            )
                                ? 'badge-danger'
                                : (
                                    strtolower($active['priority']) === 'medium'
                                        ? 'badge-warning'
                                        : 'badge-info'
                                )
                        }}"
                        data-dispute-priority
                    >
                        {{ $active['priority'] }} priority
                    </span>


                    <button
                        class="button button-primary button-small"
                        type="button"
                        data-dispute-resolve
                        @if (! $hasDisputes) disabled @endif
                    >
                        <i data-lucide="circle-check"></i>

                        Resolve case
                    </button>

                </div>

            </div>


            <div class="detail-grid dispute-summary-grid">

                <div>
                    <span>
                        Buyer
                    </span>

                    <strong data-dispute-buyer>
                        {{ $active['buyer'] }}
                    </strong>
                </div>


                <div>
                    <span>
                        Seller
                    </span>

                    <strong data-dispute-seller>
                        {{ $active['seller'] }}
                    </strong>
                </div>


                <div>
                    <span>
                        Rider
                    </span>

                    <strong data-dispute-courier>
                        {{ $active['courier'] }}
                    </strong>
                </div>


                <div>
                    <span>
                        Order value
                    </span>

                    <strong data-dispute-amount>
                        {{ $active['amount'] }}
                    </strong>
                </div>

            </div>


            <div class="complaint-copy">

                <span class="section-label">
                    Complaint summary
                </span>

                <p data-dispute-summary>
                    {{ $active['summary'] }}
                </p>

            </div>

        </article>


        <div
            class="dashboard-grid
            dashboard-grid-secondary
            dispute-subgrid"
        >

            <article class="panel">

                <div class="panel-heading">

                    <div>
                        <span class="eyebrow">
                            Supporting evidence
                        </span>

                        <h2>
                            Case files
                        </h2>
                    </div>

                </div>


                <div
                    class="evidence-grid"
                    data-dispute-evidence
                >

                    @foreach ($active['evidence'] as $item)

                        <button
                            type="button"
                            class="evidence-card"
                            data-evidence-id="{{ $item['id'] }}"
                        >

                            <span>

                                <i
                                    data-lucide="{{
                                        $item['type'] === 'Image'
                                            ? 'image'
                                            : 'file-text'
                                    }}"
                                ></i>

                            </span>


                            <div>

                                <strong>
                                    {{ $item['label'] }}
                                </strong>

                                <small>
                                    {{ $item['meta'] }}
                                </small>

                            </div>


                            <i data-lucide="external-link"></i>

                        </button>

                    @endforeach


                    @if (empty($active['evidence']))

                        <div class="table-empty">

                            <i data-lucide="file-x"></i>

                            <strong>
                                No evidence submitted
                            </strong>

                            <span>
                                Supporting files will appear here once
                                participants submit evidence.
                            </span>

                        </div>

                    @endif

                </div>

            </article>


            <article class="panel">

                <div class="panel-heading">

                    <div>
                        <span class="eyebrow">
                            Case timeline
                        </span>

                        <h2>
                            Recent updates
                        </h2>
                    </div>

                </div>


                <div
                    class="timeline-list"
                    data-dispute-timeline
                >

                    @foreach ($active['timeline'] as $item)

                        <div class="timeline-row">

                            <span>
                                {{ $item['time'] }}
                            </span>

                            <div>

                                <i></i>

                                <p>
                                    {{ $item['text'] }}
                                </p>

                            </div>

                        </div>

                    @endforeach


                    @if (empty($active['timeline']))

                        <div class="table-empty">

                            <i data-lucide="history"></i>

                            <strong>
                                No case activity yet
                            </strong>

                            <span>
                                Dispute events will appear here as the case
                                progresses.
                            </span>

                        </div>

                    @endif

                </div>

            </article>

        </div>


        <article class="panel coordination-panel">

            <div class="panel-heading">

                <div>

                    <span class="eyebrow">
                        Multi-party coordination
                    </span>

                    <h2>
                        Contact participants
                    </h2>

                </div>


                <span class="status-badge badge-info">
                    Participant messaging
                </span>

            </div>


            <div class="coordination-grid">

                <div class="party-card">

                    <span class="party-icon">
                        <i data-lucide="shopping-bag"></i>
                    </span>


                    <div>

                        <small>
                            Buyer
                        </small>

                        <strong data-party-buyer>
                            {{ $active['buyer'] }}
                        </strong>

                    </div>


                    <button
                        type="button"
                        class="button button-ghost button-small"
                        data-party-message="buyer"
                        @if (! $hasDisputes) disabled @endif
                    >
                        <i data-lucide="message-circle"></i>

                        Message
                    </button>

                </div>


                <div class="party-card">

                    <span class="party-icon">
                        <i data-lucide="store"></i>
                    </span>


                    <div>

                        <small>
                            Seller
                        </small>

                        <strong data-party-seller>
                            {{ $active['seller'] }}
                        </strong>

                    </div>


                    <button
                        type="button"
                        class="button button-ghost button-small"
                        data-party-message="seller"
                        @if (! $hasDisputes) disabled @endif
                    >
                        <i data-lucide="message-circle"></i>

                        Message
                    </button>

                </div>


                <div class="party-card">

                    <span class="party-icon">
                        <i data-lucide="bike"></i>
                    </span>


                    <div>

                        <small>
                            Rider
                        </small>

                        <strong data-party-courier>
                            {{ $active['courier'] }}
                        </strong>

                    </div>


                    <button
                        type="button"
                        class="button button-ghost button-small"
                        data-party-message="courier"
                        @if (! $hasDisputes) disabled @endif
                    >
                        <i data-lucide="message-circle"></i>

                        Message
                    </button>

                </div>

            </div>


            <label class="form-field">

                <span>
                    Internal resolution note
                </span>

                <textarea
                    rows="4"
                    placeholder="Document the admin decision or next coordination step..."
                    data-dispute-note
                    @if (! $hasDisputes) disabled @endif
                >{{ $active['internalNote'] ?? '' }}</textarea>

            </label>


            <div class="panel-footer-actions">

                <button
                    class="button button-secondary"
                    type="button"
                    data-dispute-save-note
                    @if (! $hasDisputes) disabled @endif
                >
                    Save note
                </button>


                <button
                    class="button button-primary"
                    type="button"
                    data-dispute-send-update
                    @if (! $hasDisputes) disabled @endif
                >
                    <i data-lucide="send"></i>

                    Send case update
                </button>

            </div>

        </article>

    </div>

</section>


<div
    class="modal-shell"
    data-modal="resolve-dispute"
    hidden
>

    <button
        class="modal-backdrop"
        type="button"
        data-close-modal
    ></button>


    <section class="modal-card modal-card-medium">

        <div class="modal-heading">

            <div>

                <span class="eyebrow">
                    Case resolution
                </span>

                <h2>
                    Resolve
                    <span data-resolve-case-id>
                        case
                    </span>
                </h2>

            </div>


            <button
                class="icon-button"
                type="button"
                data-close-modal
            >
                <i data-lucide="x"></i>
            </button>

        </div>


        <div class="modal-body">

            <label class="form-field">

                <span>
                    Resolution outcome
                </span>


                <select
                    class="select-field"
                    data-resolution-outcome
                >

                    <option value="">
                        Select outcome
                    </option>

                    <option value="buyer_favored">
                        Buyer favored
                    </option>

                    <option value="seller_favored">
                        Seller favored
                    </option>

                    <option value="partial_refund_approved">
                        Partial refund approved
                    </option>

                    <option value="replacement_arranged">
                        Replacement arranged
                    </option>

                </select>

            </label>


            <label class="form-field">

                <span>
                    Resolution note
                </span>


                <textarea
                    rows="5"
                    placeholder="Explain the final administrative decision..."
                    data-resolution-note
                ></textarea>


                <small
                    data-resolution-error
                    hidden
                >
                    Please select an outcome and add a resolution note.
                </small>

            </label>

        </div>


        <div class="modal-footer">

            <button
                class="button button-secondary"
                type="button"
                data-close-modal
            >
                Cancel
            </button>


            <button
                class="button button-primary"
                type="button"
                data-confirm-resolution
            >
                <i data-lucide="circle-check"></i>

                Confirm resolution
            </button>

        </div>

    </section>

</div>


<script
    type="application/json"
    id="dispute-preview-data"
>
{!! json_encode(
    collect($disputes)->mapWithKeys(function ($dispute) {
        return [
            $dispute['id'] => [
                'database_id' => $dispute['database_id'] ?? null,
                'id' => $dispute['id'],
                'subject' => $dispute['subject'],
                'buyer' => $dispute['buyer'],
                'seller' => $dispute['seller'],
                'courier' => $dispute['courier'],

                'buyer_user_id' =>
                    $dispute['buyer_user_id'] ?? null,

                'seller_user_id' =>
                    $dispute['seller_user_id'] ?? null,

                'rider_user_id' =>
                    $dispute['rider_user_id'] ?? null,

                'logistics_user_id' =>
                    $dispute['logistics_user_id'] ?? null,
                'amount' => $dispute['amount'],
                'priority' => $dispute['priority'],
                'status' => $dispute['status'],
                'opened' => $dispute['opened'],
                'summary' => $dispute['summary'],
                'assignee' => $dispute['assignee'] ?? 'Unassigned',
                'response_due' => $dispute['response_due'] ?? null,
                'resolution' => $dispute['resolution'] ?? null,

                'note_url' => $dispute['note_url'] ?? null,

                'resolve_url' => $dispute['resolve_url'] ?? null,

                'message_url' => $dispute['message_url'] ?? null,
                
                'update_url' => $dispute['update_url'] ?? null,

                'evidence' => $dispute['evidence'] ?? [],

                'timeline' => $dispute['timeline'] ?? [],

                'internalNote' => $dispute['internalNote'] ?? '',

                'resolutionOutcome' =>
                    $dispute['resolutionOutcome'] ?? '',
            ],
        ];
    }),
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
) !!}
</script>

@endsection