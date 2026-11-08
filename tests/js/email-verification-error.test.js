import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { emailVerificationFailure } from '../../resources/js/email-verification-error.js';

const authEmailScript = await readFile(
    new URL('../../resources/js/registration-email.js', import.meta.url),
    'utf8',
);
const logisticsScript = await readFile(
    new URL('../../resources/js/logistics.js', import.meta.url),
    'utf8',
);
const riderScript = await readFile(
    new URL('../../resources/js/rider.js', import.meta.url),
    'utf8',
);

function response(status, retryAfter = null) {
    return {
        status,
        headers: {
            get: (name) => name.toLowerCase() === 'retry-after' ? retryAfter : null,
        },
    };
}

test('OTP error parsing presents the server reason and exact retry duration', () => {
    const failure = emailVerificationFailure(
        response(429, '37'),
        {
            message: 'Please wait before requesting another verification code. Try again in 37 seconds.',
        },
        'Email verification is unavailable.',
    );

    assert.equal(failure.retryAfter, 37);
    assert.equal(
        failure.message,
        'Please wait before requesting another verification code. Try again in 37 seconds.',
    );
});

test('Generic Laravel 429 responses get a useful retry message from Retry-After', () => {
    const failure = emailVerificationFailure(
        response(429, '91'),
        { message: 'Too Many Attempts.' },
        'Email verification is unavailable.',
    );

    assert.equal(failure.retryAfter, 91);
    assert.equal(
        failure.message,
        'Too many verification requests. Please try again in 2 minutes.',
    );
});

test('Validation errors and expired sessions keep their existing recovery guidance', () => {
    assert.deepEqual(
        emailVerificationFailure(
            response(422),
            { errors: { email: ['Enter a valid email address.'] } },
            'Email verification is unavailable.',
        ),
        { message: 'Enter a valid email address.', retryAfter: 0 },
    );
    assert.equal(
        emailVerificationFailure(response(419), {}, 'Unavailable').message,
        'Your session expired. Refresh the page and try again.',
    );
});

test('Buyer/Seller, Logistics, and Rider clients all use the shared OTP error parser', () => {
    for (const script of [authEmailScript, logisticsScript, riderScript]) {
        assert.match(script, /emailVerificationFailure\(/);
    }
});
