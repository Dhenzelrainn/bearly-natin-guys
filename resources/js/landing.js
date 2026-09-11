/* Bearly landing preview. Demo identities never become database/cart product IDs. */
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
    const storageKey = "bearly-landing-saved-v2";
    let saved = new Set(),
        toastTimer;
    try {
        const stored = JSON.parse(localStorage.getItem(storageKey) || "[]");
        if (Array.isArray(stored))
            saved = new Set(
                stored.filter((id) => products.some((p) => p.id === id)),
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
    function photo(p, extra = "") {
        const rows = p.atlas === "mixed" ? 3 : 4;
        return `<span class="lp-product-photo ${extra}" data-atlas="${p.atlas}" style="--x:${((p.cell % 4) * 100) / 3}%;--y:${(Math.floor(p.cell / 4) * 100) / (rows - 1)}%" role="img" aria-label="Illustrative photo: ${escape(p.name)}"></span>`;
    }
    function saveButton(p) {
        return `<button type="button" class="lp-save" data-save="${p.id}" aria-pressed="${saved.has(p.id)}" aria-label="${saved.has(p.id) ? "Unsave" : "Save"} ${escape(p.name)}"><span class="material-symbols-outlined" aria-hidden="true">favorite</span></button>`;
    }
    function syncSaved() {
        $$("[data-save]").forEach((el) => {
            const p = products.find((p) => p.id === el.dataset.save);
            el.setAttribute("aria-pressed", saved.has(p.id));
            el.setAttribute(
                "aria-label",
                `${saved.has(p.id) ? "Unsave" : "Save"} ${p.name}`,
            );
        });
        const badge = $("[data-saved-count]");
        badge.textContent = saved.size;
        badge.hidden = !saved.size;
    }
    function toggleSave(id) {
        const p = products.find((p) => p.id === id);
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
    const groups = [
        "All",
        "Fashion",
        "Tech",
        "Beauty",
        "Home",
        "Books",
        "Accessories",
        "Sports",
        "Pets",
    ];
    let activeGroup = "All",
        page = 0;
    const pageSize = 12;
    $(".lp-filters").innerHTML = groups
        .map(
            (g) =>
                `<button type="button" data-filter="${g}" aria-pressed="${g === "All"}">${g}</button>`,
        )
        .join("");
    function filtered() {
        return products.filter(
            (p) => activeGroup === "All" || p.group === activeGroup,
        );
    }
    function renderProducts(animate = false) {
        const matches = filtered();
        const pages = Math.max(1, Math.ceil(matches.length / pageSize));
        page = Math.min(page, pages - 1);
        const visible = matches.slice(page * pageSize, (page + 1) * pageSize);
        const grid = $("#lp-products");
        grid.innerHTML = visible
            .map(
                (p) =>
                    `<article class="lp-product"><button type="button" class="lp-product-open" data-product="${p.id}" aria-label="Preview ${escape(p.name)}">${photo(p)}<h3>${escape(p.name)}</h3></button><p>${escape(p.shop)}</p>${saveButton(p)}</article>`,
            )
            .join("");
        $$("[data-filter]").forEach((b) =>
            b.setAttribute("aria-pressed", b.dataset.filter === activeGroup),
        );
        $("[data-product-status]").textContent =
            `${page * pageSize + 1}–${Math.min((page + 1) * pageSize, matches.length)} of ${matches.length} ${activeGroup === "All" ? "finds" : activeGroup.toLowerCase() + " finds"}`;
        $(".lp-product-pages").innerHTML = Array.from(
            { length: pages },
            (_, i) =>
                `<button type="button" data-product-page="${i}" aria-label="Product page ${i + 1}" aria-current="${i === page}"></button>`,
        ).join("");
        $("[data-products-prev]").disabled = page === 0;
        $("[data-products-next]").disabled = page === pages - 1;
        if (animate && !reduce.matches) {
            grid.classList.remove("is-changing");
            void grid.offsetWidth;
            grid.classList.add("is-changing");
        }
    }
    function selectGroup(group, scroll = false) {
        if (!groups.includes(group)) return;
        activeGroup = group;
        page = 0;
        renderProducts(true);
        if (scroll)
            $("#bl-featured").scrollIntoView({
                behavior: reduce.matches ? "auto" : "smooth",
                block: "start",
            });
    }
    function changePage(next) {
        const pages = Math.ceil(filtered().length / pageSize);
        if (next < 0 || next >= pages) return;
        page = next;
        renderProducts(true);
        $("#bl-featured").scrollIntoView({
            behavior: reduce.matches ? "auto" : "smooth",
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
        const p = products.find((p) => p.id === id);
        if (!p) return;
        const url = new URL(shop, location.origin);
        url.searchParams.set("search", p.name);
        detailTitle.textContent = p.name;
        detailContent.innerHTML = `${photo(p, "lp-detail-image")}<p><strong>${escape(p.shop)}</strong> · ${escape(p.group)}</p><p>Sample catalogue preview. This photo is illustrative; price, stock and final specifications have not been confirmed.</p><div><button class="lp-button" type="button" data-detail-save="${p.id}">${saved.has(p.id) ? "Remove from saved" : "Save this product"}</button></div><a class="lp-text-link" href="${escape(url.href)}">Search the shop ↗</a>${p.source ? `<a class="lp-text-link" href="${escape(p.source)}" target="_blank" rel="noopener noreferrer">Retailer reference ↗</a>` : ""}`;
        if (!detail.open) openDialog(detail);
    }
    function showSaved() {
        detailTitle.textContent = "Saved products";
        detailContent.innerHTML = saved.size
            ? `<p>Saved sample products on this device.</p>${products
                  .filter((p) => saved.has(p.id))
                  .map(
                      (p) =>
                          `<button type="button" class="lp-saved-item" data-product="${p.id}">${photo(p)}<span>${escape(p.name)}<br><small>${escape(p.shop)}</small></span></button>`,
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

    // Auto-rotation stops for intentional interaction, focus, reduced motion or hidden tabs.
    const hero = $(".lp-hero"),
        slides = $$(".lp-slide");
    let slide = 0,
        paused = reduce.matches,
        hovering = false,
        timer,
        touchX = null;
    function syncPause() {
        const button = $("[data-hero-pause]");
        button.setAttribute(
            "aria-label",
            paused ? "Play slideshow" : "Pause slideshow",
        );
        button.querySelector("span").textContent = paused
            ? "play_arrow"
            : "pause";
    }
    function schedule() {
        clearTimeout(timer);
        if (
            !paused &&
            !hovering &&
            !document.hidden &&
            !hero.contains(document.activeElement)
        )
            timer = setTimeout(() => setSlide(slide + 1), 6500);
    }
    function setSlide(next, user = false) {
        slide = (next + slides.length) % slides.length;
        slides.forEach((el, i) => {
            el.hidden = i !== slide;
            el.classList.toggle("is-active", i === slide);
        });
        $$("[data-slide-to]").forEach((b, i) =>
            b.setAttribute("aria-current", i === slide),
        );
        if (user) paused = true;
        syncPause();
        schedule();
    }
    hero.addEventListener("pointerenter", () => {
        hovering = true;
        schedule();
    });
    hero.addEventListener("pointerleave", () => {
        hovering = false;
        schedule();
    });
    hero.addEventListener("focusin", () => {
        clearTimeout(timer);
    });
    hero.addEventListener("focusout", () => setTimeout(schedule, 0));
    hero.addEventListener("keydown", (e) => {
        if (e.target.matches("input,textarea")) return;
        if (e.key === "ArrowRight" || e.key === "ArrowLeft") {
            e.preventDefault();
            setSlide(slide + (e.key === "ArrowRight" ? 1 : -1), true);
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
                setSlide(slide + (delta > 0 ? 1 : -1), true);
            touchX = null;
        },
        { passive: true },
    );
    document.addEventListener("visibilitychange", schedule);
    reduce.addEventListener("change", () => {
        if (reduce.matches) paused = true;
        syncPause();
        schedule();
    });
    syncPause();
    schedule();

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
        if (b.hasAttribute("data-product-page"))
            changePage(Number(b.dataset.productPage));
        if (b.hasAttribute("data-products-prev")) changePage(page - 1);
        if (b.hasAttribute("data-products-next")) changePage(page + 1);
        if (b.hasAttribute("data-saved-open")) showSaved();
        if (b.hasAttribute("data-categories-open")) {
            $("#lp-category-search").value = "";
            renderCategories();
            openDialog(explorer);
            $("#lp-category-search").focus();
        }
        if (b.hasAttribute("data-close-dialog")) b.closest("dialog").close();
        if (b.hasAttribute("data-slide-to"))
            setSlide(Number(b.dataset.slideTo), true);
        if (b.hasAttribute("data-hero-prev")) setSlide(slide - 1, true);
        if (b.hasAttribute("data-hero-next")) setSlide(slide + 1, true);
        if (b.hasAttribute("data-hero-pause")) {
            paused = !paused;
            syncPause();
            schedule();
        }
        if (b.hasAttribute("data-policy")) {
            detailTitle.textContent =
                b.dataset.policy === "privacy"
                    ? "Privacy Policy"
                    : "Terms of Service";
            detailContent.innerHTML =
                "<p>The full policy will be published before the marketplace begins accepting orders.</p><p>Saved sample products stay in this browser. Newsletter registration is not yet available.</p>";
            openDialog(detail);
        }
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
