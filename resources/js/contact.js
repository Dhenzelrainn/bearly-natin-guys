(() => {
    "use strict";

    const form = document.querySelector("[data-contact-form]");
    const topicSelect = document.getElementById("contact-topic");
    const subjectInput = document.getElementById("contact-subject");
    const messageInput = document.getElementById("contact-message");
    const counter = document.querySelector("[data-contact-count]");
    const topicButtons = document.querySelectorAll("[data-contact-topic]");
    const reportButton = document.querySelector("[data-contact-report]");
    const toast = document.querySelector("[data-contact-toast]");

    if (!form) {
        return;
    }

    let toastTimer = null;

    const showToast = (message) => {
        if (!toast) return;

        toast.textContent = message;
        toast.hidden = false;

        window.clearTimeout(toastTimer);

        toastTimer = window.setTimeout(() => {
            toast.hidden = true;
        }, 4200);
    };

    const scrollToForm = () => {
        form.scrollIntoView({
            behavior: "smooth",
            block: "center",
        });
    };

    const chooseTopic = (topic) => {
        if (!topicSelect) return;

        const option = [...topicSelect.options].find(
            (item) => item.value === topic
        );

        if (option) {
            topicSelect.value = topic;
            topicSelect.dispatchEvent(new Event("change", { bubbles: true }));
        }

        scrollToForm();

        window.setTimeout(() => {
            topicSelect.focus();
        }, 450);
    };

    topicButtons.forEach((button) => {
        button.addEventListener("click", () => {
            chooseTopic(button.dataset.contactTopic || "");
        });
    });

    reportButton?.addEventListener("click", () => {
        chooseTopic("Complaints & Disputes");

        if (subjectInput && !subjectInput.value.trim()) {
            subjectInput.value = "Complaint / dispute concern";
        }
    });

    const updateCount = () => {
        if (!messageInput || !counter) return;

        counter.textContent = `${messageInput.value.length}/1000`;
    };

    messageInput?.addEventListener("input", updateCount);
    updateCount();

    const fields = {
        "contact-name": {
            element: document.getElementById("contact-name"),
            validate: (value) =>
                value.trim().length >= 2
                    ? ""
                    : "Please enter your full name.",
        },

        "contact-email": {
            element: document.getElementById("contact-email"),
            validate: (value) => {
                const trimmed = value.trim();

                if (!trimmed) {
                    return "Please enter your email address.";
                }

                const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

                return emailPattern.test(trimmed)
                    ? ""
                    : "Please enter a valid email address.";
            },
        },

        "contact-topic": {
            element: topicSelect,
            validate: (value) =>
                value ? "" : "Please choose a support topic.",
        },

        "contact-subject": {
            element: subjectInput,
            validate: (value) =>
                value.trim().length >= 3
                    ? ""
                    : "Please enter a short subject.",
        },

        "contact-message": {
            element: messageInput,
            validate: (value) =>
                value.trim().length >= 10
                    ? ""
                    : "Please provide at least a few details about your concern.",
        },
    };

    const setFieldError = (id, message) => {
        const config = fields[id];
        const error = document.querySelector(`[data-error-for="${id}"]`);

        if (!config?.element) return;

        config.element.setAttribute(
            "aria-invalid",
            message ? "true" : "false"
        );

        if (error) {
            error.textContent = message;
        }
    };

    Object.entries(fields).forEach(([id, config]) => {
        if (!config.element) return;

        const eventName =
            config.element.tagName === "SELECT" ? "change" : "input";

        config.element.addEventListener(eventName, () => {
            setFieldError(
                id,
                config.validate(config.element.value)
            );
        });
    });

    form.addEventListener("submit", (event) => {
        event.preventDefault();

        let firstInvalid = null;
        let valid = true;

        Object.entries(fields).forEach(([id, config]) => {
            if (!config.element) return;

            const errorMessage = config.validate(config.element.value);

            setFieldError(id, errorMessage);

            if (errorMessage) {
                valid = false;

                if (!firstInvalid) {
                    firstInvalid = config.element;
                }
            }
        });

        if (!valid) {
            firstInvalid?.focus();
            showToast("Please complete the required fields before sending.");
            return;
        }

        showToast(
            "Your message is ready. Sending will be connected when Bearly’s backend support module is implemented."
        );

        form.reset();

        Object.keys(fields).forEach((id) => {
            setFieldError(id, "");
        });

        updateCount();
    });
})();
