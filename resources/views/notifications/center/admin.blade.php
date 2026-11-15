@extends('layouts.admin')

@section('title', 'Notifications')
@section('page-title', 'Notifications')

@push('styles')
    @vite(['resources/css/notification-center.css'])
@endpush

@section('content')
    @include('notifications.partials.center', [
        'role' => 'admin',
        'tabs' => $tabs,
        'perPage' => $perPage,
        'heading' => 'Notifications',
        'description' => 'Registrations, compliance flags, returns, finance, and support messages that need an Admin decision.',
        'emptyTitle' => 'No admin notifications yet',
        'emptyBody' => 'Registration, compliance, return, finance, and support alerts will appear here.',
        'links' => [
            ['label' => 'Announcements', 'url' => route('admin.announcements'), 'icon' => 'megaphone'],
        ],
    ])
@endsection

@push('scripts')
    @vite(['resources/js/notification-center-page.js'])
@endpush
