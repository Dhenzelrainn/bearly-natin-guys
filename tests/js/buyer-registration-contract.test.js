import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const registrationScript = await readFile(
    new URL('../../resources/js/bearly-auth.js', import.meta.url),
    'utf8',
);
const registrationView = await readFile(
    new URL('../../resources/views/auth/register.blade.php', import.meta.url),
    'utf8',
);

test('Buyer registration hides seller documents and makes seller ID conditional', () => {
    assert.match(registrationView, /data-seller-document @if\(\$registrationRole !== 'seller'\) hidden @endif/);
    assert.match(registrationView, /<input name="valid_id" type="file"[^>]*data-file-preview>/);
    assert.doesNotMatch(registrationView, /<input name="valid_id" type="file"[^>]*required/);
    assert.match(registrationScript, /seller:\s*\[[^\]]*'valid_id'[^\]]*'business_permit'/s);
    assert.match(registrationScript, /element\.hidden = inactive;[\s\S]*?input\.disabled = inactive;/);
    assert.match(registrationScript, /roleRequired\[role\][\s\S]*?setAttribute\('required', 'required'\)/);
});

test('Buyer registration explains immediate email-verified activation and Seller review', () => {
    assert.match(registrationView, /Verified email activates your Buyer account immediately/);
    assert.match(registrationScript, /After your email is verified, your Buyer account activates immediately/);
    assert.match(registrationScript, /A Bearly administrator will review your Seller application/);
    assert.match(registrationScript, /submitLabel\.textContent = role === 'seller'\s*\? 'Submit application'\s*: 'Create Buyer account'/);
});
