<div
    class="attendance-scan-control"
    data-rider-attendance-scanner
    data-scan-url="{{ route('rider.attendance.scan') }}"
>
    <button class="button button-primary button-small" type="button" data-attendance-scan-start>
        Open camera to scan QR
    </button>
    <div id="rider-attendance-camera" class="attendance-scan-camera" data-attendance-camera hidden></div>
    <p data-attendance-scan-status role="status" aria-live="polite">
        Scan the current QR from your owning Logistics provider to {{ $currentShift ? 'check out' : 'check in' }}. The server records the time.
    </p>
</div>
