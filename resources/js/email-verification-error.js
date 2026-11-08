function waitLabel(seconds) {
    if (seconds < 60) {
        return `${seconds} ${seconds === 1 ? 'second' : 'seconds'}`;
    }

    const minutes = Math.ceil(seconds / 60);

    return `${minutes} ${minutes === 1 ? 'minute' : 'minutes'}`;
}

export function emailVerificationFailure(response, data, fallbackMessage) {
    const retryAfterValue = Number(
        response.headers.get('Retry-After') || data.retry_after || 60,
    );
    const retryAfter = Number.isFinite(retryAfterValue) && retryAfterValue > 0
        ? Math.ceil(retryAfterValue)
        : 60;
    const serverMessage = typeof data.message === 'string'
        ? data.message.trim()
        : '';
    const validationMessage = Object.values(data.errors || {})
        .flat()
        .find((message) => typeof message === 'string');

    if (response.status === 419) {
        return {
            message: 'Your session expired. Refresh the page and try again.',
            retryAfter: 0,
        };
    }

    if (response.status === 429) {
        const isGenericRateLimit = !serverMessage || /^too many attempts\.?$/i.test(serverMessage);

        return {
            message: isGenericRateLimit
                ? `Too many verification requests. Please try again in ${waitLabel(retryAfter)}.`
                : serverMessage,
            retryAfter,
        };
    }

    return {
        message: validationMessage || serverMessage || fallbackMessage,
        retryAfter: 0,
    };
}
