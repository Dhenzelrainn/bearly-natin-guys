<meta name="csrf-token" content="{{ csrf_token() }}">
@include('partials.session-safety')
<script>
    window.bearlyBuyerProfile = @json($buyerProfilePayload ?? []);
    window.bearlyStorageKey = function (key) {
        const buyerId = String(window.bearlyBuyerProfile?.id || 'buyer');

        return `bearly:${buyerId}:${key}`;
    };
    window.bearlyLiveCatalog = @json($liveCatalog ?? []);
</script>
