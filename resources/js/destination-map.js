import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

document.querySelectorAll('[data-destination-map]').forEach((element) => {
    const latitude = Number(element.dataset.latitude);
    const longitude = Number(element.dataset.longitude);
    if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) return;

    const map = L.map(element, { scrollWheelZoom: false, dragging: false }).setView([latitude, longitude], 16);
    L.tileLayer(element.dataset.mapTileUrl, {
        attribution: element.dataset.mapAttribution || '&copy; OpenStreetMap contributors',
        maxZoom: 19,
    }).addTo(map);
    L.marker([latitude, longitude]).addTo(map);
    window.setTimeout(() => map.invalidateSize(), 0);
});
