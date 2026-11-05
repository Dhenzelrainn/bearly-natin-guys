import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import './leaflet-default-icon.js';
import {
    bestGpsReading,
    gpsReadingFromPosition,
    isStableGpsFix,
    GPS_ACCURACY_TARGET_METERS,
    GPS_STABLE_READINGS_REQUIRED,
    GPS_WATCH_DURATION_MS,
} from './geolocation-fix';

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

export function createAddressMapPicker({
    element,
    latitudeInput,
    longitudeInput,
    clearButton,
    locationButton,
    manualPlacementButton,
    reverseGeocodeUrl,
    forwardGeocodeUrl,
    stableLocation = false,
    saveLabel = 'Save Address',
    fields = {},
    status,
    latitude = null,
    longitude = null,
}) {
    if (!element || !latitudeInput || !longitudeInput) return null;

    const defaultCenter = [12.8797, 121.7740];
    const initialCoordinates = coordinatePair(latitude, longitude);
    const map = L.map(element, { scrollWheelZoom: false }).setView(
        initialCoordinates || defaultCenter,
        initialCoordinates ? 16 : 6,
    );

    const setStatus = (message, state = 'info') => {
        if (!status) return;
        status.textContent = message;
        if (state === 'info') delete status.dataset.state;
        else status.dataset.state = state;
    };

    let activeLocationRequest = null;
    const stopLocationDetection = (request = activeLocationRequest) => {
        if (!request || activeLocationRequest !== request) return;

        activeLocationRequest = null;
        if (request.watchId !== null) navigator.geolocation?.clearWatch(request.watchId);
        if (request.timeoutId !== null) window.clearTimeout(request.timeoutId);
        if (locationButton) locationButton.disabled = false;
    };
    let manualPlacementActive = false;
    const setManualPlacementActive = (active) => {
        manualPlacementActive = active;
        manualPlacementButton?.setAttribute('aria-pressed', String(active));
        element.classList.toggle('is-placing-pin', active);
    };

    let lookupSequence = 0;
    const reverseGeocode = async (lat, lng, locationAccuracy = '') => {
        if (!reverseGeocodeUrl) return;
        const sequence = ++lookupSequence;
        setStatus('Looking up address details from OpenStreetMap…');
        try {
            const response = await fetch(`${reverseGeocodeUrl}?latitude=${encodeURIComponent(lat)}&longitude=${encodeURIComponent(lng)}`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            const payload = await response.json().catch(() => ({}));
            if (!response.ok || sequence !== lookupSequence) throw new Error(payload.message || 'Address lookup failed.');
            const data = payload.data || {};
            const accuracyCopy = locationAccuracy ? ` ${locationAccuracy}` : '';
            setStatus(`Pin selected near ${data.display_name || 'the selected location'}.${accuracyCopy} Verify the pin and click ${saveLabel} to save it.`);
        } catch (error) {
            if (sequence === lookupSequence) {
                const recoveryCopy = locationAccuracy
                    ? ` The selected pin remains available. ${locationAccuracy} Verify it and click ${saveLabel} to save.`
                    : ' You can keep the pin and enter the address manually.';
                setStatus(`${error.message}${recoveryCopy}`, 'error');
            }
        }
    };

    const tileUrl = element.dataset.mapTileUrl?.trim();
    if (tileUrl) {
        const tileLayer = L.tileLayer(tileUrl, {
            attribution: element.dataset.mapAttribution || '&copy; OpenStreetMap contributors',
            maxZoom: 19,
        });
        tileLayer.on('tileerror', () => {
            setStatus('Map tiles could not load. Check MAPTILER_API_KEY, the allowed origin, and your internet connection.');
        });
        tileLayer.addTo(map);
    } else {
        setStatus('Map tiles are not configured. Set MAPS_TILE_URL in your .env file.');
    }

    let marker = null;

    if (!initialCoordinates) clearButton?.setAttribute('hidden', 'hidden');

    const setPin = (latlng, announce = true) => {
        const lat = Number(latlng.lat).toFixed(7);
        const lng = Number(latlng.lng).toFixed(7);
        latitudeInput.value = lat;
        longitudeInput.value = lng;
        if (!marker) {
            marker = L.marker([Number(lat), Number(lng)], { draggable: true }).addTo(map);
            marker.on('dragend', () => {
                stopLocationDetection();
                setManualPlacementActive(false);
                setPin(marker.getLatLng());
            });
        } else {
            marker.setLatLng([Number(lat), Number(lng)]);
        }
        clearButton?.removeAttribute('hidden');
        if (announce) setStatus(`Pin selected: ${lat}, ${lng}. Click ${saveLabel} to persist it.`);
        if (announce) reverseGeocode(Number(lat), Number(lng));
    };

    let forwardTimer = null;
    let forwardSequence = 0;
    const scheduleForwardGeocode = () => {
        if (!forwardGeocodeUrl || !fields.province?.value.trim() || !fields.city?.value.trim() || !fields.barangay?.value.trim()) return;
        window.clearTimeout(forwardTimer);
        forwardTimer = window.setTimeout(async () => {
            const sequence = ++forwardSequence;
            const params = new URLSearchParams({
                province: fields.province.value.trim(),
                city: fields.city.value.trim(),
                barangay: fields.barangay.value.trim(),
            });
            if (fields.street?.value.trim()) params.set('street', fields.street.value.trim());
            if (fields.postal?.value.trim()) params.set('postal', fields.postal.value.trim());
            setStatus('Searching the address on the map…');
            try {
                const response = await fetch(`${forwardGeocodeUrl}?${params.toString()}`, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                const payload = await response.json().catch(() => ({}));
                if (!response.ok || sequence !== forwardSequence) throw new Error(payload.message || 'Address search failed.');
                const data = payload.data;
                map.setView([Number(data.latitude), Number(data.longitude)], data.approximate ? 14 : 17);
                setPin({ lat: Number(data.latitude), lng: Number(data.longitude) }, false);
                setStatus(`${data.approximate ? 'Approximate locality match' : 'Address match'} found. Drag the pin to confirm the exact location, then save.`);
            } catch (error) {
                if (sequence === forwardSequence) setStatus(`${error.message} You can place the pin manually.`, 'error');
            }
        }, 1200);
    };

    Object.values(fields).forEach((field) => field?.addEventListener('input', scheduleForwardGeocode));

    const clearPin = () => {
        stopLocationDetection();
        setManualPlacementActive(false);
        marker?.remove();
        marker = null;
        latitudeInput.value = '';
        longitudeInput.value = '';
        clearButton?.setAttribute('hidden', 'hidden');
        setStatus('No coordinates selected. Your validated address will still be saved.');
    };

    const applyDetectedLocation = (reading, quality, zoom = 16) => {
        setManualPlacementActive(false);
        map.setView([reading.latitude, reading.longitude], zoom);
        setPin({ lat: reading.latitude, lng: reading.longitude }, false);
        if (reverseGeocodeUrl) {
            void reverseGeocode(reading.latitude, reading.longitude, quality);
        } else {
            setStatus(`${quality} Review the pin and click ${saveLabel} to persist it. You can still click the map or drag the pin manually.`);
        }
    };

    if (initialCoordinates) {
        setPin({ lat: initialCoordinates[0], lng: initialCoordinates[1] }, false);
    }

    map.on('click', event => {
        const wasManualPlacementActive = manualPlacementActive;
        stopLocationDetection();
        setPin(event.latlng);
        setManualPlacementActive(false);
        if (wasManualPlacementActive) {
            setStatus(`Manual pin placed. Drag it to fine-tune the location, then click ${saveLabel}.`);
        }
    });
    clearButton?.addEventListener('click', clearPin);
    manualPlacementButton?.addEventListener('click', () => {
        stopLocationDetection();
        const activate = !manualPlacementActive;
        setManualPlacementActive(activate);
        setStatus(activate
            ? `Manual pin placement is active. Tap or click the map to place or move the pin, then drag it to fine-tune. Click ${saveLabel} when ready.`
            : 'Manual pin placement canceled. You can still tap the map or drag the pin.');
    });
    locationButton?.addEventListener('click', () => {
        const geolocationMethod = stableLocation ? 'watchPosition' : 'getCurrentPosition';
        if (!navigator.geolocation || typeof navigator.geolocation[geolocationMethod] !== 'function') {
            setStatus('Browser location is unavailable. Place the pin manually.', 'error');
            return;
        }

        if (activeLocationRequest) return;

        locationButton.disabled = true;
        setManualPlacementActive(false);
        setStatus(stableLocation
            ? 'Requesting your location permission. Keep this page open while the device gathers GPS readings; you can still place the pin manually.'
            : 'Requesting your location permission…');

        if (!stableLocation) {
            try {
                navigator.geolocation.getCurrentPosition(
                    position => {
                        const reading = gpsReadingFromPosition(position);
                        locationButton.disabled = false;
                        if (!reading) {
                            setStatus('The browser returned an invalid location. Place the pin manually.', 'error');
                            return;
                        }

                        const quality = reading.accuracy === null
                            ? 'Device location selected. GPS accuracy is unavailable.'
                            : `Device location selected. Reported GPS accuracy: ±${Math.round(reading.accuracy)} m.`;
                        applyDetectedLocation(reading, quality);
                    },
                    () => {
                        locationButton.disabled = false;
                        setStatus('Location permission was denied or unavailable. Place the pin manually.', 'error');
                    },
                    { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 },
                );
            } catch {
                locationButton.disabled = false;
                setStatus('The browser could not start location detection. Place the pin manually.', 'error');
            }
            return;
        }

        const request = {
            watchId: null,
            timeoutId: null,
            bestReading: null,
            accurateReadings: [],
        };
        activeLocationRequest = request;
        const finishLocationDetection = (reason = 'The 45-second GPS check ended.') => {
            if (activeLocationRequest !== request) return;

            const stableFix = isStableGpsFix(request.accurateReadings)
                ? bestGpsReading(request.accurateReadings.slice(-GPS_STABLE_READINGS_REQUIRED))
                : null;
            const selected = stableFix || request.bestReading;
            stopLocationDetection(request);

            if (!selected) {
                setStatus(`${reason} No usable GPS reading was received. You can still click or drag the pin manually.`, 'error');
                return;
            }

            const reportedAccuracy = selected.accuracy === null
                ? 'GPS accuracy is unavailable.'
                : `Reported GPS accuracy: ±${Math.round(selected.accuracy)} m.`;
            const fixQuality = stableFix
                ? `Stable fix confirmed by ${GPS_STABLE_READINGS_REQUIRED} readings within ${GPS_STABLE_RADIUS_METERS} m. ${reportedAccuracy}`
                : `Approximate location: ${reason} No stable fix with reported accuracy of ±${GPS_ACCURACY_TARGET_METERS} m or better was confirmed. Best available reading: ${reportedAccuracy}`;

            applyDetectedLocation(selected, fixQuality, 18);
        };

        request.timeoutId = window.setTimeout(
            () => finishLocationDetection('The 45-second GPS check ended.'),
            GPS_WATCH_DURATION_MS,
        );

        try {
            request.watchId = navigator.geolocation.watchPosition(
                position => {
                    if (activeLocationRequest !== request) return;

                    const reading = gpsReadingFromPosition(position);
                    if (!reading) {
                        request.accurateReadings = [];
                        return;
                    }

                    request.bestReading = bestGpsReading([request.bestReading, reading].filter(Boolean));

                    if (reading.accuracy !== null && reading.accuracy <= GPS_ACCURACY_TARGET_METERS) {
                        request.accurateReadings.push(reading);
                        request.accurateReadings = request.accurateReadings.slice(-GPS_STABLE_READINGS_REQUIRED);
                    } else {
                        request.accurateReadings = [];
                    }

                    const readingAccuracy = reading.accuracy === null
                        ? 'GPS accuracy is unavailable.'
                        : `Current reported GPS accuracy: ±${Math.round(reading.accuracy)} m.`;
                    if (isStableGpsFix(request.accurateReadings)) {
                        finishLocationDetection('A stable GPS fix was confirmed.');
                    } else {
                        setStatus(`${readingAccuracy} Waiting for ${GPS_STABLE_READINGS_REQUIRED} stable readings at ±${GPS_ACCURACY_TARGET_METERS} m or better (within ${GPS_STABLE_RADIUS_METERS} m of one another). This can take up to 45 seconds; manual pin placement remains available.`);
                    }
                },
                error => {
                    if (activeLocationRequest !== request) return;
                    const reason = error?.code === 1
                        ? 'Location permission was denied.'
                        : error?.code === 3
                            ? 'The browser timed out while waiting for a GPS reading.'
                            : 'The device could not provide another GPS reading.';
                    finishLocationDetection(reason);
                },
                { enableHighAccuracy: true, timeout: GPS_WATCH_DURATION_MS, maximumAge: 0 },
            );
        } catch {
            finishLocationDetection('The browser could not start GPS location detection.');
        }
    });

    return {
        map,
        reset: (lat = null, lng = null) => {
            clearPin();
            const coordinates = coordinatePair(lat, lng);
            if (coordinates) {
                map.setView(coordinates, 16);
                setPin({ lat: coordinates[0], lng: coordinates[1] }, false);
            } else {
                map.setView(defaultCenter, 6);
            }
            window.setTimeout(() => map.invalidateSize(), 0);
        },
        coordinates: () => ({
            latitude: latitudeInput.value || null,
            longitude: longitudeInput.value || null,
        }),
    };
}
