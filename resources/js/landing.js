/* Bearly landing page interactions. */
if (document.body.classList.contains("bearly-landing")) initializeLanding();

function initializeLanding() {
    const $ = (s, root = document) => root.querySelector(s);
    const $$ = (s, root = document) => [...root.querySelectorAll(s)];
    const products = JSON.parse($("#lp-products-data").textContent);
    const categories = JSON.parse($("#lp-categories-data").textContent);
    const escape = (text) =>
        String(text).replace(
            /[&<>"']/g,
            (c) =>
                ({
                    "&": "&amp;",
                    "<": "&lt;",
                    ">": "&gt;",
                    '"': "&quot;",
                    "'": "&#39;",
                })[c],
        );
    const reduce = window.matchMedia("(prefers-reduced-motion: reduce)");
    const shop = document.body.dataset.shopUrl;
    const storageKey = "bearly-landing-saved-v3";
    const priceFormatter = new Intl.NumberFormat("en-PH", {
        style: "currency",
        currency: "PHP",
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
    let saved = new Set(),
        toastTimer;

    try {
        const stored = JSON.parse(localStorage.getItem(storageKey) || "[]");
        if (Array.isArray(stored))
            saved = new Set(
                stored
                    .map(String)
                    .filter((id) => products.some((p) => String(p.id) === id)),
            );
    } catch {
        /* Session-only fallback. */
    }

    function notify(message) {
        const el = $("[data-toast]");
        el.textContent = message;
        el.hidden = false;
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => {
            el.hidden = true;
        }, 3200);
    }

    function photo(product, extra = "") {
        const image = String(product.image || "").trim();

        if (!image) {
            return `<span class="lp-live-product-photo is-empty ${extra}" aria-hidden="true"><span>Image unavailable</span></span>`;
        }

        return `<span class="lp-live-product-photo ${extra}" aria-hidden="true"><img src="${escape(image)}" alt="" loading="lazy" decoding="async"></span>`;
    }

    function price(product) {
        const minor = Number(product.price_minor);

        return Number.isSafeInteger(minor) && minor >= 0
            ? priceFormatter.format(minor / 100)
            : "Price unavailable";
    }

    function saveButton(p) {
        return `<button type="button" class="lp-save" data-save="${p.id}" aria-pressed="${saved.has(p.id)}" aria-label="${saved.has(p.id) ? "Unsave" : "Save"} ${escape(p.name)}"><span class="material-symbols-outlined" aria-hidden="true">favorite</span></button>`;
    }

    function syncSaved() {
        $$("[data-save]").forEach((el) => {
            const p = products.find((p) => String(p.id) === el.dataset.save);
            if (!p) return;

            el.setAttribute("aria-pressed", saved.has(String(p.id)));
            el.setAttribute(
                "aria-label",
                `${saved.has(String(p.id)) ? "Unsave" : "Save"} ${p.name}`,
            );
        });
        const badge = $("[data-saved-count]");
        badge.textContent = saved.size;
        badge.hidden = !saved.size;
    }

    function toggleSave(id) {
        id = String(id);
        const p = products.find((p) => String(p.id) === id);
        if (!p) return;

        const remove = saved.has(id);
        remove ? saved.delete(id) : saved.add(id);

        try {
            localStorage.setItem(storageKey, JSON.stringify([...saved]));
        } catch {
            /* Storage can be unavailable in private mode. */
        }

        syncSaved();
        notify(
            remove ? "Removed from saved products." : "Saved on this device.",
        );
    }

    // Featured catalogue: 12 at a time, consistent mixed order, independent filters.
    const preferredGroups = [
        "Fashion",
        "Tech",
        "Beauty",
        "Home",
        "Books",
        "Accessories",
        "Sports",
        "Pets",
        "Food",
        "Kids & Baby",
        "Automotive",
    ];
    const availableGroups = new Set(
        products.map((product) => String(product.group || "").trim()).filter(Boolean),
    );
    const groups = [
        "All",
        ...preferredGroups.filter((group) => availableGroups.delete(group)),
        ...[...availableGroups].sort((a, b) => a.localeCompare(b)),
    ];

    let activeGroup = "All";

    const pageSize = 12;

    $(".lp-filters").innerHTML = groups
        .map(
            (g) =>
                `<button type="button" data-filter="${escape(g)}" aria-pressed="${g === "All"}">${escape(g)}</button>`,
        )
        .join("");

    function filtered() {
        return products.filter(
            (p) => activeGroup === "All" || String(p.group || "") === activeGroup,
        );
    }

    function renderProducts(animate = false) {
        const matches = filtered();
        const visible = matches.slice(0, pageSize);
        const grid = $("#lp-products");

        grid.innerHTML = visible
            .map(
                (p) =>
                    `<article class="lp-product"><a class="lp-product-open" href="${escape(p.detail_url)}" aria-label="View ${escape(p.name)}, priced ${escape(price(p))}">${photo(p)}<h3>${escape(p.name)}</h3><span class="lp-product-price">${escape(price(p))}</span><span class="lp-product-view">View product <span class="material-symbols-outlined" aria-hidden="true">arrow_outward</span></span></a>${saveButton(p)}</article>`,
            )
            .join("");

        const emptyState = $("#lp-products-empty");
        if (emptyState) emptyState.hidden = matches.length !== 0;

        $$("[data-filter]").forEach((b) =>
            b.setAttribute("aria-pressed", b.dataset.filter === activeGroup),
        );

        if (animate && !reduce.matches) {
            grid.classList.remove("is-changing");
            void grid.offsetWidth;
            grid.classList.add("is-changing");
        }
    }

    function selectGroup(group, scroll = false) {
        if (!groups.includes(group) && !preferredGroups.includes(group)) return;

        activeGroup = group;
        renderProducts(true);

        if (scroll)
            $("#bl-featured").scrollIntoView({
                behavior: reduce.matches ? "auto" : "smooth",
                block: "start",
            });
    }


    renderProducts();
    syncSaved();

    // Native dialogs provide focus containment, Escape support and focus return.
    const detail = $("#lp-detail"),
        detailTitle = $("#lp-detail-title"),
        detailContent = $("#lp-detail-content");

    let lastFocus;

    function openDialog(dialog) {
        lastFocus = document.activeElement;
        if (!dialog.open) dialog.showModal();
    }

    $$(".lp-dialog").forEach((dialog) => {
        dialog.addEventListener("close", () => {
            if (lastFocus?.isConnected)
                lastFocus.focus({ preventScroll: true });
        });

        dialog.addEventListener("click", (e) => {
            if (e.target !== dialog) return;

            const rect = dialog.getBoundingClientRect();

            if (
                e.clientX < rect.left ||
                e.clientX > rect.right ||
                e.clientY < rect.top ||
                e.clientY > rect.bottom
            )
                dialog.close();
        });
    });

    function showProduct(id) {
        const p = products.find((p) => String(p.id) === String(id));
        if (!p) return;

        detailTitle.textContent = p.name;
        detailContent.innerHTML = `${photo(p, "lp-detail-image")}<p class="lp-detail-price">${escape(price(p))}</p><p>Sign in as a Buyer to see the description, options, availability, and seller information.</p><a class="lp-button lp-preview-link" href="${escape(p.detail_url)}">View product preview <span aria-hidden="true">↗</span></a><div><button class="lp-text-link" type="button" data-detail-save="${escape(p.id)}">${saved.has(String(p.id)) ? "Remove from saved" : "Save this product"}</button></div>`;

        if (!detail.open) openDialog(detail);
    }

    function showSaved() {
        detailTitle.textContent = "Saved products";
        detailContent.innerHTML = saved.size
            ? `<p>Saved products on this device.</p>${products
                  .filter((p) => saved.has(p.id))
                  .map(
                       (p) =>
                            `<button type="button" class="lp-saved-item" data-product="${escape(p.id)}">${photo(p)}<span>${escape(p.name)}<br><small>${escape(price(p))}</small></span></button>`,
                  )
                  .join("")}`
            : "<p>No saved products yet. Tap a heart on a product to keep it here.</p>";

        openDialog(detail);
    }

    // Full category explorer uses the existing canonical category and subcategory data.
    const explorer = $("#lp-categories");

    function renderCategories() {
        const query = $("#lp-category-search").value.trim().toLowerCase();
        let count = 0;

        $("[data-category-groups]").innerHTML = categories
            .map((c) => {
                const categoryMatches = c.name.toLowerCase().includes(query);
                const subs = c.subcategories.filter(
                    (s) => categoryMatches || s.toLowerCase().includes(query),
                );

                if (!categoryMatches && !subs.length) return "";

                count++;

                const url = new URL(shop, location.origin);
                url.searchParams.set("category", c.slug);

                return `<section class="lp-category-group"><h3><a href="${escape(url.href)}">${escape(c.name)} ↗</a></h3>${subs
                    .map((s) => {
                        const subUrl = new URL(url);
                        subUrl.searchParams.set("subcategory", s);
                        return `<a href="${escape(subUrl.href)}">${escape(s)}</a>`;
                    })
                    .join("")}</section>`;
            })
            .join("");

        $("[data-category-empty]").hidden = count !== 0;
    }

    $("#lp-category-search").addEventListener("input", renderCategories);

    // Hero carousel: readable timing with explicit pause/play control.
    const hero = $(".lp-hero"),
        slides = $$(".lp-slide"),
        heroToggle = $("[data-hero-toggle]"),
        heroToggleIcon = $("[data-hero-toggle-icon]");

    const HERO_AUTOPLAY_MS = 5000;

    let slide = 0,
        timer,
        touchX = null,
        isPaused = false,
        isFocusInside = false;

    function updateHeroToggle() {
        if (!heroToggle) return;

        heroToggle.setAttribute("aria-pressed", String(isPaused));
        heroToggle.setAttribute(
            "aria-label",
            isPaused ? "Play slideshow" : "Pause slideshow",
        );

        if (heroToggleIcon)
            heroToggleIcon.textContent = isPaused ? "play_arrow" : "pause";
    }

    function scheduleHero() {
        clearTimeout(timer);

        // Respect reduced motion, page visibility, keyboard focus, and manual pause.
        if (
            !reduce.matches &&
            !document.hidden &&
            !isPaused &&
            !isFocusInside
        ) {
            timer = setTimeout(() => setSlide(slide + 1), HERO_AUTOPLAY_MS);
        }
    }

    function setSlide(next) {
        slide = (next + slides.length) % slides.length;

        slides.forEach((el, i) => {
            el.hidden = i !== slide;
            el.classList.toggle("is-active", i === slide);
        });

        $$("[data-slide-to]").forEach((b, i) =>
            b.setAttribute("aria-current", i === slide),
        );

        scheduleHero();
    }

    hero.addEventListener("focusin", () => {
        isFocusInside = true;
        clearTimeout(timer);
    });

    hero.addEventListener("focusout", (e) => {
        if (hero.contains(e.relatedTarget)) return;

        isFocusInside = false;
        scheduleHero();
    });

    heroToggle?.addEventListener("click", () => {
        isPaused = !isPaused;
        updateHeroToggle();
        scheduleHero();
    });

    hero.addEventListener("keydown", (e) => {
        if (e.target.matches("input,textarea")) return;

        if (e.key === "ArrowRight" || e.key === "ArrowLeft") {
            e.preventDefault();
            setSlide(slide + (e.key === "ArrowRight" ? 1 : -1));
        }
    });

    hero.addEventListener(
        "touchstart",
        (e) => {
            touchX = e.touches[0].clientX;
        },
        { passive: true },
    );

    hero.addEventListener(
        "touchend",
        (e) => {
            if (touchX === null) return;

            const delta = touchX - e.changedTouches[0].clientX;

            if (Math.abs(delta) > 60)
                setSlide(slide + (delta > 0 ? 1 : -1));

            touchX = null;
        },
        { passive: true },
    );

    document.addEventListener("visibilitychange", scheduleHero);
    reduce.addEventListener("change", scheduleHero);
    updateHeroToggle();
    scheduleHero();

    document.addEventListener("click", (e) => {
        const b = e.target.closest("button,a");
        if (!b) return;

        if (b.hasAttribute("data-save")) toggleSave(b.dataset.save);

        if (b.hasAttribute("data-product")) showProduct(b.dataset.product);

        if (b.hasAttribute("data-detail-save")) {
            toggleSave(b.dataset.detailSave);
            b.textContent = saved.has(b.dataset.detailSave)
                ? "Remove from saved"
                : "Save this product";
        }

        if (b.hasAttribute("data-filter")) selectGroup(b.dataset.filter);

        if (b.hasAttribute("data-category-filter"))
            selectGroup(b.dataset.categoryFilter, true);

        if (b.hasAttribute("data-hero-filter")) {
            e.preventDefault();
            selectGroup(b.dataset.heroFilter, true);
        }



        if (b.hasAttribute("data-saved-open")) showSaved();

        if (b.hasAttribute("data-categories-open")) {
            $("#lp-category-search").value = "";
            renderCategories();
            openDialog(explorer);
            $("#lp-category-search").focus();
        }

        if (b.hasAttribute("data-close-dialog"))
            b.closest("dialog").close();

        if (b.hasAttribute("data-slide-to"))
            setSlide(Number(b.dataset.slideTo));

        if (b.hasAttribute("data-hero-prev"))
            setSlide(slide - 1);

        if (b.hasAttribute("data-hero-next"))
            setSlide(slide + 1);

    });

    $("[data-newsletter]").addEventListener("submit", (e) => {
        e.preventDefault();
        $("[data-newsletter-status]").textContent =
            "Newsletter registration is not available yet. Your email has not been submitted.";
    });

    if ("IntersectionObserver" in window && !reduce.matches) {
        document.body.classList.add("lp-animate");

        const observer = new IntersectionObserver(
            (entries) =>
                entries.forEach((entry) => {
                    if (entry.isIntersecting) {
                        entry.target.classList.add("is-visible");
                        observer.unobserve(entry.target);
                    }
                }),
            { threshold: 0.08 },
        );

        $$(".lp-reveal").forEach((el) => observer.observe(el));
    }
}
