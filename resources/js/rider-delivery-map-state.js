export const MAX_RIDER_MAP_ACCURACY_METERS = 100;

export function validCoordinatePair(latitude, longitude) {
    const parse = (value, minimum, maximum) => {
        if (value === null || value === undefined || String(value).trim() === '') return null;

        const number = Number(value);

        return Number.isFinite(number) && number >= minimum && number <= maximum
            ? number
            : null;
    };

    const lat = parse(latitude, -90, 90);
    const lng = parse(longitude, -180, 180);

    return lat === null || lng === null ? null : [lat, lng];
}

export function normalizeRiderFix(detail) {
    const coordinates = validCoordinatePair(detail?.latitude, detail?.longitude);
    const rawAccuracy = detail?.accuracyMeters;
    const parsedAccuracy = rawAccuracy === null || rawAccuracy === undefined || String(rawAccuracy).trim() === ''
        ? null
        : Number(rawAccuracy);
    const accuracyMeters = Number.isFinite(parsedAccuracy) && parsedAccuracy >= 0
        ? parsedAccuracy
        : null;

    if (!coordinates) return null;

    return {
        coordinates,
        accuracyMeters,
        accuracyUsable: accuracyMeters !== null
            && accuracyMeters <= MAX_RIDER_MAP_ACCURACY_METERS,
    };
}

export function shouldAppendTrailPoint(previousCoordinates, nextCoordinates, minimumDistanceMeters = 15) {
    const next = Array.isArray(nextCoordinates)
        ? validCoordinatePair(nextCoordinates[0], nextCoordinates[1])
        : null;

    if (!next) return false;

    const previous = Array.isArray(previousCoordinates)
        ? validCoordinatePair(previousCoordinates[0], previousCoordinates[1])
        : null;

    if (!previous) return true;

    const latitudeDelta = toRadians(next[0] - previous[0]);
    const longitudeDelta = toRadians(next[1] - previous[1]);
    const previousLatitude = toRadians(previous[0]);
    const nextLatitude = toRadians(next[0]);
    const haversine = Math.sin(latitudeDelta / 2) ** 2
        + Math.cos(previousLatitude) * Math.cos(nextLatitude) * Math.sin(longitudeDelta / 2) ** 2;
    const distanceMeters = 6_371_008.8 * 2 * Math.atan2(
        Math.sqrt(Math.min(1, haversine)),
        Math.sqrt(Math.max(0, 1 - haversine)),
    );

    return distanceMeters >= minimumDistanceMeters;
}

function toRadians(degrees) {
    return degrees * (Math.PI / 180);
}

export function directionsUrl(destination, address) {
    const target = destination
        ? `${destination[0]},${destination[1]}`
        : String(address || '').trim();

    if (!target || target.toLowerCase() === 'address unavailable') return '';

    const query = new URLSearchParams({
        api: '1',
        destination: target,
        travelmode: 'driving',
    });

    return `https://www.google.com/maps/dir/?${query.toString()}`;
}
