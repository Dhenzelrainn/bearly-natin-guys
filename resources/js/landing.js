/* BEARLY landing interactions — final pass 2026-09-13 */
(() => {
    'use strict';

    const $ = (selector, root = document) => root.querySelector(selector);
    const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

    const status = $('#interactionStatus');
    let statusTimer = 0;
    const announce = (message) => {
        if (!status) return;
        window.clearTimeout(statusTimer);
        status.textContent = message;
        statusTimer = window.setTimeout(() => { status.textContent = ''; }, 2200);
    };

    /* ---------------- Scroll reveal (fail-safe) ---------------- */
    const revealSections = $$('.reveal-section');
    if (revealSections.length) {
        if ('IntersectionObserver' in window && !reducedMotion.matches) {
            const revealObserver = new IntersectionObserver((entries, observer) => {
                entries.forEach(entry => {
                    if (!entry.isIntersecting) return;
                    entry.target.classList.add('is-visible');
                    observer.unobserve(entry.target);
                });
            }, { rootMargin: '0px 0px -8% 0px', threshold: 0.06 });

            revealSections.forEach(section => revealObserver.observe(section));
        } else {
            revealSections.forEach(section => section.classList.add('is-visible'));
        }

        /* Never leave page content hidden if a browser delays observer callbacks. */
        window.setTimeout(() => {
            revealSections.forEach(section => {
                if (section.getBoundingClientRect().top < window.innerHeight * 1.35) {
                    section.classList.add('is-visible');
                }
            });
        }, 700);
    }

    /* ---------------- Mobile navigation ---------------- */
    const header = $('#siteHeader');
    const menuButton = $('#menuButton');
    if (header && menuButton) {
        const closeMenu = () => {
            header.classList.remove('menu-open');
            menuButton.setAttribute('aria-expanded', 'false');
        };
        menuButton.setAttribute('aria-expanded', 'false');
        menuButton.addEventListener('click', () => {
            const open = !header.classList.contains('menu-open');
            header.classList.toggle('menu-open', open);
            menuButton.setAttribute('aria-expanded', String(open));
        });
        $$('.main-nav a', header).forEach(link => link.addEventListener('click', closeMenu));
        document.addEventListener('keydown', event => { if (event.key === 'Escape') closeMenu(); });
    }

    /* ---------------- Hero carousel ---------------- */
    const hero = $('#heroSlider');
    if (hero) {
        const slides = $$('[data-slide]', hero);
        const dots = $$('[data-hero-dot]', hero);
        const prev = $('.hero-prev', hero);
        const next = $('.hero-next', hero);
        let active = Math.max(0, slides.findIndex(slide => slide.classList.contains('is-active')));
        let timer = 0;
        let paused = false;

        const show = (index, userInitiated = false) => {
            if (!slides.length) return;
            active = (index + slides.length) % slides.length;
            slides.forEach((slide, i) => {
                const current = i === active;
                slide.classList.toggle('is-active', current);
                slide.setAttribute('aria-hidden', String(!current));
            });
            dots.forEach((dot, i) => {
                dot.classList.toggle('is-active', i === active);
                dot.setAttribute('aria-current', i === active ? 'true' : 'false');
            });
            if (userInitiated) restart();
        };

        const stop = () => { window.clearInterval(timer); timer = 0; };
        const start = () => {
            stop();
            if (reducedMotion.matches || paused || document.hidden || slides.length < 2) return;
            timer = window.setInterval(() => show(active + 1), 6500);
        };
        const restart = () => start();

        prev?.addEventListener('click', () => show(active - 1, true));
        next?.addEventListener('click', () => show(active + 1, true));
        dots.forEach((dot, i) => dot.addEventListener('click', () => show(i, true)));
        hero.addEventListener('mouseenter', () => { paused = true; stop(); });
        hero.addEventListener('mouseleave', () => { paused = false; start(); });
        hero.addEventListener('focusin', () => { paused = true; stop(); });
        hero.addEventListener('focusout', event => {
            if (!hero.contains(event.relatedTarget)) { paused = false; start(); }
        });
        document.addEventListener('visibilitychange', start);
        reducedMotion.addEventListener?.('change', start);
        show(active);
        start();
    }

    /* ---------------- Auto-moving Shop by Categories ---------------- */
    const categoryCarousel = $('#categoryCarousel');
    if (categoryCarousel) {
        const prev = $('.category-prev');
        const next = $('.category-next');
        let timer = 0;
        let paused = false;

        const stepSize = () => {
            const card = $('.category-card', categoryCarousel);
            if (!card) return Math.max(180, categoryCarousel.clientWidth * .35);
            const styles = getComputedStyle(categoryCarousel);
            const gap = parseFloat(styles.columnGap || styles.gap) || 18;
            return card.getBoundingClientRect().width + gap;
        };

        const move = (direction = 1, smooth = true) => {
            const max = categoryCarousel.scrollWidth - categoryCarousel.clientWidth;
            if (max <= 2) return;
            const step = stepSize();
            const atEnd = categoryCarousel.scrollLeft >= max - step * .45;
            const atStart = categoryCarousel.scrollLeft <= step * .15;

            if (direction > 0 && atEnd) {
                categoryCarousel.scrollTo({ left: 0, behavior: smooth ? 'smooth' : 'auto' });
            } else if (direction < 0 && atStart) {
                categoryCarousel.scrollTo({ left: max, behavior: smooth ? 'smooth' : 'auto' });
            } else {
                categoryCarousel.scrollBy({ left: direction * step, behavior: smooth ? 'smooth' : 'auto' });
            }
        };

        const stop = () => { window.clearInterval(timer); timer = 0; };
        const start = () => {
            stop();
            if (reducedMotion.matches || paused || document.hidden) return;
            timer = window.setInterval(() => move(1, true), 2600);
        };
        const restart = () => { stop(); window.setTimeout(start, 900); };

        prev?.addEventListener('click', () => { move(-1); restart(); });
        next?.addEventListener('click', () => { move(1); restart(); });
        categoryCarousel.addEventListener('mouseenter', () => { paused = true; stop(); });
        categoryCarousel.addEventListener('mouseleave', () => { paused = false; start(); });
        categoryCarousel.addEventListener('focusin', () => { paused = true; stop(); });
        categoryCarousel.addEventListener('focusout', event => {
            if (!categoryCarousel.contains(event.relatedTarget)) { paused = false; start(); }
        });
        categoryCarousel.addEventListener('pointerdown', () => { paused = true; stop(); }, { passive: true });
        categoryCarousel.addEventListener('pointerup', () => { paused = false; restart(); }, { passive: true });
        document.addEventListener('visibilitychange', start);
        reducedMotion.addEventListener?.('change', start);
        start();
    }

    /* ---------------- View all categories modal ---------------- */
    const categoryModal = $('#categoryModal');
    const categorySearch = $('#categorySearch');
    if (categoryModal) {
        const cards = $$('[data-modal-category]', categoryModal);
        const groups = $$('[data-subcategory-group]', categoryModal);
        const noResults = $('#noCategoryResults', categoryModal);
        let previousFocus = null;

        const filterCategories = (query = '') => {
            const q = query.trim().toLowerCase();
            let shown = 0;
            cards.forEach(card => {
                const match = !q || (card.dataset.searchText || '').includes(q);
                card.hidden = !match;
                if (match) shown++;
            });
            groups.forEach(group => {
                const match = !q || (group.dataset.searchText || '').includes(q);
                group.hidden = !match;
            });
            if (noResults) noResults.hidden = shown !== 0 || !q;
        };

        const open = (trigger) => {
            previousFocus = trigger || document.activeElement;
            categoryModal.classList.add('is-open');
            categoryModal.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';
            const chosen = trigger?.dataset?.categoryName || '';
            if (categorySearch) categorySearch.value = chosen;
            filterCategories(chosen);
            window.setTimeout(() => categorySearch?.focus(), 30);
        };
        const close = () => {
            categoryModal.classList.remove('is-open');
            categoryModal.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = '';
            previousFocus?.focus?.({ preventScroll: true });
        };

        $$('[data-open-categories]').forEach(button => button.addEventListener('click', event => {
            if (button.tagName === 'A') event.preventDefault();
            open(button);
        }));
        $$('[data-close-categories]', categoryModal).forEach(button => button.addEventListener('click', close));
        categorySearch?.addEventListener('input', () => filterCategories(categorySearch.value));
        $$('.trending-searches button', categoryModal).forEach(button => button.addEventListener('click', () => {
            if (categorySearch) categorySearch.value = button.textContent.trim();
            filterCategories(categorySearch?.value || '');
        }));
        document.addEventListener('keydown', event => {
            if (event.key === 'Escape' && categoryModal.classList.contains('is-open')) close();
        });
    }

    /* ---------------- Featured product filters + search ---------------- */
    const productCards = $$('[data-product-card]');
    const productTabs = $$('[data-product-filter]');
    const productSearch = $('#headerProductSearch');
    const noProducts = $('#noProductResults');
    let productCategory = 'All';
    let productQuery = '';

    const filterProducts = () => {
        let shown = 0;
        productCards.forEach(card => {
            const category = card.dataset.category || '';
            const name = card.dataset.name || '';
            const matchesCategory = productCategory === 'All' || category === productCategory;
            const matchesSearch = !productQuery || `${name} ${category}`.toLowerCase().includes(productQuery);
            const visible = matchesCategory && matchesSearch;
            card.classList.toggle('is-hidden', !visible);
            if (visible) shown++;
        });
        if (noProducts) noProducts.hidden = shown !== 0;
    };

    productTabs.forEach(tab => tab.addEventListener('click', () => {
        productCategory = tab.dataset.productFilter || 'All';
        productTabs.forEach(item => item.classList.toggle('is-active', item === tab));
        filterProducts();
    }));
    productSearch?.addEventListener('input', () => {
        productQuery = productSearch.value.trim().toLowerCase();
        filterProducts();
    });
    productSearch?.addEventListener('keydown', event => {
        if (event.key === 'Enter') {
            event.preventDefault();
            $('#featured-products')?.scrollIntoView({ behavior: reducedMotion.matches ? 'auto' : 'smooth', block: 'start' });
        }
    });

    /* Wishlist is UI-only during front-end stage. */
    $$('.wishlist-button').forEach(button => button.addEventListener('click', () => {
        const active = button.classList.toggle('is-active');
        button.setAttribute('aria-pressed', String(active));
        announce(active ? 'Saved to your Bearly wishlist preview.' : 'Removed from your Bearly wishlist preview.');
    }));

    /* Newsletter is still front-end only. */
    $$('.newsletter-form').forEach(form => form.addEventListener('submit', event => {
        event.preventDefault();
        announce('Thanks! Newsletter signup will connect when the backend is ready.');
        form.reset();
    }));
})();
