export const REQUIRED_STABLE_BARCODE_READS = 3;
export const BARCODE_READ_GAP_MS = 1200;

export function createBarcodeReadState() {
    return {
        value: '',
        reads: 0,
        lastSeenAt: 0,
    };
}

/**
 * Require a stable, printable code before the camera flow uses it. Bearly
 * parcel and waybill identifiers use only letters, digits, and hyphens.
 * Never guess or substitute visually similar characters such as O/0.
 */
export function observeBarcodeRead(state, rawValue, timestamp) {
    const raw = typeof rawValue === 'string' ? rawValue.trim() : '';

    if (!raw) {
        if (timestamp - state.lastSeenAt > BARCODE_READ_GAP_MS) {
            state.value = '';
            state.reads = 0;
            state.lastSeenAt = 0;
        }

        return {
            value: '',
            reads: state.reads,
            stable: false,
            invalid: false,
        };
    }

    if (raw.length > 120 || !/^[a-z\d-]+$/i.test(raw) || !/[a-z\d]/i.test(raw)) {
        state.value = '';
        state.reads = 0;
        state.lastSeenAt = timestamp;

        return {
            value: '',
            reads: 0,
            stable: false,
            invalid: true,
        };
    }

    if (state.value === raw && timestamp - state.lastSeenAt <= BARCODE_READ_GAP_MS) {
        state.reads += 1;
    } else {
        state.value = raw;
        state.reads = 1;
    }

    state.lastSeenAt = timestamp;

    return {
        value: raw,
        reads: state.reads,
        stable: state.reads >= REQUIRED_STABLE_BARCODE_READS,
        invalid: false,
    };
}
