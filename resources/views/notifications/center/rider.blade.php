@extends('rider.layouts.app')

@section('title', 'Notifications')
@section('page-title', 'Notifications')

@push('styles')
    @vite(['resources/css/notification-center.css'])
@endpush

@section('content')
    @include('notifications.partials.center', [
        'role' => 'rider',
        'tabs' => $tabs,
        'perPage' => $perPage,
        'heading' => 'Notifications',
        'description' => 'Pickup assignments, delivery assignments, refund cash handovers, and platform announcements.',
        'emptyTitle' => 'No rider notifications yet',
        'emptyBody' => 'New pickup, delivery, and refund cash assignments will appear here.',
        'links' => [],
    ])
@endsection

@push('scripts')
    @vite(['resources/js/notification-center-page.js'])
@endpush
