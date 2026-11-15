import QRCode from 'qrcode';

function formatPhtTime(value) {
    const date = new Date(value || '');
    if (Number.isNaN(date.getTime())) return '';

    return new Intl.DateTimeFormat('en-PH', {
        timeZone: 'Asia/Manila',
        hour: 'numeric',
        minute: '2-digit',
    }).format(date);
}

function setAttendanceTimeCell(cell, value) {
    cell.replaceChildren();

    if (!value) {
        const empty = document.createElement('span');
        empty.className = 'attendance-muted';
        empty.textContent = '—';
        cell.append(empty);
        return;
    }

    const time = document.createElement('time');
    time.dateTime = value;
    time.textContent = `${formatPhtTime(value)} PHT`;
    cell.append(time);
}

function addAttendanceState(cell, label, className, detail = '') {
    const badge = document.createElement('span');
    badge.className = `attendance-state ${className}`;
    badge.textContent = label;
    cell.append(badge);

    if (detail) {
        addAttendanceDetail(cell, detail);
    }
}

function addAttendanceDetail(cell, text) {
    const description = document.createElement('small');
    description.className = 'attendance-state-detail';
    description.textContent = text;
    cell.append(description);
}

function updateAttendanceStateCell(cell, rider) {
    cell.replaceChildren();

    if (rider.absence) {
        const markedAt = formatPhtTime(rider.absence.marked_at);
        addAttendanceState(cell, 'Absent', 'is-absent');
        addAttendanceDetail(
            cell,
            `Marked by ${rider.absence.marked_by}${markedAt ? ` at ${markedAt} PHT` : ''}`,
        );

        if (rider.late_check_in_at) {
            addAttendanceState(cell, 'Late check-in', 'is-late');
            addAttendanceDetail(cell, `Scanned at ${formatPhtTime(rider.late_check_in_at)} PHT`);
        }

        return;
    }

    if (rider.status === 'checked_in') {
        addAttendanceState(cell, 'Checked in', 'is-present');
        return;
    }

    if (rider.status === 'checked_out') {
        addAttendanceState(
            cell,
            'Checked out',
            'is-finished',
            rider.check_out_at ? `${formatPhtTime(rider.check_out_at)} PHT` : '',
        );
        return;
    }

    addAttendanceState(cell, 'Not checked in', 'is-pending');
}

function updateAttendanceActionCell(cell, rider) {
    if (rider.absence) {
        const recorded = document.createElement('span');
        recorded.className = 'attendance-muted';
        recorded.textContent = 'Recorded';
        cell.replaceChildren(recorded);
        return;
    }

    if (rider.check_in_at) {
        const empty = document.createElement('span');
        empty.className = 'attendance-muted';
        empty.textContent = '—';
        cell.replaceChildren(empty);
    }
}

function setupAttendanceRosterUpdates() {
    let updating = false;

    const syncAbsenceActions = () => {
        const roster = document.querySelector('[data-attendance-roster]');
        if (!roster) return;

        const canMarkAbsent = roster.dataset.canMarkAbsent === 'true';
        roster.querySelectorAll('[data-attendance-absence-action]').forEach((form) => {
            form.hidden = !canMarkAbsent;
        });
        roster.querySelectorAll('[data-attendance-cutoff-hint]').forEach((hint) => {
            hint.hidden = canMarkAbsent;
        });
    };

    const refreshRoster = async () => {
        const roster = document.querySelector('[data-attendance-roster]');
        if (!roster || document.hidden || updating) return;

        updating = true;

        try {
            const response = await fetch(roster.dataset.updatesUrl, {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            const payload = await response.json().catch(() => ({}));
            const attendance = payload.data;

            if (!response.ok || !Array.isArray(attendance?.riders)) {
                throw new Error(payload.message || 'Attendance updates are temporarily unavailable.');
            }

            if (attendance.date !== roster.dataset.workDate) {
                window.location.reload();
                return;
            }

            const currentRiderIds = [...roster.querySelectorAll('[data-attendance-rider-row]')]
                .map((row) => row.dataset.attendanceRiderRow)
                .sort();
            const updatedRiderIds = attendance.riders
                .map((rider) => String(rider.id))
                .sort();

            if (currentRiderIds.join(',') !== updatedRiderIds.join(',')) {
                window.location.reload();
                return;
            }

            attendance.riders.forEach((rider) => {
                const row = roster.querySelector(`[data-attendance-rider-row="${Number(rider.id)}"]`);
                if (!row) return;

                setAttendanceTimeCell(row.querySelector('[data-attendance-time-in]'), rider.check_in_at);
                updateAttendanceStateCell(row.querySelector('[data-attendance-state]'), rider);
                updateAttendanceActionCell(row.querySelector('[data-attendance-action]'), rider);
            });

            roster.querySelector('[data-attendance-checked-in-count]').textContent = String(attendance.checked_in_count);
            roster.querySelector('[data-attendance-roster-count]').textContent = String(attendance.roster_count);
            roster.querySelector('[data-attendance-absent-count]').textContent = String(attendance.absent_count);
            roster.dataset.cutoffAt = attendance.cutoff_at;
            roster.dataset.canMarkAbsent = attendance.can_mark_absent ? 'true' : 'false';

            const updateStatus = roster.querySelector('[data-attendance-roster-live-status]');
            if (updateStatus) {
                const updatedAt = formatPhtTime(attendance.updated_at);
                updateStatus.textContent = updatedAt
                    ? `Updated ${updatedAt} PHT · refreshes automatically.`
                    : 'Attendance refreshes automatically while this page is open.';
                updateStatus.classList.remove('is-error');
            }

            syncAbsenceActions();
        } catch {
            const updateStatus = document.querySelector('[data-attendance-roster-live-status]');
            if (updateStatus) {
                updateStatus.textContent = 'Live attendance refresh is temporarily unavailable.';
                updateStatus.classList.add('is-error');
            }
        } finally {
            updating = false;
        }
    };

    if (document.querySelector('[data-attendance-roster]')) {
        syncAbsenceActions();
        void refreshRoster();
        window.setInterval(refreshRoster, 5_000);
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) void refreshRoster();
        });
    }
}

function setupAttendanceQrStation() {
    const station = document.querySelector('[data-logistics-attendance-qr]');
    if (!station) return;

    const canvas = station.querySelector('[data-attendance-qr-canvas]');
    const refreshButton = station.querySelector('[data-attendance-qr-refresh]');
    const countdown = station.querySelector('[data-attendance-qr-countdown]');
    const expiry = station.querySelector('[data-attendance-qr-expiry]');
    const message = station.querySelector('[data-attendance-qr-message]');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    let expiresAt = 0;
    let issuing = false;
    let stopped = false;

    const setMessage = (text, isError = false) => {
        message.textContent = text;
        message.classList.toggle('is-error', isError);
    };

    const issue = async () => {
        if (issuing || stopped) return;
        issuing = true;
        refreshButton.disabled = true;
        countdown.textContent = 'Generating a fresh QR…';

        try {
            const response = await fetch(station.dataset.challengeUrl, {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                },
                body: '{}',
            });
            const result = await response.json().catch(() => ({}));

            if (!response.ok || typeof result.data?.challenge !== 'string') {
                throw new Error(result.message || 'The attendance QR could not be refreshed. Try again.');
            }

            await QRCode.toCanvas(canvas, result.data.challenge, {
                width: 280,
                margin: 2,
                errorCorrectionLevel: 'M',
                color: { dark: '#2B1D16', light: '#FFFFFF' },
            });
            expiresAt = Date.parse(result.data.expires_at);
            setMessage('The current code is ready for Rider check-in or check-out.');
            expiry.textContent = `Challenge ${result.data.generation} · code is not saved in the browser after refresh.`;
        } catch (error) {
            expiresAt = 0;
            canvas.getContext('2d')?.clearRect(0, 0, canvas.width, canvas.height);
            countdown.textContent = 'QR unavailable';
            setMessage(error?.message || 'The attendance QR could not be refreshed. Try again.', true);
        } finally {
            issuing = false;
            refreshButton.disabled = false;
        }
    };

    refreshButton.addEventListener('click', issue);
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden && expiresAt - Date.now() < 12000) issue();
    });

    window.setInterval(() => {
        if (!expiresAt) return;
        const remaining = Math.max(0, Math.ceil((expiresAt - Date.now()) / 1000));
        countdown.textContent = remaining > 0 ? `Refreshes in ${remaining} seconds` : 'Refreshing expired QR…';

        if (remaining === 0) issue();
    }, 1000);

    issue();
}

document.addEventListener('DOMContentLoaded', () => {
    setupAttendanceRosterUpdates();
    setupAttendanceQrStation();
});
