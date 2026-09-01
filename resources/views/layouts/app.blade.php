<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <meta
        name="description"
        content="Bearly - Your everyday e-commerce marketplace"
    >

    <title>@yield('title', 'Bearly')</title>

    <!-- Google Font -->
    <link rel="preconnect" href="https://fonts.googleapis.com">

    <link
        rel="preconnect"
        href="https://fonts.gstatic.com"
        crossorigin
    >

    <link
        href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap"
        rel="stylesheet"
    >

    <!-- Laravel Vite -->
    @vite([
        'resources/css/homepage.css',
        'resources/js/homepage.js'
    ])

</head>

<body>

    @yield('content')

</body>

</html>