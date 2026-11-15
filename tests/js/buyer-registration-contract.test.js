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

test('Buyer registration offers an optional ID while Seller ID stays required', () => {
    assert.match(registrationView, /data-seller-document @if\(\$registrationRole !== 'seller'\) hidden @endif/);
    assert.match(registrationView, /<input name="valid_id" type="file"[^>]*data-file-preview[^>]*@required\(\$registrationRole === 'seller'\)>/);
    assert.match(registrationView, /Admins can view your ID\. It does not affect email-based Buyer activation\./);
    assert.match(registrationView, /name="profile_photo" type="file" accept="image\/jpeg,image\/png,image\/webp/);
    assert.doesNotMatch(registrationScript, /seller:\s*\[[^\]]*'valid_id'/s);
    assert.match(registrationScript, /validIdInput\.required = role === 'seller'/);
    assert.match(registrationScript, /element\.hidden = inactive;[\s\S]*?input\.disabled = inactive;/);
    assert.match(registrationScript, /roleRequired\[role\][\s\S]*?setAttribute\('required', 'required'\)/);
});

test('Profile-photo browser validation matches its image formats and 10 MB server limit', () => {
    assert.match(registrationScript, /const allowedProfilePhotoTypes = \[[\s\S]*?'image\/webp'/);
    assert.match(registrationScript, /const maxProfilePhotoSize = 10 \* 1024 \* 1024/);
    assert.match(registrationScript, /const isProfilePhoto = input\.name === 'profile_photo'/);
    assert.match(registrationScript, /isProfilePhoto\s*\? allowedProfilePhotoTypes\s*: allowedDocumentTypes/);
    assert.match(registrationView, /If upload fails on your phone, choose a copy saved on the device/);
});

test('Buyer registration explains immediate email-verified activation and Seller review', () => {
    assert.match(registrationView, /Verified email activates your Buyer account immediately/);
    assert.match(registrationScript, /After your email is verified, your Buyer account activates immediately/);
    assert.match(registrationScript, /A Bearly administrator will review your Seller application/);
    assert.match(registrationScript, /submitLabel\.textContent = role === 'seller'\s*\? 'Submit application'\s*: 'Create Buyer account'/);
});
