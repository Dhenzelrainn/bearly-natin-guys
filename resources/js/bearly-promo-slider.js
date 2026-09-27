/*
|--------------------------------------------------------------------------
| Bearly Homepage Promo Slider
|--------------------------------------------------------------------------
| Auto slides every 3 seconds.
| Hovering/focusing does NOT pause or reset the timer.
| Supports arrows, dots, keyboard navigation, and mobile swipe.
*/

document.addEventListener('DOMContentLoaded', () => {
    const slider = document.getElementById('bearly-promo-slider');

    if (!slider) return;

    const slides = Array.from(
        slider.querySelectorAll('.bearly-promo-slide')
    );

    const dots = Array.from(
        slider.querySelectorAll('.bearly-slider-dots button')
    );

    const prev = slider.querySelector('.bearly-slider-prev');
    const next = slider.querySelector('.bearly-slider-next');

    if (slides.length < 2) return;

    // Auto-slide every 3 seconds
    const AUTO_DELAY = 3000;

    let activeIndex = 0;
    let timer = null;

    let touchStartX = 0;
    let touchEndX = 0;

    function showSlide(index) {
        activeIndex = (index + slides.length) % slides.length;

        slides.forEach((slide, i) => {
            const active = i === activeIndex;

            slide.classList.toggle('is-active', active);

            slide.setAttribute(
                'aria-hidden',
                active ? 'false' : 'true'
            );
        });

        dots.forEach((dot, i) => {
            const active = i === activeIndex;

            dot.classList.toggle('is-active', active);

            dot.setAttribute(
                'aria-selected',
                active ? 'true' : 'false'
            );

            dot.tabIndex = active ? 0 : -1;
        });
    }

    function advance() {
        showSlide(activeIndex + 1);
    }

    function startTimer() {
        clearInterval(timer);

        timer = setInterval(() => {
            advance();
        }, AUTO_DELAY);
    }

    /*
    |--------------------------------------------------------------------------
    | Previous / Next
    |--------------------------------------------------------------------------
    */

    prev?.addEventListener('click', () => {
        showSlide(activeIndex - 1);

        // Fresh 3 seconds after manual navigation
        startTimer();
    });

    next?.addEventListener('click', () => {
        showSlide(activeIndex + 1);

        // Fresh 3 seconds after manual navigation
        startTimer();
    });

    /*
    |--------------------------------------------------------------------------
    | Slider Dots
    |--------------------------------------------------------------------------
    */

    dots.forEach((dot, index) => {
        dot.addEventListener('click', () => {
            showSlide(index);

            // Fresh 3 seconds after manual navigation
            startTimer();
        });
    });

    /*
    |--------------------------------------------------------------------------
    | Keyboard Navigation
    |--------------------------------------------------------------------------
    */

    slider.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowLeft') {
            event.preventDefault();

            showSlide(activeIndex - 1);
            startTimer();
        }

        if (event.key === 'ArrowRight') {
            event.preventDefault();

            showSlide(activeIndex + 1);
            startTimer();
        }
    });

    /*
    |--------------------------------------------------------------------------
    | Mobile Swipe
    |--------------------------------------------------------------------------
    */

    slider.addEventListener(
        'touchstart',
        (event) => {
            touchStartX = event.changedTouches[0].screenX;
            touchEndX = touchStartX;
        },
        { passive: true }
    );

    slider.addEventListener(
        'touchmove',
        (event) => {
            touchEndX = event.changedTouches[0].screenX;
        },
        { passive: true }
    );

    slider.addEventListener(
        'touchend',
        () => {
            const distance = touchEndX - touchStartX;
            const threshold = 45;

            if (Math.abs(distance) >= threshold) {
                if (distance < 0) {
                    showSlide(activeIndex + 1);
                } else {
                    showSlide(activeIndex - 1);
                }

                startTimer();
            }
        },
        { passive: true }
    );

    /*
    |--------------------------------------------------------------------------
    | Browser Tab Visibility
    |--------------------------------------------------------------------------
    */

    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            clearInterval(timer);
            timer = null;
        } else {
            startTimer();
        }
    });

    /*
    |--------------------------------------------------------------------------
    | Initialize
    |--------------------------------------------------------------------------
    */

    showSlide(0);
    startTimer();
});