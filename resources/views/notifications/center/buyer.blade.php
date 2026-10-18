<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Notifications | Bearly</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&family=Material+Symbols+Outlined:wght@400&display=swap" rel="stylesheet">
    @include('buyer.partials.account-context-script')
    @vite([
        'resources/css/buyer.css',
        'resources/css/notification-center.css',
        'resources/js/notification-center-page.js',
    ])
</head>
<body class="bh notification-center-body">
@include('buyer.partials.buyer-header', [
    'headerSearchFormId' => 'notifications-search-form',
    'headerSearchInputId' => 'notifications-search-input',
    'headerSearchLabel' => 'Search Bearly',
    'headerActive' => 'notifications',
    'headerAvatarId' => 'navbar-avatar',
])

<main class="nc-main">
    @include('notifications.partials.center', [
        'role' => 'buyer',
        'tabs' => $tabs,
        'perPage' => $perPage,
        'heading' => 'Notifications',
        'description' => 'Order, shipment, return, message, and announcement updates in one place.',
        'emptyTitle' => 'No notifications yet',
        'emptyBody' => 'Updates about your Bearly orders and account will appear here.',
        'links' => [],
    ])
</main>
</body>
</html>
