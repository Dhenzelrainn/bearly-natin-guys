@if (session('success'))
    <div class="toast-stack admin-server-toast-stack" aria-live="polite" aria-atomic="true">
        <div class="admin-toast" data-admin-server-toast role="status">
            <span><i data-lucide="circle-check" aria-hidden="true"></i></span>
            <div>
                <strong>{{ session('success_title', 'Saved successfully') }}</strong>
                <small>{{ session('success') }}</small>
            </div>
            <button type="button" data-admin-toast-dismiss aria-label="Dismiss notification">
                <i data-lucide="x" aria-hidden="true"></i>
            </button>
        </div>
    </div>
@endif
