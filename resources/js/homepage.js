document.addEventListener('DOMContentLoaded', () => {
    const toast = document.getElementById('toast');
    let toastTimer;

    const showToast = (message) => {
        if (!toast) return;
        toast.textContent = message;
        toast.classList.add('show');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => toast.classList.remove('show'), 2200);
    };

    document.querySelectorAll('.wishlist-product').forEach((button) => {
        button.addEventListener('click', () => {
            button.classList.toggle('liked');
            button.textContent = button.classList.contains('liked') ? '♥' : '♡';
            showToast(button.classList.contains('liked') ? 'Added to wishlist' : 'Removed from wishlist');
        });
    });

    document.querySelectorAll('.add-to-cart-btn').forEach((button) => {
        button.addEventListener('click', () => {
            const counter = document.getElementById('cartCount');
            const current = Number(counter?.textContent || 0) + 1;
            if (counter) counter.textContent = current;
            showToast('Product added to cart');
        });
    });

    const menuButton = document.querySelector('.mobile-menu');
    const sidebar = document.querySelector('.category-sidebar');
    menuButton?.addEventListener('click', () => sidebar?.classList.toggle('open'));

    const slides = [...document.querySelectorAll('.hero-banner[data-slide]')];
    const slideButtons = [...document.querySelectorAll('[data-slide-to]')];
    let activeSlide = 0;
    let sliderTimer;

    const showSlide = (index) => {
        activeSlide = (index + slides.length) % slides.length;
        slides.forEach((slide, slideIndex) => slide.classList.toggle('is-active', slideIndex === activeSlide));
        slideButtons.forEach((button, buttonIndex) => {
            button.classList.toggle('selected', buttonIndex === activeSlide);
            button.setAttribute('aria-selected', buttonIndex === activeSlide ? 'true' : 'false');
        });
    };

    const restartSlider = () => {
        clearInterval(sliderTimer);
        sliderTimer = setInterval(() => showSlide(activeSlide + 1), 5000);
    };

    slideButtons.forEach((button) => button.addEventListener('click', () => {
        showSlide(Number(button.dataset.slideTo));
        restartSlider();
    }));

    const slider = document.querySelector('.hero-slider');
    slider?.addEventListener('mouseenter', () => clearInterval(sliderTimer));
    slider?.addEventListener('mouseleave', restartSlider);
    if (slides.length) restartSlider();

    let remaining = 2 * 60 * 60 + 45 * 60 + 18;
    const updateCountdown = () => {
        const hours = document.getElementById('hours');
        const minutes = document.getElementById('minutes');
        const seconds = document.getElementById('seconds');
        if (!hours || !minutes || !seconds) return;
        hours.textContent = String(Math.floor(remaining / 3600)).padStart(2, '0');
        minutes.textContent = String(Math.floor((remaining % 3600) / 60)).padStart(2, '0');
        seconds.textContent = String(remaining % 60).padStart(2, '0');
        remaining = remaining > 0 ? remaining - 1 : 3 * 60 * 60;
    };
    updateCountdown();
    setInterval(updateCountdown, 1000);
});
