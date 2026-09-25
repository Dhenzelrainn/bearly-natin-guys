@extends('logistics.layouts.app')

@section('title', 'Rider Application '.$application['id'])
@section('page-title', 'Rider Application Review')

@section('content')
<div class="page-header">
    <div>
        <p class="page-kicker">Rider {{ $application['id'] }}</p>
        <h2>{{ $application['name'] }}</h2>
        <p>Inspect the Rider profile, vehicle, and submitted credentials.</p>
    </div>

    <div class="page-actions">
        <a class="button" href="{{ route('logistics.riders.index') }}">
            <i data-lucide="arrow-left"></i>
            Back to riders
        </a>

        @if(in_array(strtolower($application['status']), ['pending', 'needs revision'], true))
            <form method="POST" action="{{ route('logistics.riders.reject', $application['user_id']) }}">
                @csrf
                <input
                    type="hidden"
                    name="reason"
                    value="Rider credentials were not approved by the selected Logistics Center."
                >
                <button class="button button-danger" type="submit">
                    <i data-lucide="x-circle"></i>
                    Disapprove
                </button>
            </form>

            <form method="POST" action="{{ route('logistics.riders.approve', $application['user_id']) }}">
                @csrf
                <button class="button button-primary" type="submit">
                    <i data-lucide="badge-check"></i>
                    Approve rider
                </button>
            </form>
        @endif
    </div>
</div>

<div class="content-grid">
    <section class="panel">
        <div class="panel-header">
            <div>
                <h3>Rider profile</h3>
                <p>Personal and contact information from the database.</p>
            </div>
            <span class="status-badge" data-status="{{ $application['status'] }}">
                {{ $application['status'] }}
            </span>
        </div>

        <div class="panel-body detail-list">
            <div class="detail-item"><small>Full name</small><strong>{{ $application['name'] }}</strong></div>
            <div class="detail-item"><small>Sex</small><strong>{{ $application['sex'] }}</strong></div>
            <div class="detail-item"><small>Birthday</small><strong>{{ $application['birthday'] }}</strong></div>
            <div class="detail-item"><small>Email</small><strong>{{ $application['email'] }}</strong></div>
            <div class="detail-item"><small>Contact number</small><strong>{{ $application['contact'] }}</strong></div>
            <div class="detail-item"><small>Home address</small><strong>{{ $application['address'] }}</strong></div>
        </div>
    </section>

    <div class="section-stack">
        <section class="panel">
            <div class="panel-header">
                <div>
                    <h3>Vehicle and assignment</h3>
                    <p>Registered Rider vehicle information.</p>
                </div>
            </div>

            <div class="panel-body detail-list">
                <div class="detail-item"><small>Vehicle type</small><strong>{{ $application['vehicle'] }}</strong></div>
                <div class="detail-item"><small>Plate number</small><strong>{{ $application['plate'] }}</strong></div>
                <div class="detail-item"><small>Area</small><strong>{{ $application['area'] }}</strong></div>
                <div class="detail-item"><small>Submitted</small><strong>{{ $application['submitted'] }}</strong></div>
            </div>
        </section>

        <section class="panel">
            <div class="panel-header">
                <div>
                    <h3>Submitted documents</h3>
                    <p>Files recorded during Rider registration.</p>
                </div>
            </div>

            <div class="panel-body attention-list">
                @forelse($application['documents'] as $document)
                    <div class="attention-item">
                        <i data-lucide="file-check-2"></i>
                        <span>
                            <strong>{{ $document['label'] }}</strong>
                            <small>{{ $document['filename'] }}</small>
                        </span>
                        <span class="status-badge" data-status="{{ $document['status'] }}">
                            {{ $document['status'] }}
                        </span>
                    </div>
                @empty
                    <p class="empty-copy">No registration documents were recorded.</p>
                @endforelse
            </div>
        </section>
    </div>
</div>
@endsection
