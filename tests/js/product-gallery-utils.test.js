import test from 'node:test';
import assert from 'node:assert/strict';
import {
    galleryIndexForDirection,
    gallerySwipeDirection,
} from '../../resources/js/product-gallery-utils.js';

test('a left or right horizontal swipe advances or reverses the carousel', () => {
    assert.equal(gallerySwipeDirection(180, 100, 90, 108), 1);
    assert.equal(gallerySwipeDirection(90, 108, 180, 100), -1);
});

test('short or mostly vertical gestures do not change the selected image', () => {
    assert.equal(gallerySwipeDirection(100, 100, 140, 102), 0);
    assert.equal(gallerySwipeDirection(100, 100, 130, 160), 0);
    assert.equal(gallerySwipeDirection(100, 100, 160, 150), 0);
});

test('carousel navigation wraps at both ends and starts from the first image without a current index', () => {
    assert.equal(galleryIndexForDirection(4, 1, 5), 0);
    assert.equal(galleryIndexForDirection(0, -1, 5), 4);
    assert.equal(galleryIndexForDirection(-1, 1, 5), 1);
    assert.equal(galleryIndexForDirection(2, 0, 5), 2);
});

test('carousel navigation safely handles an empty image set', () => {
    assert.equal(galleryIndexForDirection(-1, 1, 0), -1);
});
