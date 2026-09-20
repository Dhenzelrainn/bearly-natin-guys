@extends('layouts.admin')

@section('title', 'Platform Settings')
@section('page-title', 'Platform Settings')

@section('content')

<section class="page-hero">
    <div>
        <span class="eyebrow">System Management</span>
        <h1>Platform settings</h1>
        <p>
            Configure marketplace-wide operational settings, registration availability,
            transaction defaults, and system behavior.
        </p>
    </div>
</section>

<section class="settings-grid">

    <article class="panel settings-editor-panel">
        <div class="panel-heading">
            <div>
                <span class="eyebrow">Marketplace Configuration</span>
                <h2>General platform settings</h2>
            </div>
        </div>

        <div class="form-grid two-column-form">
            <label class="form-field">
                <span>Marketplace name</span>
                <input
                    type="text"
                    value="Bearly"
                    data-setting-marketplace-name
                >
            </label>

            <label class="form-field">
                <span>Platform commission rate</span>
                <div class="input-with-suffix">
                    <input
                        type="number"
                        min="0"
                        max="100"
                        step="0.1"
                        value="10"
                        data-setting-commission-rate
                    >
                    <span>%</span>
                </div>
            </label>
        </div>

        <label class="form-field">
            <span>Marketplace description</span>
            <textarea
                rows="4"
                data-setting-marketplace-description
            >Bearly is an e-commerce marketplace connecting Buyers, Sellers, Logistics Centers, and Riders.</textarea>
        </label>
    </article>

    <aside class="panel">
        <div class="panel-heading">
            <div>
                <span class="eyebrow">System Status</span>
                <h2>Platform availability</h2>
            </div>
        </div>

        <div class="settings-toggle-list">

            <label class="settings-toggle-row">
                <div>
                    <strong>Marketplace operations</strong>
                    <small>
                        Allow users to access and use the marketplace normally.
                    </small>
                </div>

                <input
                    type="checkbox"
                    data-setting-platform-active
                    checked
                >
            </label>

            <label class="settings-toggle-row">
                <div>
                    <strong>Maintenance mode</strong>
                    <small>
                        Temporarily restrict marketplace access during maintenance.
                    </small>
                </div>

                <input
                    type="checkbox"
                    data-setting-maintenance-mode
                >
            </label>

        </div>

        <div
            class="settings-availability-state"
            data-platform-availability-state
        >

            <i
                data-lucide="circle-check"
                data-platform-availability-icon
            ></i>

            <span data-platform-availability-message>
                Marketplace is available to users.
            </span>

        </div>

    </aside>

</section>

<section class="panel">
    <div class="panel-heading">
        <div>
            <span class="eyebrow">Registration Management</span>
            <h2>Account registration availability</h2>
            <p>
                Control which account types can currently submit registration requests.
            </p>
        </div>
    </div>

    <div class="settings-toggle-grid">

        <label class="settings-toggle-card">
            <div>
                <i data-lucide="shopping-bag"></i>
            </div>

            <span>
                <strong>Buyer registration</strong>
                <small>Allow new Buyer accounts to register.</small>
            </span>

            <input
                type="checkbox"
                data-setting-registration="buyer"
                checked
            >
        </label>

        <label class="settings-toggle-card">
            <div>
                <i data-lucide="store"></i>
            </div>

            <span>
                <strong>Seller registration</strong>
                <small>Allow new Sellers to submit applications.</small>
            </span>

            <input
                type="checkbox"
                data-setting-registration="seller"
                checked
            >
        </label>

        <label class="settings-toggle-card">
            <div>
                <i data-lucide="warehouse"></i>
            </div>

            <span>
                <strong>Logistics registration</strong>
                <small>Allow Logistics Centers to submit applications.</small>
            </span>

            <input
                type="checkbox"
                data-setting-registration="logistics"
                checked
            >
        </label>

        <label class="settings-toggle-card">
            <div>
                <i data-lucide="bike"></i>
            </div>

            <span>
                <strong>Rider registration</strong>
                <small>Allow Riders to submit applications through Logistics Centers.</small>
            </span>

            <input
                type="checkbox"
                data-setting-registration="rider"
                checked
            >
        </label>

    </div>
</section>

<section class="panel">
    <div class="panel-heading">
        <div>
            <span class="eyebrow">Transactions</span>
            <h2>Order and payment settings</h2>
        </div>
    </div>

    <div class="form-grid two-column-form">

        <label class="form-field">
            <span>Default order cancellation window</span>
            <div class="input-with-suffix">
                <input
                    type="number"
                    min="1"
                    value="24"
                    data-setting-cancellation-hours
                >
                <span>hours</span>
            </div>
        </label>

        <label class="form-field">
            <span>Seller settlement period</span>
            <select data-setting-settlement-period>
                <option value="7">Every 7 days</option>
                <option value="14">Every 14 days</option>
                <option value="30">Every 30 days</option>
            </select>
        </label>

    </div>
</section>

<section class="panel settings-save-panel">

    <div class="panel-heading panel-heading-wrap">

        <div>
            <span class="eyebrow">
                Administrative Controls
            </span>

            <h2>
                Save platform configuration
            </h2>

            <p>
                Review and apply marketplace-wide configuration changes.
            </p>
        </div>


        <div
            class="settings-save-state"
            data-settings-state
            data-state="saved"
        >

            <span class="settings-save-state-icon">
                <i data-lucide="circle-check"></i>
            </span>

            <div>
                <strong data-settings-state-title>
                    Configuration saved
                </strong>

                <small data-settings-state-message>
                    Current settings match the saved platform configuration.
                </small>
            </div>

        </div>

    </div>


    <div class="panel-footer-actions">

        <button
            class="button button-secondary"
            type="button"
            data-open-modal="reset-platform-settings"
        >
            <i data-lucide="rotate-ccw"></i>
            Reset settings
        </button>


        <button
            class="button button-primary"
            type="button"
            data-settings-save
        >
            <i data-lucide="save"></i>
            Save settings
        </button>

    </div>

</section>

<div
    class="modal-shell"
    data-modal="reset-platform-settings"
    hidden
>

    <button
        type="button"
        class="modal-backdrop"
        data-close-modal
        aria-label="Close reset confirmation"
    ></button>


    <section
        class="modal-card"
        role="dialog"
        aria-modal="true"
        aria-labelledby="reset-platform-settings-title"
    >

        <div class="modal-heading">

            <div>
                <span class="eyebrow">
                    Platform Settings
                </span>

                <h2 id="reset-platform-settings-title">
                    Reset platform settings?
                </h2>
            </div>


            <button
                type="button"
                class="icon-button"
                data-close-modal
                aria-label="Close"
            >
                <i data-lucide="x"></i>
            </button>

        </div>


        <div class="review-details">

            <div class="detail-note">

                <span>
                    Restore default configuration
                </span>

                <p>
                    This will restore marketplace settings,
                    registration availability, transaction defaults,
                    and platform availability controls to their default values.
                </p>

            </div>

        </div>


        <div class="modal-footer decision-footer">

            <button
                type="button"
                class="button button-secondary"
                data-close-modal
            >
                Cancel
            </button>


            <button
                type="button"
                class="button button-danger-soft"
                data-settings-reset-confirm
            >
                <i data-lucide="rotate-ccw"></i>
                Reset settings
            </button>

        </div>

    </section>

</div>

@endsection