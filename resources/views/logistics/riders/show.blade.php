@extends('logistics.layouts.app')

@section('title', 'Rider Application '.$application['id'])
@section('page-title', 'Rider Application Review')

@section('content')
<div class="page-header">
    <div>
        <p class="page-kicker">Application {{ $application['id'] }}</p>
        <h2>{{ $application['name'] }}</h2>
        <p>Inspect the applicant profile, delivery coverage, vehicle, and submitted credentials before deciding.</p>
    </div>
    <div class="page-actions">
        <a class="button" href="{{ route('logistics.riders.index') }}"><i data-lucide="arrow-left"></i>Back to riders</a>
        @if(isset($application['user_id']))
            <form method="POST" action="{{ route('logistics.riders.reject', $application['user_id']) }}">@csrf<input type="hidden" name="reason" value="Rider credentials were not approved by the selected Logistics Center."><button class="button button-danger" type="submit"><i data-lucide="x-circle"></i>Disapprove</button></form>
            <form method="POST" action="{{ route('logistics.riders.approve', $application['user_id']) }}">@csrf<button class="button button-primary" type="submit"><i data-lucide="badge-check"></i>Approve rider</button></form>
        @else
            <button class="button button-danger" type="button" data-preview-action data-success="Application disapproved in this front-end preview."><i data-lucide="x-circle"></i>Disapprove</button>
            <button class="button button-primary" type="button" data-preview-action data-success="{{ $application['name'] }} approved as a Bearly rider."><i data-lucide="badge-check"></i>Approve rider</button>
        @endif
    </div>
</div>

<div class="content-grid">
    <section class="panel">
        <div class="panel-header"><div><h3>Applicant profile</h3><p>Personal and contact information.</p></div><span class="status-badge" data-status="{{ $application['status'] }}">{{ $application['status'] }}</span></div>
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
            <div class="panel-header"><div><h3>Vehicle and assignment</h3><p>Proposed courier setup.</p></div></div>
            <div class="panel-body detail-list">
                <div class="detail-item"><small>Vehicle type</small><strong>{{ $application['vehicle'] }}</strong></div>
                <div class="detail-item"><small>Plate number</small><strong>{{ $application['plate'] }}</strong></div>
                <div class="detail-item"><small>Preferred area</small><strong>{{ $application['area'] }}</strong></div>
                <div class="detail-item"><small>Submitted</small><strong>{{ $application['submitted'] }}</strong></div>
            </div>
        </section>
        <section class="panel">
            <div class="panel-header"><div><h3>Submitted documents</h3><p>Front-end document verification preview.</p></div></div>
            <div class="panel-body attention-list">
                @foreach($application['documents'] as $document)
                    <button class="attention-item" type="button" data-preview-action data-success="Opened {{ $document['filename'] }} in preview mode.">
                        <i data-lucide="file-check-2"></i><span><strong>{{ $document['label'] }}</strong><small>{{ $document['filename'] }}</small></span><span class="status-badge" data-status="{{ $document['status'] }}">{{ $document['status'] }}</span>
                    </button>
                @endforeach
            </div>
        </section>
    </div>
</div>
@endsection
