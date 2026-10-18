import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

function coordinatePair(latitude, longitude) {
    const parse = (value, minimum, maximum) => {
        if (value === null || value === undefined || String(value).trim() === '') return null;
        const number = Number(value);
        return Number.isFinite(number) && number >= minimum && number <= maximum ? number : null;
    };

    const lat = parse(latitude, -90, 90);
    const lng = parse(longitude, -180, 180);

    return lat === null || lng === null ? null : [lat, lng];
}

document.querySelectorAll('[data-destination-map]').forEach((element) => {
    const coordinates = coordinatePair(element.dataset.latitude, element.dataset.longitude);
    const tileUrl = element.dataset.mapTileUrl?.trim();
    if (!coordinates || !tileUrl) return;

    const map = L.map(element, { scrollWheelZoom: false, dragging: false }).setView(coordinates, 16);
    L.tileLayer(tileUrl, {
        attribution: element.dataset.mapAttribution || '&copy; OpenStreetMap contributors',
        maxZoom: 19,
    }).addTo(map);
    L.marker(coordinates).addTo(map);
    window.setTimeout(() => map.invalidateSize(), 0);
});
