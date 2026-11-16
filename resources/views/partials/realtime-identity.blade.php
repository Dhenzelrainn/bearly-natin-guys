@php
    /*
     * Shared realtime identity for Echo subscriptions.
     *
     * The user id and the marketplace roles are never trusted from page
     * JavaScript: they come from the authenticated session here, and every
     * private channel is still authorized again by routes/channels.php.
     */
    $realtimeUser = auth()->user();
    $realtimeAllowedRoles = ['buyer', 'seller', 'logistics', 'rider'];

    $realtimeRoles = collect();

    if ($realtimeUser) {
        $roleNames = $realtimeUser->relationLoaded('roles')
            ? $realtimeUser->roles->pluck('name')
            : $realtimeUser->roles()->pluck('name');

        $realtimeRoles = $roleNames
            ->push($realtimeUser->role)
            ->filter(fn ($role) => in_array($role, $realtimeAllowedRoles, true))
            ->unique()
            ->values();
    }

    $realtimeIdentity = [
        'user_id' => (int) auth()->id(),
        'roles' => $realtimeRoles,
        'session_status_url' => route('auth.session.status', absolute: false),
        'sign_in_url' => route('login', ['session' => 'ended'], absolute: false),
    ];
@endphp

@auth
    <script>
        window.bearlyRealtimeIdentity = @json($realtimeIdentity);
    </script>
@endauth
