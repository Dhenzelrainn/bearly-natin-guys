export const GPS_ACCURACY_TARGET_METERS = 30;
export const GPS_STABLE_READINGS_REQUIRED = 3;
export const GPS_STABLE_RADIUS_METERS = 30;
export const GPS_WATCH_DURATION_MS = 45_000;

const toRadians = (degrees) => degrees * (Math.PI / 180);

export function gpsReadingFromPosition(position) {
    const latitude = Number(position?.coords?.latitude);
    const longitude = Number(position?.coords?.longitude);
    const reportedAccuracy = Number(position?.coords?.accuracy);

    if (!Number.isFinite(latitude) || latitude < -90 || latitude > 90
        || !Number.isFinite(longitude) || longitude < -180 || longitude > 180) {
        return null;
    }

    return {
        latitude,
        longitude,
        accuracy: Number.isFinite(reportedAccuracy) && reportedAccuracy >= 0 ? reportedAccuracy : null,
    };
}

function distanceBetweenReadings(first, second) {
    const latitudeDelta = toRadians(second.latitude - first.latitude);
    const longitudeDelta = toRadians(second.longitude - first.longitude);
    const firstLatitude = toRadians(first.latitude);
    const secondLatitude = toRadians(second.latitude);
    const haversine = Math.sin(latitudeDelta / 2) ** 2
        + Math.cos(firstLatitude) * Math.cos(secondLatitude) * Math.sin(longitudeDelta / 2) ** 2;

    return 6_371_000 * 2 * Math.atan2(Math.sqrt(haversine), Math.sqrt(1 - haversine));
}

export function isStableGpsFix(readings) {
    if (!Array.isArray(readings) || readings.length < GPS_STABLE_READINGS_REQUIRED) return false;

    const stableWindow = readings.slice(-GPS_STABLE_READINGS_REQUIRED);
    if (stableWindow.some(reading => !Number.isFinite(reading.accuracy)
        || reading.accuracy > GPS_ACCURACY_TARGET_METERS)) {
        return false;
    }

    return stableWindow.every((reading, index) => stableWindow
        .slice(index + 1)
        .every(other => distanceBetweenReadings(reading, other) <= GPS_STABLE_RADIUS_METERS));
}

export function bestGpsReading(readings) {
    return readings.reduce((best, reading) => {
        if (!best) return reading;
        if (reading.accuracy === null) return best;
        if (best.accuracy === null || reading.accuracy < best.accuracy) return reading;
        return best;
    }, null);
}
