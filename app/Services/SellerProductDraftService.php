<?php

namespace App\Services;

use App\Models\SellerProductDraft;
use App\Models\SellerProductDraftImage;
use App\Models\Store;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class SellerProductDraftService
{
    public const TTL_DAYS = 7;

    public const ORPHAN_FILE_GRACE_MINUTES = 60;

    public const TEMPORARY_IMAGE_STORAGE_PREFIX = 'seller-product-drafts';

    private const MAX_TOTAL_IMAGE_BYTES = 50 * 1024 * 1024;

    private const IMAGE_PURPOSES = ['primary', 'gallery', 'variant_color'];

    private const PENDING_IMAGE_CLEANUP_KEY = 'pending_image_cleanup';

    private const CLEANUP_ONLY_KEY = 'cleanup_only';

    private int $cleanupFailureCount = 0;

    public function cleanupFailureCount(): int
    {
        return $this->cleanupFailureCount;
    }

    public function forStore(Store $store): SellerProductDraft
    {
        $this->cleanupFailureCount = 0;
        $draft = SellerProductDraft::query()->firstOrNew(['store_id' => $store->id]);

        if ($draft->exists && $draft->expires_at?->isPast()) {
            if (! $this->forget($draft)) {
                throw new RuntimeException(
                    'Temporary product image cleanup failed. The expired draft was retained for retry.'
                );
            }

            $draft = new SellerProductDraft(['store_id' => $store->id]);
        } elseif ($draft->exists) {
            $this->processPendingImageCleanup($draft);
            $draft = $draft->fresh('images');

            if ($this->cleanupOnlyReady($draft)) {
                if ($this->deleteDraftRecordIfReady($draft)) {
                    $draft = new SellerProductDraft(['store_id' => $store->id]);
                }
            }
        }

        if (! $draft->exists) {
            $draft->forceFill([
                'store_id' => $store->id,
                'payload' => [],
                'expires_at' => now()->addDays(self::TTL_DAYS),
            ])->save();
        } else {
            $draft->forceFill(['expires_at' => now()->addDays(self::TTL_DAYS)])->save();
        }

        return $draft->load('images');
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  array<int, mixed>  $variants
     * @param  array<int, mixed>  $variantImageColors
     * @param  array<int|string, mixed>  $draftImageColors
     */
    public function save(
        Store $store,
        array $fields,
        array $variants,
        array $variantImageColors,
        array $draftImageColors = [],
    ): SellerProductDraft {
        $draft = $this->forStore($store);
        $safeFields = $this->safeFields($fields);
        $safeVariants = $this->safeVariants($variants);
        $safeColors = $this->safeColors($variantImageColors);
        $safeImageColors = collect($draftImageColors)
            ->take(100)
            ->mapWithKeys(function ($color, $imageId): array {
                $id = filter_var($imageId, FILTER_VALIDATE_INT);

                if (! $id) {
                    return [];
                }

                return [(int) $id => is_scalar($color) && trim((string) $color) !== ''
                    ? mb_substr(trim((string) $color), 0, 120)
                    : null];
            })
            ->all();

        return DB::transaction(function () use ($store, $safeFields, $safeVariants, $safeColors, $safeImageColors): SellerProductDraft {
            $draft = SellerProductDraft::query()
                ->where('store_id', $store->id)
                ->lockForUpdate()
                ->first();

            $draft ??= new SellerProductDraft(['store_id' => $store->id]);
            $pendingImageIds = $this->pendingImageIds($draft);
            $payload = [
                'fields' => $safeFields,
                'variants' => $safeVariants,
                'variant_image_colors' => $safeColors,
            ];
            if ($pendingImageIds !== []) {
                $payload[self::PENDING_IMAGE_CLEANUP_KEY] = $pendingImageIds;
            }

            $draft->forceFill([
                'payload' => $payload,
                'expires_at' => now()->addDays(self::TTL_DAYS),
            ])->save();

            if ($safeImageColors !== []) {
                $draft->images()
                    ->where('purpose', 'variant_color')
                    ->whereNotIn('id', $pendingImageIds)
                    ->whereIn('id', array_keys($safeImageColors))
                    ->get()
                    ->each(function (SellerProductDraftImage $image) use ($safeImageColors): void {
                        $image->forceFill(['color' => $safeImageColors[$image->id]])->save();
                    });
            }

            return $draft->load('images');
        });
    }

    /**
     * Replace the current draft images for the selected field.
     *
     * @param  array<int, UploadedFile>  $files
     * @return array<int, SellerProductDraftImage>
     */
    public function replaceImages(Store $store, string $purpose, array $files, array $colors = []): array
    {
        $this->cleanupFailureCount = 0;

        if (! in_array($purpose, self::IMAGE_PURPOSES, true)) {
            throw ValidationException::withMessages(['purpose' => 'Choose a supported product image field.']);
        }

        $limits = ['primary' => 1, 'gallery' => 4, 'variant_color' => 100];
        if ($files === [] || count($files) > $limits[$purpose]) {
            throw ValidationException::withMessages([
                'images' => 'Choose between 1 and '.$limits[$purpose].' image(s) for this field.',
            ]);
        }

        foreach ($files as $file) {
            if (
                ! $file instanceof UploadedFile
                || ! $file->isValid()
                || $file->getSize() > 10 * 1024 * 1024
                || ! in_array($file->getMimeType(), ['image/jpeg', 'image/png', 'image/webp'], true)
            ) {
                throw ValidationException::withMessages(['images' => 'One of the selected product images could not be uploaded.']);
            }
        }

        $safeColors = $this->safeColors($colors);

        $draft = $this->forStore($store);
        $newBytes = array_sum(array_map(static fn (UploadedFile $file): int => (int) $file->getSize(), $files));
        $otherBytes = (int) $draft->images
            ->where('purpose', '<>', $purpose)
            ->sum('size_bytes');

        if ($newBytes + $otherBytes > self::MAX_TOTAL_IMAGE_BYTES) {
            throw ValidationException::withMessages([
                'images' => 'All temporary product images must total 50 MB or less. Remove or compress images and try again.',
            ]);
        }

        $disk = Storage::disk('local');
        $newPaths = [];

        try {
            foreach ($files as $index => $file) {
                $extension = strtolower($file->getClientOriginalExtension());
                $extension = in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true) ? $extension : 'img';
                $path = $file->storeAs(
                    self::TEMPORARY_IMAGE_STORAGE_PREFIX.'/'.$store->id.'/'.$draft->id,
                    Str::uuid().'.'.$extension,
                    'local',
                );

                if (! $path) {
                    throw new RuntimeException('Temporary product image could not be stored.');
                }

                $newPaths[] = [
                    'path' => $path,
                    'original_name' => mb_substr(basename($file->getClientOriginalName()), 0, 255),
                    'mime_type' => (string) $file->getMimeType(),
                    'size_bytes' => (int) $file->getSize(),
                    'position' => $index,
                    'color' => $purpose === 'variant_color' ? ($safeColors[$index] ?? null) : null,
                ];
            }

            DB::transaction(function () use ($draft, $purpose, $newPaths): void {
                $lockedDraft = SellerProductDraft::query()
                    ->whereKey($draft->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $activeOldImages = $lockedDraft->images()
                    ->where('purpose', $purpose)
                    ->whereNotIn('id', $this->pendingImageIds($lockedDraft))
                    ->get();

                $this->appendPendingImageIds(
                    $lockedDraft,
                    $activeOldImages->pluck('id')->all(),
                );

                foreach ($newPaths as $image) {
                    $lockedDraft->images()->create([
                        'purpose' => $purpose,
                        'disk' => 'local',
                        ...$image,
                    ]);
                }

                $lockedDraft->forceFill(['expires_at' => now()->addDays(self::TTL_DAYS)])->save();
            });
        } catch (Throwable $exception) {
            $retryDraft = $this->queueStoredPathsForCleanup($draft, $purpose, $newPaths);

            if ($retryDraft) {
                try {
                    $this->processPendingImageCleanup($retryDraft);
                } catch (Throwable $cleanupException) {
                    report($cleanupException);
                    Log::error('Seller product draft temporary file reconciliation failed.', [
                        'draft_id' => $draft->id,
                        'reason' => $cleanupException->getMessage(),
                    ]);
                }
            } else {
                $this->deleteStoredPaths($newPaths, $disk);
            }

            throw $exception;
        }

        $draft = $draft->fresh('images');
        $this->processPendingImageCleanup($draft);

        return $draft->fresh('images')->images
            ->where('purpose', $purpose)
            ->reject(fn (SellerProductDraftImage $image): bool => $this->isPendingImage($draft, $image->id))
            ->sortBy('position')
            ->values()
            ->all();
    }

    public function removeImage(Store $store, int $imageId): SellerProductDraft
    {
        $this->cleanupFailureCount = 0;
        $image = $this->imageForStore($store, $imageId);

        $purpose = $image->purpose;
        $draft = $image->draft;
        $draft = $this->markImagesForCleanup($draft, [$image->id]);
        $this->processPendingImageCleanup($draft);

        $draft = $draft->fresh('images');
        if ($this->isPendingImage($draft, $image->id)) {
            throw $this->cleanupFailureException();
        }

        $remaining = $draft->images()
            ->where('purpose', $purpose)
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        foreach ($remaining as $position => $remainingImage) {
            if ($remainingImage->position !== $position) {
                $remainingImage->forceFill(['position' => $position])->save();
            }
        }

        if ($purpose === 'variant_color') {
            $payload = $draft->payload ?? [];
            $payload['variant_image_colors'] = $remaining->pluck('color')->values()->all();
            $draft->forceFill(['payload' => $payload])->save();
        }

        $draft->forceFill(['expires_at' => now()->addDays(self::TTL_DAYS)])->save();

        return $draft->fresh('images');
    }

    public function removeImages(Store $store, string $purpose): ?SellerProductDraft
    {
        $this->cleanupFailureCount = 0;

        if (! in_array($purpose, self::IMAGE_PURPOSES, true)) {
            throw ValidationException::withMessages(['purpose' => 'Choose a supported product image field.']);
        }

        $draft = SellerProductDraft::query()
            ->where('store_id', $store->id)
            ->where('expires_at', '>', now())
            ->first();

        if (! $draft) {
            return null;
        }

        $pendingImageIds = $this->pendingImageIds($draft);
        $images = $draft->images()
            ->where('purpose', $purpose)
            ->whereNotIn('id', $pendingImageIds)
            ->get();
        $targetImageIds = $images->pluck('id')->all();
        $draft = $this->markImagesForCleanup($draft, $images->pluck('id')->all());
        $this->processPendingImageCleanup($draft);
        $draft = $draft->fresh('images');

        if (array_intersect($this->pendingImageIds($draft), $targetImageIds) !== []) {
            throw $this->cleanupFailureException();
        }

        if ($purpose === 'variant_color') {
            $payload = $draft->payload ?? [];
            $payload['variant_image_colors'] = [];
            $draft->forceFill(['payload' => $payload])->save();
        }

        $draft->forceFill(['expires_at' => now()->addDays(self::TTL_DAYS)])->save();

        return $draft->fresh('images');
    }

    public function imageForStore(Store $store, int $imageId): SellerProductDraftImage
    {
        $image = SellerProductDraftImage::query()
            ->with('draft')
            ->whereHas('draft', fn ($query) => $query
                ->where('store_id', $store->id)
                ->where('expires_at', '>', now()))
            ->findOrFail($imageId);

        abort_if($this->isPendingImage($image->draft, $image->id), 404);

        return $image;
    }

    /** @return array<int, UploadedFile> */
    public function uploadedFiles(SellerProductDraft $draft, string $purpose): array
    {
        $pendingImageIds = $this->pendingImageIds($draft);

        return $draft->images()
            ->where('purpose', $purpose)
            ->whereNotIn('id', $pendingImageIds)
            ->orderBy('position')
            ->get()
            ->map(function (SellerProductDraftImage $image): UploadedFile {
                $path = Storage::disk($image->disk)->path($image->path);

                if (! is_file($path)) {
                    throw ValidationException::withMessages([
                        'image' => 'A temporarily saved product image is no longer available. Upload it again before saving.',
                    ]);
                }

                return new UploadedFile($path, $image->original_name, $image->mime_type, null, true);
            })
            ->all();
    }

    public function forget(SellerProductDraft $draft): bool
    {
        if (! $draft->exists) {
            return true;
        }

        $draft = $draft->fresh('images');
        $draft = $this->markImagesForCleanup($draft, $draft->images->pluck('id')->all(), true);
        $this->processPendingImageCleanup($draft);
        $draft = $draft->fresh('images');

        if ($draft->images()->exists()) {
            return false;
        }

        return $this->deleteDraftRecordIfReady($draft);
    }

    public function pruneExpired(): int
    {
        $this->cleanupFailureCount = 0;
        $count = 0;

        SellerProductDraft::query()
            ->with('images')
            ->chunkById(100, function ($drafts) use (&$count): void {
                foreach ($drafts as $draft) {
                    $hadPendingCleanup = $this->pendingImageIds($draft) !== [];

                    if ($hadPendingCleanup) {
                        $this->processPendingImageCleanup($draft);
                        $draft = $draft->fresh('images');

                        if ($this->cleanupOnlyReady($draft)) {
                            if ($this->deleteDraftRecordIfReady($draft)) {
                                $count++;
                            }

                            continue;
                        }

                        // Do not retry a failed pending image twice in one run.
                        // If it was repaired and this row is also expired, the
                        // remaining non-pending images may now be pruned once.
                        if ($this->pendingImageIds($draft) !== []) {
                            continue;
                        }
                    }

                    if ($draft->expires_at?->isPast() && $this->forget($draft)) {
                        $count++;
                    }
                }
            });

        return $count;
    }

    public function reconcileOrphanedUploads(): int
    {
        try {
            $referencedPaths = SellerProductDraftImage::query()
                ->where('disk', 'local')
                ->pluck('path')
                ->map(fn ($path): string => $this->normalizeStoragePath((string) $path))
                ->flip()
                ->all();
            $disk = Storage::disk('local');
            $paths = $disk->allFiles(self::TEMPORARY_IMAGE_STORAGE_PREFIX);
        } catch (Throwable $exception) {
            $this->recordOrphanCleanupFailure('The private draft-image listing or reference lookup failed.', null, $exception);

            return 0;
        }

        $cutoff = now()->subMinutes(self::ORPHAN_FILE_GRACE_MINUTES)->timestamp;
        $deletedCount = 0;

        foreach ($paths as $path) {
            $path = $this->normalizeStoragePath((string) $path);

            if (! $this->isControlledTemporaryImagePath($path) || isset($referencedPaths[$path])) {
                continue;
            }

            try {
                $lastModified = $disk->lastModified($path);
                if ($lastModified >= $cutoff) {
                    continue;
                }

                if (! $disk->delete($path)) {
                    $this->recordOrphanCleanupFailure('The disk reported a failed orphan-file deletion.', $path);

                    continue;
                }

                if ($disk->exists($path)) {
                    $this->recordOrphanCleanupFailure('The disk still reports the orphan file after deletion.', $path);

                    continue;
                }

                $deletedCount++;
            } catch (Throwable $exception) {
                $this->recordOrphanCleanupFailure('The orphan-file age check or deletion failed.', $path, $exception);
            }
        }

        return $deletedCount;
    }

    /** @return array<int, array<string, mixed>> */
    public function imagesForView(SellerProductDraft $draft): array
    {
        $pendingImageIds = $this->pendingImageIds($draft);

        return $draft->images
            ->reject(fn (SellerProductDraftImage $image): bool => in_array($image->id, $pendingImageIds, true))
            ->sortBy(fn (SellerProductDraftImage $image): array => [$image->purpose, $image->position, $image->id])
            ->map(fn (SellerProductDraftImage $image): array => [
                'id' => match ($image->purpose) {
                    'primary' => 'primary',
                    'gallery' => 'draft-gallery-'.$image->id,
                    default => 'draft-variant-'.$image->id,
                },
                'image_id' => null,
                'draft_image_id' => (int) $image->id,
                'kind' => match ($image->purpose) {
                    'primary' => 'primary',
                    'gallery' => 'gallery',
                    default => 'variant',
                },
                'label' => match ($image->purpose) {
                    'primary' => 'Primary image',
                    'gallery' => 'Gallery image '.($image->position + 1),
                    default => ($image->color ? $image->color.' variant image' : 'Variant image'),
                },
                'purpose' => $image->purpose,
                'original_name' => $image->original_name,
                'mime_type' => $image->mime_type,
                'size_bytes' => $image->size_bytes,
                'position' => $image->position,
                'color' => $image->color,
                'color_shared' => $image->purpose === 'variant_color' && filled($image->color),
                'legacy_color' => false,
                'url' => route('seller.products.draft.images.show', $image->id),
            ])
            ->values()
            ->all();
    }

    /** @return array<int, int> */
    private function pendingImageIds(?SellerProductDraft $draft): array
    {
        if (! $draft) {
            return [];
        }

        $payload = is_array($draft->payload) ? $draft->payload : [];

        return collect($payload[self::PENDING_IMAGE_CLEANUP_KEY] ?? [])
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    private function normalizeStoragePath(string $path): string
    {
        return ltrim(str_replace('\\', '/', $path), '/');
    }

    private function isControlledTemporaryImagePath(string $path): bool
    {
        $prefix = self::TEMPORARY_IMAGE_STORAGE_PREFIX.'/';
        if (! str_starts_with($path, $prefix)) {
            return false;
        }

        $segments = explode('/', $path);

        return ! in_array('', $segments, true)
            && ! in_array('.', $segments, true)
            && ! in_array('..', $segments, true);
    }

    private function isPendingImage(SellerProductDraft $draft, int $imageId): bool
    {
        return in_array($imageId, $this->pendingImageIds($draft), true);
    }

    /** @param array<int, int> $imageIds */
    private function markImagesForCleanup(
        SellerProductDraft $draft,
        array $imageIds,
        bool $cleanupOnly = false,
    ): SellerProductDraft {
        return DB::transaction(function () use ($draft, $imageIds, $cleanupOnly): SellerProductDraft {
            $lockedDraft = SellerProductDraft::query()
                ->whereKey($draft->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->appendPendingImageIds($lockedDraft, $imageIds, $cleanupOnly);

            return $lockedDraft->fresh('images');
        });
    }

    /** @param array<int, int> $imageIds */
    private function appendPendingImageIds(
        SellerProductDraft $draft,
        array $imageIds,
        bool $cleanupOnly = false,
    ): void {
        $pendingImageIds = array_values(array_unique([
            ...$this->pendingImageIds($draft),
            ...array_map('intval', $imageIds),
        ]));
        $payload = $cleanupOnly
            ? [self::CLEANUP_ONLY_KEY => true]
            : (is_array($draft->payload) ? $draft->payload : []);

        if (! $cleanupOnly) {
            unset($payload[self::CLEANUP_ONLY_KEY]);
        }

        if ($pendingImageIds === []) {
            unset($payload[self::PENDING_IMAGE_CLEANUP_KEY]);
        } else {
            $payload[self::PENDING_IMAGE_CLEANUP_KEY] = $pendingImageIds;
        }

        $draft->forceFill(['payload' => $payload])->save();
    }

    private function processPendingImageCleanup(SellerProductDraft $draft): bool
    {
        $draft = $draft->fresh('images');
        $pendingImageIds = $this->pendingImageIds($draft);

        if ($pendingImageIds === []) {
            return true;
        }

        $failedImageIds = [];
        foreach ($draft->images->whereIn('id', $pendingImageIds) as $image) {
            if ($this->deleteImageFile($image)) {
                $image->delete();
            } else {
                $failedImageIds[] = $image->id;
            }
        }

        $payload = is_array($draft->payload) ? $draft->payload : [];
        if ($failedImageIds === []) {
            unset($payload[self::PENDING_IMAGE_CLEANUP_KEY]);
        } else {
            $payload[self::PENDING_IMAGE_CLEANUP_KEY] = array_values(array_unique($failedImageIds));
        }
        $draft->forceFill(['payload' => $payload])->save();

        return $failedImageIds === [];
    }

    private function queueStoredPathsForCleanup(
        SellerProductDraft $draft,
        string $purpose,
        array $paths,
    ): ?SellerProductDraft {
        if ($paths === [] || ! $draft->exists) {
            return null;
        }

        try {
            return DB::transaction(function () use ($draft, $purpose, $paths): ?SellerProductDraft {
                $lockedDraft = SellerProductDraft::query()
                    ->whereKey($draft->id)
                    ->lockForUpdate()
                    ->first();

                if (! $lockedDraft) {
                    return null;
                }

                $imageIds = [];
                foreach ($paths as $stored) {
                    $image = $lockedDraft->images()->create([
                        'purpose' => $purpose,
                        'disk' => 'local',
                        ...$stored,
                    ]);
                    $imageIds[] = $image->id;
                }

                $this->appendPendingImageIds($lockedDraft, $imageIds);

                return $lockedDraft->fresh('images');
            });
        } catch (Throwable $exception) {
            report($exception);
            Log::critical('Seller product draft could not persist temporary-file reconciliation metadata.', [
                'draft_id' => $draft->id,
                'purpose' => $purpose,
                'paths' => array_column($paths, 'path'),
                'reason' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function cleanupOnlyReady(SellerProductDraft $draft): bool
    {
        return $this->isCleanupOnly($draft)
            && $this->pendingImageIds($draft) === []
            && ! $draft->images()->exists();
    }

    private function isCleanupOnly(?SellerProductDraft $draft): bool
    {
        if (! $draft || ! is_array($draft->payload)) {
            return false;
        }

        return ($draft->payload[self::CLEANUP_ONLY_KEY] ?? false) === true;
    }

    private function deleteDraftRecordIfReady(SellerProductDraft $draft): bool
    {
        return (bool) DB::transaction(function () use ($draft): bool {
            $lockedDraft = SellerProductDraft::query()
                ->whereKey($draft->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedDraft) {
                return true;
            }

            if (! $this->cleanupOnlyReady($lockedDraft)) {
                return false;
            }

            $lockedDraft->delete();

            return true;
        });
    }

    private function cleanupFailureException(): ValidationException
    {
        return ValidationException::withMessages([
            'images' => 'Temporary product image cleanup failed. The saved image metadata was retained and will be retried.',
        ]);
    }

    private function deleteImageFile(SellerProductDraftImage $image): bool
    {
        try {
            $disk = Storage::disk($image->disk);
            $deleted = $disk->delete($image->path);

            if (! $deleted || $disk->exists($image->path)) {
                $this->recordCleanupFailure(
                    $image,
                    $deleted ? 'The disk still reports the file after deletion.' : 'The disk reported a failed deletion.',
                );

                return false;
            }

            return true;
        } catch (Throwable $exception) {
            $this->recordCleanupFailure($image, $exception->getMessage(), $exception);

            return false;
        }
    }

    /** @param array<int, array{path: string}> $paths */
    private function deleteStoredPaths(array $paths, $disk): void
    {
        foreach ($paths as $stored) {
            try {
                $deleted = $disk->delete($stored['path']);
                if (! $deleted || $disk->exists($stored['path'])) {
                    Log::error('Seller product draft temporary file cleanup failed.', [
                        'disk' => 'local',
                        'path' => $stored['path'],
                        'reason' => $deleted
                            ? 'The disk still reports the file after deletion.'
                            : 'The disk reported a failed deletion.',
                    ]);
                }
            } catch (Throwable $exception) {
                report($exception);
                Log::error('Seller product draft temporary file cleanup failed.', [
                    'disk' => 'local',
                    'path' => $stored['path'],
                    'reason' => $exception->getMessage(),
                ]);
            }
        }
    }

    private function recordCleanupFailure(
        SellerProductDraftImage $image,
        string $reason,
        ?Throwable $exception = null,
    ): void {
        $this->cleanupFailureCount++;
        if ($exception) {
            report($exception);
        }

        Log::error('Seller product draft image cleanup failed; metadata retained for retry.', [
            'draft_id' => $image->seller_product_draft_id,
            'image_id' => $image->id,
            'disk' => $image->disk,
            'path' => $image->path,
            'reason' => $reason,
        ]);
    }

    private function recordOrphanCleanupFailure(
        string $reason,
        ?string $path = null,
        ?Throwable $exception = null,
    ): void {
        $this->cleanupFailureCount++;
        if ($exception) {
            report($exception);
        }

        Log::error('Seller product draft orphan-file reconciliation failed; the path will be retried.', [
            'disk' => 'local',
            'prefix' => self::TEMPORARY_IMAGE_STORAGE_PREFIX,
            'path' => $path,
            'reason' => $reason,
        ]);
    }

    /** @return array<string, mixed> */
    private function safeFields(array $fields): array
    {
        $limits = [
            'name' => 120,
            'marketplace_subcategory_id' => 20,
            'subcategory_id' => 20,
            'new_subcategory_name' => 120,
            'description' => 1000,
            'sku' => 60,
            'price' => 30,
            'voucher_eligible' => 1,
            'product_mode' => 20,
            'stock' => 20,
            'low_stock_threshold' => 20,
            'package_weight_kg' => 30,
            'package_length_cm' => 30,
            'package_width_cm' => 30,
            'package_height_cm' => 30,
            'option_one_name' => 40,
            'option_one_values' => 250,
            'option_two_name' => 40,
            'option_two_values' => 250,
            'package_shared' => 1,
        ];
        $safe = [];

        foreach ($limits as $field => $max) {
            if (! array_key_exists($field, $fields) || ! is_scalar($fields[$field])) {
                continue;
            }

            $value = (string) $fields[$field];
            if (mb_strlen($value) <= $max) {
                $safe[$field] = $value;
            }
        }

        $shared = $fields['shared_package'] ?? null;
        if (is_array($shared)) {
            $safe['shared_package'] = [];
            foreach (['package_weight_kg', 'package_length_cm', 'package_width_cm', 'package_height_cm'] as $field) {
                if (isset($shared[$field]) && is_scalar($shared[$field])) {
                    $safe['shared_package'][$field] = mb_substr((string) $shared[$field], 0, 30);
                }
            }
        }

        if (array_key_exists('package_shared', $fields) && is_scalar($fields['package_shared'])) {
            $safe['package_shared'] = (string) $fields['package_shared'] === '1' ? '1' : '0';
        }

        return $safe;
    }

    /** @return array<int, array<string, mixed>> */
    private function safeVariants(array $variants): array
    {
        $allowed = [
            'label' => 120,
            'sku' => 80,
            'price' => 30,
            'stock' => 20,
            'low_stock_threshold' => 20,
            'package_weight_kg' => 30,
            'package_length_cm' => 30,
            'package_width_cm' => 30,
            'package_height_cm' => 30,
        ];

        return collect($variants)
            ->take(100)
            ->map(function ($variant) use ($allowed): array {
                if (! is_array($variant)) {
                    return [];
                }

                $safe = [];
                foreach ($allowed as $key => $max) {
                    if (isset($variant[$key]) && is_scalar($variant[$key])) {
                        $safe[$key] = mb_substr((string) $variant[$key], 0, $max);
                    }
                }

                foreach (['package_override', 'package_selected', 'packageDetailsOpen'] as $key) {
                    if (array_key_exists($key, $variant)) {
                        $safe[$key] = in_array($variant[$key], [true, 1, '1', 'true', 'on', 'yes'], true);
                    }
                }

                return $safe;
            })
            ->all();
    }

    /**
     * @param  array<int, mixed>  $colors
     * @return array<int, string|null>
     */
    private function safeColors(array $colors): array
    {
        return collect($colors)
            ->take(100)
            ->map(fn ($color): ?string => is_scalar($color) && trim((string) $color) !== ''
                ? mb_substr(trim((string) $color), 0, 120)
                : null)
            ->values()
            ->all();
    }
}
