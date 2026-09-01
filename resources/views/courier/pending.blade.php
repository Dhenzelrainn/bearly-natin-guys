@extends('layouts.courier')

@section('title', 'Application Pending')

@section('content')
<section class="pending-state-card">
    <span class="pending-icon"><i data-lucide="clock-3"></i></span>
    <span class="eyebrow">Application submitted</span>
    <h1>Your courier application is pending approval.</h1>
    <p>Bearly administrators will review your submitted courier information, vehicle details, OR/CR, and identification. The eventual system will send the decision through email.</p>
    <div class="pending-timeline">
        <div><strong>1. Submitted</strong><small>Your registration details and document placeholders were received in this preview.</small></div>
        <div><strong>2. Admin review</strong><small>An administrator verifies your identity, vehicle information, and requirements.</small></div>
        <div><strong>3. Account activation</strong><small>Approved couriers can log in and access available delivery requests.</small></div>
    </div>
    <div class="hero-actions" style="justify-content:center">
        <a class="button button-secondary" href="{{ route('courier.register') }}"><i data-lucide="pencil"></i> Review application</a>
        <a class="button button-primary" href="{{ route('courier.dashboard') }}"><i data-lucide="layout-dashboard"></i> Preview approved dashboard</a>
    </div>
</section>
@endsection
