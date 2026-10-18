@extends('layouts.seller')

@section('title', 'Notifications')
@section('page-title', 'Notifications')

@push('styles')
    @vite(['resources/css/notification-center.css'])
@endpush

@section('content')
    @include('notifications.partials.center', [
        'role' => 'seller',
        'tabs' => $tabs,
        'perPage' => $perPage,
        'heading' => 'Notifications',
        'description' => 'Orders, cancellations, returns, pickups, shipments, messages, and finance updates for your store.',
        'emptyTitle' => 'No seller notifications yet',
        'emptyBody' => 'Order, pickup, shipment, and finance updates for your store will appear here.',
        'links' => [
            ['label' => 'Notification settings', 'url' => route('seller.settings.notifications'), 'icon' => 'settings'],
        ],
    ])
@endsection

@push('scripts')
    @vite(['resources/js/notification-center-page.js'])
@endpush
