@php
    $catalog = json_decode(
        file_get_contents(resource_path('data/buyer-pet-supplies-products.json')),
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    $taxonomy = json_decode(
        file_get_contents(resource_path('data/buyer-categories.json')),
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    $petCategory = collect($taxonomy)->firstWhere('slug', 'pet-supplies');
    $subcategories = $petCategory['subcategories'];
@endphp

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pet Supplies | Bearly</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&family=Material+Symbols+Outlined:wght@400&display=swap" rel="stylesheet">

    @vite(['resources/css/buyer.css', 'resources/js/buyer.js'])
</head>

<body class="bc pet-supplies" style="--catalog-image:url('{{ asset('images/pet-supplies-catalog-atlas.png') }}')">
    <a href="#bc-main" class="skip">Skip to products</a>

    <header class="bc-header">
        <a href="{{ route('home') }}" class="brand">
            <img src="{{ asset('images/bearly-logo.png') }}" alt="Bearly home" width="185" height="62">
        </a>

        <form id="bc-search-form" class="search" role="search">
            <span>Pet Supplies</span>
            <label class="sr" for="bc-search">Search Pet Supplies</label>
            <input id="bc-search" type="search" placeholder="Search Pet Supplies" maxlength="120">
            <button aria-label="Search"><i class="mi">search</i></button>
        </form>

        <nav aria-label="Account">
            <button data-info="orders"><i class="mi">receipt_long</i> Orders</button>
            <button data-info="chat"><i class="mi">chat_bubble</i> Chat</button>
            <a href="{{ url('/cart') }}"><i class="mi">shopping_cart</i> Cart</a>
            <a href="{{ url('/login') }}"><i class="mi">person</i> Sign in</a>
        </nav>
    </header>

    <div class="bc-shell">
        <aside class="bc-sidebar" id="bc-sidebar" aria-label="Categories and filters">
            <a class="back" href="{{ route('home') }}">
                <i class="mi">arrow_back</i> All categories
            </a>

            <div class="active-category">
                <i class="mi">pets</i>
                <strong>Pet Supplies</strong>
            </div>

            <details open class="subcategories">
                <summary>Subcategories</summary>
                <nav aria-label="Subcategories">
                    <button data-sub="" aria-pressed="true">
                        <i class="mi">grid_view</i> All items
                    </button>

                    @foreach($subcategories as $i => $sub)
                        <button data-sub="{{ $sub }}" aria-pressed="false">
                            <i class="mi">
                                {{ ['pets','cleaning_services','water','flutter_dash','content_cut','health_and_safety'][$i] }}
                            </i>
                            {{ $sub }}
                        </button>
                    @endforeach
                </nav>
            </details>

            @include('buyer.Category.Pet-Supplies.components.pet-supplies-filter')
        </aside>

        <main id="bc-main" tabindex="-1">
            <nav class="breadcrumb" aria-label="Breadcrumb">
                <a href="{{ route('home') }}">Home</a>
                <span>/</span>
                <strong>Pet Supplies</strong>
            </nav>

            <h1>Pet Supplies</h1>
            <p class="subtitle">Everyday essentials for happy, healthy pets.</p>
            <p class="preview">Frontend catalog · Sample products, prices and ratings</p>

            <div class="toolbar">
                <strong id="bc-count">{{ count($catalog) }} sample products</strong>

                <button class="mobile-filter button outline" id="bc-open-filters">
                    <i class="mi">tune</i> Filters
                    <span id="bc-mobile-count"></span>
                </button>

                <div class="sorts" aria-label="Sort products">
                    <button data-sort="featured" aria-pressed="true">All items</button>
                    <button data-sort="newest" aria-pressed="false">New arrivals</button>
                    <button data-sort="price-low" aria-pressed="false">Price: low to high</button>
                    <button data-sort="price-high" aria-pressed="false">Price: high to low</button>
                </div>

                <div class="views">
                    <button data-view="grid" aria-label="Grid view" aria-pressed="true"><i class="mi">grid_view</i></button>
                    <button data-view="list" aria-label="List view" aria-pressed="false"><i class="mi">view_list</i></button>
                </div>

                <button class="saved-filter" id="bc-saved-filter" aria-pressed="false">
                    <i class="mi">favorite</i>
                    <span id="bc-saved-count">0</span>
                </button>
            </div>

            <div class="shortcuts" aria-label="Explore subcategories">
                @php $shortcutPhotos = [0, 5, 10, 15, 1, 6]; @endphp

                @foreach($subcategories as $i => $sub)
                    <button data-sub="{{ $sub }}">
                        <span
                            class="photo"
                            style="--x:{{ ($shortcutPhotos[$i] % 5) * 25 }}%;--y:{{ floor($shortcutPhotos[$i] / 5) * 100 / 3 }}%"
                            aria-hidden="true"
                        ></span>
                        <span>{{ $sub }} <b>›</b></span>
                    </button>
                @endforeach
            </div>

            <div id="bc-chips" class="chips"></div>
            <div class="catalog-grid" id="bc-grid"></div>

            <section id="bc-empty" class="empty" hidden>
                <i class="mi">search_off</i>
                <h2>No matching finds</h2>
                <p>Try removing a filter or searching for something else.</p>
                <button class="button" data-reset>Clear all filters</button>
            </section>

            <div class="load">
                <p id="bc-status" role="status" aria-live="polite"></p>
                <button class="button outline" id="bc-more">Load more products</button>
            </div>
        </main>
    </div>

    <footer>
        <img src="{{ asset('images/bearly-logo.png') }}" alt="Bearly" width="110" height="37">
        <span>Good finds. Happy spaces.</span>

        <nav>
            <button data-info="help">Help centre</button>
            <button data-info="about">About Bearly</button>
        </nav>

        <small>Frontend preview</small>
    </footer>

    <button class="chat" data-info="chat">
        <i class="mi">chat_bubble</i> Chat
    </button>

    <dialog id="bc-mobile-dialog" aria-labelledby="bc-mobile-title">
        <div class="mobile-heading">
            <div>
                <h2 id="bc-mobile-title">Filters</h2>
                <p>Pet Supplies</p>
            </div>
            <button data-close aria-label="Close filters"><i class="mi">close</i></button>
        </div>

        <div id="bc-mobile-content"></div>

        <div class="mobile-actions">
            <button class="button outline" data-reset>Reset</button>
            <button class="button gold" id="bc-show-results">Show results</button>
        </div>
    </dialog>

    <dialog id="bc-product-dialog" aria-labelledby="bc-product-title">
        <button class="close" data-close aria-label="Close product"><i class="mi">close</i></button>
        <div id="bc-product-detail"></div>
    </dialog>

    <dialog id="bc-info-dialog" aria-labelledby="bc-info-title">
        <button class="close" data-close aria-label="Close"><i class="mi">close</i></button>
        <h2 id="bc-info-title"></h2>
        <p id="bc-info-copy"></p>
        <a class="button gold" href="{{ url('/login') }}">Sign in</a>
    </dialog>

    @include('buyer.Category.Pet-Supplies.components.pet-supplies-product-card')

    <script type="application/json" id="bc-data">
        {!! json_encode($catalog, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) !!}
    </script>
</body>
</html>
