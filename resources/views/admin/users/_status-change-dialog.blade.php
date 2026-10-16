<div
    class="modal-shell admin-user-status-dialog"
    data-modal="user-status-change"
    data-user-status-dialog
    hidden
>
    <button
        class="modal-backdrop"
        type="button"
        data-close-modal
        aria-label="Cancel account status change"
    ></button>

    <section
        class="modal-card modal-card-medium"
        role="dialog"
        aria-modal="true"
        aria-labelledby="user-status-dialog-title"
        aria-describedby="user-status-dialog-description"
        tabindex="-1"
    >
        <div class="modal-heading">
            <div>
                <span class="eyebrow">Account status</span>
                <h2 id="user-status-dialog-title">Confirm status change</h2>
            </div>

            <button
                class="icon-button"
                type="button"
                data-close-modal
                aria-label="Close status confirmation"
            >
                <i data-lucide="x"></i>
            </button>
        </div>

        <p class="modal-description" id="user-status-dialog-description" data-user-status-description>
            Choose a status change and provide a reason for the audit record.
        </p>

        <form data-user-status-form>
            <input type="hidden" name="status" data-user-status-value>

            <div class="form-field">
                <label for="user-status-reason">Reason <span aria-hidden="true">*</span></label>
                <textarea
                    id="user-status-reason"
                    name="reason"
                    rows="4"
                    maxlength="1000"
                    required
                    aria-describedby="user-status-reason-help user-status-error"
                ></textarea>
                <small class="helper-text" id="user-status-reason-help">This reason is saved in the account audit history.</small>
                <small class="registration-review-feedback" data-user-status-error role="alert" hidden></small>
            </div>

            <div class="modal-footer">
                <button class="button button-secondary" type="button" data-close-modal>Cancel</button>
                <button class="button button-primary" type="submit" data-user-status-submit>
                    Confirm change
                </button>
            </div>
        </form>
    </section>
</div>
