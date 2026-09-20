/*
|--------------------------------------------------------------------------
| Bearly Homepage Promo Slider
|--------------------------------------------------------------------------
| Auto slides every 5 seconds, pauses on hover/focus, supports arrows, dots,
| keyboard navigation, and mobile swipe.
*/

document.addEventListener('DOMContentLoaded', () => {
    const slider = document.getElementById('bearly-promo-slider');

    if (!slider) return;

    const slides = Array.from(slider.querySelectorAll('.bearly-promo-slide'));
    const dots = Array.from(slider.querySelectorAll('.bearly-slider-dots button'));
    const prev = slider.querySelector('.bearly-slider-prev');
    const next = slider.querySelector('.bearly-slider-next');

    if (slides.length < 2) return;

    const AUTO_DELAY = 5000;
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    let activeIndex = 0;
    let timer = null;
    let touchStartX = 0;
    let touchEndX = 0;
    let paused = false;

    function restartProgress() {
        slider.classList.remove('is-running');

        // Force animation restart.
        void slider.offsetWidth;

        if (!reducedMotion && !paused) {
            slider.classList.add('is-running');
        }
    }

    function showSlide(index, userInitiated = false) {
        activeIndex = (index + slides.length) % slides.length;

        slides.forEach((slide, i) => {
            const active = i === activeIndex;
            slide.classList.toggle('is-active', active);
            slide.setAttribute('aria-hidden', active ? 'false' : 'true');
        });

        dots.forEach((dot, i) => {
            const active = i === activeIndex;
            dot.classList.toggle('is-active', active);
            dot.setAttribute('aria-selected', active ? 'true' : 'false');
            dot.tabIndex = active ? 0 : -1;
        });

        restartProgress();

        if (userInitiated) {
            restartTimer();
        }
    }

    function advance() {
        showSlide(activeIndex + 1);
    }

    function startTimer() {
        if (reducedMotion || paused) return;
        clearInterval(timer);
        timer = setInterval(advance, AUTO_DELAY);
        restartProgress();
    }

    function stopTimer() {
        clearInterval(timer);
        timer = null;
        slider.classList.remove('is-running');
    }

    function restartTimer() {
        stopTimer();
        startTimer();
    }

    function pause() {
        paused = true;
        stopTimer();
    }

    function resume() {
        paused = false;
        startTimer();
    }

    prev?.addEventListener('click', () => {
        showSlide(activeIndex - 1, true);
    });

    next?.addEventListener('click', () => {
        showSlide(activeIndex + 1, true);
    });

    dots.forEach((dot, index) => {
        dot.addEventListener('click', () => {
            showSlide(index, true);
        });
    });

    slider.addEventListener('mouseenter', pause);
    slider.addEventListener('mouseleave', resume);
    slider.addEventListener('focusin', pause);

    slider.addEventListener('focusout', (event) => {
        if (!slider.contains(event.relatedTarget)) {
            resume();
        }
    });

    slider.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowLeft') {
            event.preventDefault();
            showSlide(activeIndex - 1, true);
        }

        if (event.key === 'ArrowRight') {
            event.preventDefault();
            showSlide(activeIndex + 1, true);
        }
    });

    slider.addEventListener('touchstart', (event) => {
        touchStartX = event.changedTouches[0].screenX;
        touchEndX = touchStartX;
        pause();
    }, { passive: true });

    slider.addEventListener('touchmove', (event) => {
        touchEndX = event.changedTouches[0].screenX;
    }, { passive: true });

    slider.addEventListener('touchend', () => {
        const distance = touchEndX - touchStartX;
        const threshold = 45;

        if (Math.abs(distance) >= threshold) {
            if (distance < 0) {
                showSlide(activeIndex + 1);
            } else {
                showSlide(activeIndex - 1);
            }
        }

        paused = false;
        restartTimer();
    }, { passive: true });

    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            stopTimer();
        } else if (!paused) {
            startTimer();
        }
    });

    showSlide(0);
    startTimer();
});
