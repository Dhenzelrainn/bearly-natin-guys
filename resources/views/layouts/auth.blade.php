<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>@yield('title', 'Bearly')</title>

    <script src="https://cdn.tailwindcss.com"></script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link
          href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap"rel="stylesheet">

    @vite([
        'resources/css/bearly-auth.css',
        'resources/js/bearly-auth.js'
    ])

    @stack('styles')
</head>

<body>
    <header class="auth-header">
        <a
            href="{{ route('login') }}"
            class="brand"
            aria-label="Bearly home"
        >
            <img
                src="{{ asset('images/bearly-logo.png') }}"
                alt="Bearly"
            >
        </a>

        @yield('header-action')
    </header>

    <main>
        @yield('content')
    </main>

    @stack('scripts')
</body>
</html>