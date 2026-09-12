(() => {
    'use strict';

    const qs = (selector, scope = document) => scope.querySelector(selector);
    const qsa = (selector, scope = document) => [...scope.querySelectorAll(selector)];

    // Sticky header + mobile menu
    const header = qs('#siteHeader');
    const menuButton = qs('#menuButton');
    const syncHeader = () => header?.classList.toggle('is-scrolled', window.scrollY > 8);
    syncHeader();
    window.addEventListener('scroll', syncHeader, { passive: true });
    menuButton?.addEventListener('click', () => header?.classList.toggle('menu-open'));
    qsa('.main-nav a').forEach(link => link.addEventListener('click', () => header?.classList.remove('menu-open')));

    // Hero carousel
    const slides = qsa('.hero-slide');
    const dots = qsa('[data-hero-dot]');
    const prevHero = qs('.hero-prev');
    const nextHero = qs('.hero-next');
    const heroSlider = qs('#heroSlider');
    let heroIndex = 0;
    let heroTimer = null;

    const showHero = (index) => {
        if (!slides.length) return;
        heroIndex = (index + slides.length) % slides.length;
        slides.forEach((slide, i) => slide.classList.toggle('is-active', i === heroIndex));
        dots.forEach((dot, i) => dot.classList.toggle('is-active', i === heroIndex));
    };

    const startHero = () => {
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches || slides.length < 2) return;
        clearInterval(heroTimer);
        heroTimer = setInterval(() => {
            if (!document.hidden && !document.body.classList.contains('modal-open')) showHero(heroIndex + 1);
        }, 7000);
    };

    prevHero?.addEventListener('click', () => { showHero(heroIndex - 1); startHero(); });
    nextHero?.addEventListener('click', () => { showHero(heroIndex + 1); startHero(); });
    dots.forEach(dot => dot.addEventListener('click', () => { showHero(Number(dot.dataset.heroDot)); startHero(); }));
    heroSlider?.addEventListener('mouseenter', () => clearInterval(heroTimer));
    heroSlider?.addEventListener('mouseleave', startHero);
    heroSlider?.addEventListener('focusin', () => clearInterval(heroTimer));
    heroSlider?.addEventListener('focusout', startHero);
    startHero();
    heroSlider?.addEventListener('keydown', event => {
        if (event.target.matches('input, textarea')) return;
        if (event.key === 'ArrowRight' || event.key === 'ArrowLeft') {
            event.preventDefault();
            showHero(heroIndex + (event.key === 'ArrowRight' ? 1 : -1));
        }
    });
    let touchStartX = null;
    heroSlider?.addEventListener('touchstart', event => {touchStartX = event.touches[0].clientX;}, {passive: true});
    heroSlider?.addEventListener('touchend', event => {
        if(touchStartX === null) return;
        const distance = event.changedTouches[0].clientX - touchStartX;
        if(Math.abs(distance) > 65) {showHero(heroIndex + (distance < 0 ? 1 : -1)); startHero();}
        touchStartX = null;
    }, {passive: true});

    // Horizontal carousels
    const categoryCarousel = qs('#categoryCarousel');
    qs('.category-prev')?.addEventListener('click', () => categoryCarousel?.scrollBy({ left: -520, behavior: 'smooth' }));
    qs('.category-next')?.addEventListener('click', () => categoryCarousel?.scrollBy({ left: 520, behavior: 'smooth' }));

    const collectionTrack = qs('#collectionTrack');
    let collectionIndex = 0;
    const moveCollections = (direction) => {
        if (!collectionTrack) return;
        const card = qs('.collection-card', collectionTrack);
        if (!card) return;
        const gap = 18;
        const step = card.getBoundingClientRect().width + gap;
        const max = Math.max(0, qsa('.collection-card', collectionTrack).length - (window.innerWidth > 1200 ? 3 : window.innerWidth > 680 ? 2 : 1));
        collectionIndex = Math.min(max, Math.max(0, collectionIndex + direction));
        collectionTrack.style.transform = `translateX(${-collectionIndex * step}px)`;
    };
    qs('#collectionPrev')?.addEventListener('click', () => moveCollections(-1));
    qs('#collectionNext')?.addEventListener('click', () => moveCollections(1));
    qs('#viewCollections')?.addEventListener('click', () => moveCollections(collectionIndex >= 3 ? -collectionIndex : 1));
    window.addEventListener('resize', () => { collectionIndex = 0; if (collectionTrack) collectionTrack.style.transform = ''; });

    const productTrack = qs('#productTrack');
    qs('#productPrev')?.addEventListener('click', () => productTrack?.scrollBy({ left: -650, behavior: 'smooth' }));
    qs('#productNext')?.addEventListener('click', () => productTrack?.scrollBy({ left: 650, behavior: 'smooth' }));

    // Product filters + front-end search
    const productCards = qsa('[data-product-card]');
    const tabs = qsa('[data-product-filter]');
    let activeFilter = 'All';
    let productQuery = '';

    const applyProductFilters = () => {
        productCards.forEach(card => {
            const categoryMatch = activeFilter === 'All' || card.dataset.category === activeFilter;
            const searchMatch = !productQuery || card.dataset.name.includes(productQuery);
            card.classList.toggle('is-hidden', !(categoryMatch && searchMatch));
        });
        productTrack?.scrollTo({ left: 0, behavior: 'smooth' });
    };

    tabs.forEach(tab => tab.addEventListener('click', () => {
        activeFilter = tab.dataset.productFilter;
        tabs.forEach(t => t.classList.toggle('is-active', t === tab));
        applyProductFilters();
    }));

    const headerSearch = qs('#headerProductSearch');
    headerSearch?.addEventListener('input', e => {
        productQuery = e.target.value.trim().toLowerCase();
        activeFilter = 'All';
        tabs.forEach(t => t.classList.toggle('is-active', t.dataset.productFilter === 'All'));
        applyProductFilters();
        if (productQuery) qs('#featured-products')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });

    qs('#showAllProducts')?.addEventListener('click', () => {
        productQuery = '';
        activeFilter = 'All';
        if (headerSearch) headerSearch.value = '';
        tabs.forEach(t => t.classList.toggle('is-active', t.dataset.productFilter === 'All'));
        applyProductFilters();
    });

    // Wishlist front-end state
    qsa('.wishlist-button').forEach(button => {
        button.addEventListener('click', event => {
            event.preventDefault();
            event.stopPropagation();
            button.classList.toggle('is-active');
            const icon = qs('.material-symbols-rounded', button);
            if (icon) icon.style.fontVariationSettings = button.classList.contains('is-active') ? "'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24" : "'FILL' 0,'wght' 350,'GRAD' 0,'opsz' 24";
        });
    });

    // Category modal
    const modal = qs('#categoryModal');
    const modalSearch = qs('#categorySearch');
    const noResults = qs('#noCategoryResults');

    const openModal = (categoryName = '') => {
        if (!modal) return;
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('modal-open');
        if (modalSearch) {
            modalSearch.value = categoryName || '';
            filterCategories(categoryName);
            setTimeout(() => modalSearch.focus(), 50);
        }
    };

    const closeModal = () => {
        if (!modal) return;
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('modal-open');
    };

    const filterCategories = (raw = '') => {
        const term = raw.trim().toLowerCase();
        let visibleCount = 0;
        qsa('[data-modal-category]').forEach(card => {
            const visible = !term || card.dataset.searchText.includes(term);
            card.hidden = !visible;
            if (visible) visibleCount++;
        });
        qsa('[data-subcategory-group]').forEach(group => {
            group.hidden = !!term && !group.dataset.searchText.includes(term);
        });
        if (noResults) noResults.hidden = visibleCount !== 0;
    };

    qsa('[data-open-categories]').forEach(button => {
        button.addEventListener('click', () => openModal(button.dataset.categoryName || ''));
    });
    qsa('[data-close-categories]').forEach(button => button.addEventListener('click', closeModal));
    modalSearch?.addEventListener('input', e => filterCategories(e.target.value));
    qsa('.trending-searches button').forEach(button => button.addEventListener('click', () => {
        if (modalSearch) modalSearch.value = button.textContent.trim();
        filterCategories(button.textContent.trim());
    }));
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && modal?.classList.contains('is-open')) closeModal();
    });

    // Scroll reveal
    const revealItems = qsa('.reveal-section');
    if ('IntersectionObserver' in window && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        const observer = new IntersectionObserver(entries => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-visible');
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.12, rootMargin: '0px 0px -35px 0px' });
        revealItems.forEach(item => observer.observe(item));
    } else {
        revealItems.forEach(item => item.classList.add('is-visible'));
    }
})();

// Accessible front-end interactions; no newsletter or checkout request is sent.
(() => {
 const $ = s => document.querySelector(s), $$ = s => [...document.querySelectorAll(s)];
 let messageTimer;
 const notify = message => { const el = $('#interactionStatus'); if (!el) return; el.textContent = message; clearTimeout(messageTimer); messageTimer = setTimeout(() => el.textContent = '', 5000); };
 $$('.newsletter-form').forEach(form => form.addEventListener('submit', event => {event.preventDefault(); notify('Newsletter signup is coming soon. Your email has not been submitted.');}));
 const modal = $('#categoryModal'); let opener;
 $$('[data-open-categories]').forEach(button => button.addEventListener('click', () => {opener = button; document.querySelector('main').inert = true; document.querySelector('header').inert = true;}));
 const restoreFocus = () => {document.querySelector('main').inert = false; document.querySelector('header').inert = false; opener?.focus();};
 $$('[data-close-categories]').forEach(el => el.addEventListener('click', restoreFocus));
 document.addEventListener('keydown', event => {
   if(event.key === 'Escape' && opener) {restoreFocus(); opener = null;}
   if(event.key !== 'Tab' || !modal?.classList.contains('is-open')) return;
   const focusable = [...modal.querySelectorAll('button,input,a[href]')].filter(el => el.getClientRects().length);
   const first = focusable[0], last = focusable.at(-1);
   if(event.shiftKey && document.activeElement === first){event.preventDefault();last?.focus();}
   else if(!event.shiftKey && document.activeElement === last){event.preventDefault();first?.focus();}
 });
 $$('.modal-category-card').forEach(card => card.addEventListener('click', () => {
   const label = card.querySelector('strong')?.textContent.trim();
   const group = $$('.subcategory-column').find(el => el.querySelector('h4')?.textContent.includes(label));
   group?.scrollIntoView({behavior:'smooth',block:'start'});
 }));
 $$('.subcategory-column a').forEach(link => link.addEventListener('click', event => {event.preventDefault();notify(`Browsing ${link.textContent.trim()} will be available when the catalog is connected.`);}));
 $('.header-actions button[aria-label="Wishlist"]')?.addEventListener('click', () => {const count = $$('.wishlist-button.is-active').length;notify(count ? `${count} favorite${count === 1 ? '' : 's'} selected on this page.` : 'Tap a heart on a product to save a favorite.');});
 $('.cart-button')?.addEventListener('click', () => notify('Your bag is empty. Shopping will be available when the catalog is connected.'));
 const syncState = () => {
  $$('.wishlist-button').forEach(b => b.setAttribute('aria-pressed',String(b.classList.contains('is-active'))));
  $$('[data-product-filter]').forEach(b => b.setAttribute('aria-pressed',String(b.classList.contains('is-active'))));
  $$('[data-hero-dot]').forEach(b => b.setAttribute('aria-current',String(b.classList.contains('is-active'))));
  $$('.hero-slide').forEach(el => {el.inert = !el.classList.contains('is-active'); el.setAttribute('aria-hidden',String(el.inert));});
  const empty = $('#noProductResults'); if(empty) empty.hidden = $$('[data-product-card]:not(.is-hidden)').length > 0;
 };
 new MutationObserver(syncState).observe(document.querySelector('main'),{subtree:true,attributes:true,attributeFilter:['class']});syncState();
 $('#menuButton')?.addEventListener('click',event => event.currentTarget.setAttribute('aria-expanded',String($('#siteHeader').classList.contains('menu-open'))));
})();
