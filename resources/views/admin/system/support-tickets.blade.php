@extends('layouts.admin')

@section('title', 'Support Tickets')
@section('page-title', 'Support Tickets')

@section('content')

<section class="page-hero">

    <div>
        <span class="eyebrow">Communication</span>

        <h1>Support tickets</h1>

        <p>
            Review messages submitted through the public contact form.
            Every ticket below is stored in the platform database with the
            details the sender provided.
        </p>
    </div>


    <div class="hero-context-card">

        <span class="hero-context-icon">
            <i data-lucide="life-buoy"></i>
        </span>


        <div class="hero-context-copy">

            <small>
                Open tickets
            </small>

            <strong>
                {{ $openCount }}
            </strong>

            <span>
                {{ $totalCount }} received in total
            </span>

        </div>

    </div>

</section>


<section class="panel">

    <div class="panel-heading panel-heading-wrap">

        <div>
            <span class="eyebrow">
                Contact support queue
            </span>

            <h2>
                Submitted messages
            </h2>
        </div>


        <form method="GET" action="{{ route('admin.support-tickets') }}" class="table-toolbar">

            <label class="field-with-icon compact-field">

                <i data-lucide="search"></i>

                <input
                    type="search"
                    name="search"
                    value="{{ $filters['search'] }}"
                    placeholder="Search tickets..."
                >

            </label>


            <select class="select-field" name="status" aria-label="Filter by status">

                <option value="">
                    All statuses
                </option>

                @foreach ($statuses as $statusOption)

                    <option value="{{ $statusOption }}" @selected($filters['status'] === $statusOption)>
                        {{ ucfirst(str_replace('_', ' ', $statusOption)) }}
                    </option>

                @endforeach

            </select>


            <select class="select-field" name="topic" aria-label="Filter by topic">

                <option value="">
                    All topics
                </option>

                @foreach ($topics as $topicOption)

                    <option value="{{ $topicOption }}" @selected($filters['topic'] === $topicOption)>
                        {{ $topicOption }}
                    </option>

                @endforeach

            </select>


            <button type="submit" class="button button-ghost button-small">
                <i data-lucide="filter"></i>
                Apply
            </button>

        </form>

    </div>


    <div class="table-wrap">

        <table class="admin-table" id="support-tickets-table">

            <thead>

                <tr>
                    <th>Ticket</th>
                    <th>Sender</th>
                    <th>Topic</th>
                    <th>Subject</th>
                    <th>Reference</th>
                    <th>Received</th>
                    <th>Status</th>
                    <th>Priority</th>
                    <th class="align-right">
                        Details
                    </th>
                </tr>

            </thead>


            <tbody>

                @forelse ($tickets as $ticket)

                    <tr>

                        <td>
                            <strong>{{ $ticket->ticket_no }}</strong>
                        </td>

                        <td>

                            <div class="identity-cell">

                                <span class="avatar avatar-soft">
                                    {{ strtoupper(substr($ticket->name ?: 'S', 0, 1)) }}
                                </span>

                                <div class="table-primary-secondary">
                                    <strong>{{ $ticket->name ?: 'Unnamed sender' }}</strong>
                                    <small>{{ $ticket->email ?: 'No email provided' }}</small>
                                </div>

                            </div>

                        </td>

                        <td>
                            {{ $ticket->topic }}
                        </td>

                        <td>
                            {{ $ticket->subject }}
                        </td>

                        <td>
                            {{ $ticket->reference ?: '—' }}
                        </td>

                        <td>

                            <div class="table-primary-secondary">
                                <strong>{{ $ticket->created_at?->format('M j, Y') ?? '—' }}</strong>
                                <small>{{ $ticket->created_at?->format('g:i A') ?? '' }}</small>
                            </div>

                        </td>

                        <td>

                            <span class="status-badge {{ $ticket->status === 'open' ? 'badge-warning' : 'badge-neutral' }}">
                                {{ ucfirst(str_replace('_', ' ', $ticket->status)) }}
                            </span>

                        </td>

                        <td>

                            <span class="status-badge {{ in_array($ticket->priority, ['urgent', 'high'], true) ? 'badge-danger' : ($ticket->priority === 'low' ? 'badge-neutral' : 'badge-info') }}">
                                {{ ucfirst($ticket->priority) }}
                            </span>

                        </td>

                        <td class="align-right">

                            <button
                                type="button"
                                class="button button-ghost button-small"
                                data-open-modal="support-ticket-{{ $ticket->id }}"
                            >
                                <i data-lucide="eye"></i>
                                View
                            </button>

                        </td>

                    </tr>

                @empty

                    <tr>
                        <td colspan="9">

                            <div class="table-empty">
                                <i data-lucide="inbox"></i>
                                <strong>No support tickets found</strong>
                                <span>No submissions match the current search or filters.</span>
                            </div>

                        </td>
                    </tr>

                @endforelse

            </tbody>

        </table>

    </div>

    @if ($tickets->hasPages())
        <div class="panel-footer">{{ $tickets->links() }}</div>
    @endif

</section>


@foreach ($tickets as $ticket)

    <div class="modal-shell" data-modal="support-ticket-{{ $ticket->id }}" hidden>

        <button type="button" class="modal-backdrop" data-close-modal aria-label="Close ticket details"></button>

        <section class="modal-card modal-wide" role="dialog" aria-modal="true">

            <div class="modal-heading">

                <div>
                    <span class="eyebrow">{{ $ticket->ticket_no }}</span>
                    <h2>{{ $ticket->subject }}</h2>
                </div>

                <button type="button" class="icon-button" data-close-modal aria-label="Close">
                    <i data-lucide="x"></i>
                </button>

            </div>


            <div class="review-details">

                <h3 class="section-subtitle">
                    Ticket information
                </h3>


                <div class="detail-grid">

                    <div>
                        <span>Sender</span>
                        <strong>{{ $ticket->name ?: 'Unnamed sender' }}</strong>
                    </div>

                    <div>
                        <span>Email</span>
                        <strong>{{ $ticket->email ?: '—' }}</strong>
                    </div>

                    <div>
                        <span>Stated role</span>
                        <strong>{{ $ticket->role_label ?: 'Not provided' }}</strong>
                    </div>

                    <div>
                        <span>Topic</span>
                        <strong>{{ $ticket->topic }}</strong>
                    </div>

                    <div>
                        <span>Reference</span>
                        <strong>{{ $ticket->reference ?: '—' }}</strong>
                    </div>

                    <div>
                        <span>Received</span>
                        <strong>{{ $ticket->created_at?->format('M j, Y g:i A') ?? '—' }}</strong>
                    </div>

                    <div>
                        <span>Status</span>
                        <strong>{{ ucfirst(str_replace('_', ' ', $ticket->status)) }}</strong>
                    </div>

                    <div>
                        <span>Priority</span>
                        <strong>{{ ucfirst($ticket->priority) }}</strong>
                    </div>

                    <div>
                        <span>Platform account</span>
                        <strong>{{ $ticket->user?->name ?? 'Guest submission' }}</strong>
                    </div>

                </div>


                <h3 class="section-subtitle">
                    Message
                </h3>

                <p class="detail-note">{{ $ticket->message }}</p>

            </div>

        </section>

    </div>

@endforeach

@endsection
