<script>
    (() => {
        const root = document.documentElement;
        const storageKey = 'bearly-theme';
        let theme = 'light';

        try {
            const savedTheme = window.localStorage.getItem(storageKey);
            theme = savedTheme === 'light' || savedTheme === 'dark'
                ? savedTheme
                : (window.matchMedia?.('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
        } catch {
            theme = window.matchMedia?.('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        }

        root.dataset.theme = theme;
        root.style.colorScheme = theme;
        document.querySelector('meta[name="theme-color"]')?.setAttribute('content', theme === 'dark' ? '#171411' : '#432214');
    })();
</script>
