<div
    class="announcement-media-picker"
    data-announcement-media-editor
    data-existing-media-count="{{ $existingCount }}"
>
    <label class="announcement-media-upload" for="announcement-media-{{ $pickerId }}">
        <span class="announcement-media-upload-icon"><i data-lucide="images" aria-hidden="true"></i></span>
        <span class="announcement-media-upload-copy">
            <strong>Add photos or video</strong>
            <small>Up to 5 media files total. JPG, PNG or WebP images up to 10 MB; MP4 or WebM video up to 50 MB. Your server may apply a lower combined request limit.</small>
        </span>
        <input
            id="announcement-media-{{ $pickerId }}"
            type="file"
            name="media[]"
            accept="image/jpeg,image/png,image/webp,video/mp4,video/webm"
            multiple
            data-announcement-media-input
        >
    </label>

    <p class="announcement-media-count" data-announcement-media-count aria-live="polite">
        {{ $existingCount }} of 5 media files used
    </p>

    <div class="announcement-upload-preview" data-announcement-media-preview></div>
</div>
