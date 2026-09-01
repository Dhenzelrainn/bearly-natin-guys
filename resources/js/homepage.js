/* =========================================================
   BEARLY E-COMMERCE JAVASCRIPT
========================================================= */

document.addEventListener("DOMContentLoaded", function () {


    /* =====================================================
       CART
    ====================================================== */

    let cartCount = 0;

    const cartCounter = document.getElementById("cartCount");

    const cartButtons = document.querySelectorAll(
        ".add-cart-button"
    );


    cartButtons.forEach(function (button) {

        button.addEventListener("click", function () {

            cartCount++;

            cartCounter.textContent = cartCount;

            showToast("Product added to cart!");

        });

    });



    /* =====================================================
       WISHLIST
    ====================================================== */

    const wishlistButtons = document.querySelectorAll(
        ".wishlist-product"
    );

    wishlistButtons.forEach(function (button) {

        button.addEventListener("click", function () {

            if (button.classList.contains("liked")) {

                button.classList.remove("liked");

                button.textContent = "♡";

                showToast("Removed from wishlist!");

            } else {

                button.classList.add("liked");

                button.textContent = "♥";

                showToast("Added to wishlist!");

            }

        });

    });



    /* =====================================================
       SEARCH
    ====================================================== */

    const searchInput = document.getElementById(
        "searchInput"
    );

    const searchButton = document.getElementById(
        "searchButton"
    );

    const products = document.querySelectorAll(
        ".product-card"
    );


    function searchProducts() {

        const searchValue =
            searchInput.value
                .toLowerCase()
                .trim();


        products.forEach(function (product) {

            const productName =
                product.dataset.productName;


            if (
                productName.includes(searchValue)
                || searchValue === ""
            ) {

                product.style.display = "";

            } else {

                product.style.display = "none";

            }

        });

    }


    searchButton.addEventListener(
        "click",
        searchProducts
    );


    searchInput.addEventListener(
        "keyup",
        function (event) {

            if (event.key === "Enter") {

                searchProducts();

            }

        }
    );



    /* =====================================================
       COUNTDOWN TIMER
    ====================================================== */

    let totalSeconds =
        (2 * 60 * 60)
        + (34 * 60)
        + 20;


    const hoursElement =
        document.getElementById("hours");

    const minutesElement =
        document.getElementById("minutes");

    const secondsElement =
        document.getElementById("seconds");


    function updateCountdown() {

        if (totalSeconds <= 0) {

            totalSeconds = 3 * 60 * 60;

        }


        const hours =
            Math.floor(totalSeconds / 3600);


        const minutes =
            Math.floor(
                (totalSeconds % 3600) / 60
            );


        const seconds =
            totalSeconds % 60;


        hoursElement.textContent =
            String(hours).padStart(2, "0");


        minutesElement.textContent =
            String(minutes).padStart(2, "0");


        secondsElement.textContent =
            String(seconds).padStart(2, "0");


        totalSeconds--;

    }


    updateCountdown();

    setInterval(
        updateCountdown,
        1000
    );



    /* =====================================================
       VOUCHERS
    ====================================================== */

    const claimButtons =
        document.querySelectorAll(
            ".claim-button"
        );


    claimButtons.forEach(function (button) {

        button.addEventListener(
            "click",
            function () {

                const code =
                    button.dataset.code;


                navigator.clipboard
                    .writeText(code)
                    .then(function () {

                        showToast(
                            "Voucher " +
                            code +
                            " copied!"
                        );

                    })
                    .catch(function () {

                        showToast(
                            "Voucher code: " +
                            code
                        );

                    });

            }
        );

    });



    /* =====================================================
       HERO BUTTON
    ====================================================== */

    const shopButton =
        document.getElementById(
            "shopNowButton"
        );


    if (shopButton) {

        shopButton.addEventListener(
            "click",
            function () {

                document
                    .querySelector(".flash-section")
                    .scrollIntoView({
                        behavior: "smooth"
                    });

            }
        );

    }



    /* =====================================================
       TOAST
    ====================================================== */

    function showToast(message) {

        const toast =
            document.getElementById("toast");


        toast.textContent =
            message;


        toast.classList.add("show");


        setTimeout(function () {

            toast.classList.remove(
                "show"
            );

        }, 2500);

    }



    /* =====================================================
       NAVIGATION
    ====================================================== */

    const navLinks =
        document.querySelectorAll(
            ".main-navigation a"
        );


    navLinks.forEach(function (link) {

        link.addEventListener(
            "click",
            function (event) {

                event.preventDefault();


                navLinks.forEach(
                    function (item) {

                        item.classList.remove(
                            "active"
                        );

                    }
                );


                link.classList.add(
                    "active"
                );

            }
        );

    });

});