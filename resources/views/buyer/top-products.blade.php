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
    <title>Top Products | Bearly</title>
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
    'rankingMode' => 'popular',
    'rankingPageHeading' => 'Top Products',
    'rankingIntro' => 'Popular Picks are ranked by distinct saves from active Buyer accounts.',
    'rankingFilterLabel' => 'Filter Top Products by Buyer category',
    'rankingEmptyHeading' => 'No live product rankings are available yet',
    'rankingEmptyDescription' => 'Popular Picks appear when Buyer accounts save orderable listings. We never substitute sample products or ratings.',
])

<footer class="footer">
    <a href="{{ url('/home') }}"><img src="{{ asset('images/bearly-logo-2.png') }}" alt="Bearly home" width="110" height="37"></a>
    <p>Good finds. Happy spaces.</p>
    <span>Top Products</span>
</footer>
</body>
</html>
