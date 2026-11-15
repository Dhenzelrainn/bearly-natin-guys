@extends('logistics.layouts.app')

@section('title', 'Notifications')
@section('page-title', 'Notifications')

@push('styles')
    @vite(['resources/css/notification-center.css'])
@endpush

@section('content')
    @include('notifications.partials.center', [
        'role' => 'logistics',
        'tabs' => $tabs,
        'perPage' => $perPage,
        'heading' => 'Notifications',
        'description' => 'Pickup requests, handoffs, COD and refund cash, rider applications, messages, and platform announcements for your facility.',
        'emptyTitle' => 'No logistics notifications yet',
        'emptyBody' => 'Pickup, handoff, cash custody, and rider application alerts will appear here.',
        'links' => [],
    ])
@endsection

@push('scripts')
    @vite(['resources/js/notification-center-page.js'])
@endpush
