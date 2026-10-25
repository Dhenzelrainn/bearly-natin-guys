@php
    $headerSearchCategories = collect(json_decode(file_get_contents(resource_path('data/buyer-categories.json')), true, 512, JSON_THROW_ON_ERROR))
        ->mapWithKeys(fn (array $category): array => [$category['slug'] => $category['name']])
        ->all();
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Top Sales | Bearly</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&family=Material+Symbols+Outlined:wght@400&display=swap" rel="stylesheet">
    @vite(['resources/css/buyer.css', 'resources/js/buyer.js'])
    @include('buyer.partials.account-context-script')
</head>
<body class="bh top-products-page">
@include('buyer.partials.buyer-header', [
    'headerSearchFormId' => 'search-form',
    'headerSearchInputId' => 'search-input',
    'headerSearchCategories' => $headerSearchCategories,
    'headerSearchPlaceholder' => 'Search for products, brands or sellers...',
])

@include('buyer.partials.ranking-page-content', [
    'rankingMode' => 'top_sales',
    'rankingPageHeading' => 'Top Sales',
    'rankingIntro' => 'Best Sellers are ranked by total units on completed Seller Orders.',
    'rankingFilterLabel' => 'Filter Top Sales by Buyer category',
    'rankingEmptyHeading' => 'No live sales rankings are available yet',
    'rankingEmptyDescription' => 'Best Sellers appear when completed orders contain orderable listings. We never substitute sample products or sales counts.',
])

<footer class="footer">
    <a href="{{ url('/home') }}"><img src="{{ asset('images/bearly-logo-2.png') }}" alt="Bearly home" width="110" height="37"></a>
    <p>Good finds. Happy spaces.</p>
    <span>Top Sales</span>
</footer>
</body>
</html>
