<script>
    (() => {
        window.addEventListener('pageshow', (event) => {
            // A page restored from the browser back/forward cache may contain
            // a CSRF token from a previous session state. Reload the GET page
            // so Laravel can render a current token and auth state.
            if (event.persisted) {
                window.location.reload();
            }
        });
    })();
</script>
