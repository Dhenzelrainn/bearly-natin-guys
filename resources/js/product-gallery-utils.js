export const gallerySwipeDirection = (startX, startY, endX, endY, threshold = 48) => {
    const deltaX = Number(endX) - Number(startX);
    const deltaY = Number(endY) - Number(startY);

    if (!Number.isFinite(deltaX) || !Number.isFinite(deltaY)
        || Math.abs(deltaX) < threshold
        || Math.abs(deltaX) <= Math.abs(deltaY) * 1.2) {
        return 0;
    }

    return deltaX < 0 ? 1 : -1;
};

export const galleryIndexForDirection = (currentIndex, direction, imageCount) => {
    if (!Number.isInteger(imageCount) || imageCount < 1) return -1;

    const current = Number.isInteger(currentIndex) && currentIndex >= 0
        ? currentIndex % imageCount
        : 0;
    const step = direction < 0 ? -1 : direction > 0 ? 1 : 0;

    return (current + step + imageCount) % imageCount;
};
