<?php

namespace App\Services;

use App\Models\Store;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class StoreAppearanceService
{
    /**
     * Persist Store appearance data and synchronize its public image files.
     *
     * File writes happen before the database transaction so a failed database
     * update can clean up newly-created files. Superseded files are removed
     * only after the transaction and only when no Store still references them.
     */
    public function save(
        Store $store,
        array $data,
        ?UploadedFile $profilePhoto = null,
        ?UploadedFile $coverPhoto = null,
        array $promoFiles = [],
    ): void {
        $existingBanners = $store->promotionalBanners()->get()->keyBy('id');
        $submittedBannerIds = $this->submittedBannerIds($data);
        $ownedBannerIds = $existingBanners->keys()->map(fn ($id): int => (int) $id);
        $unknownBannerIds = collect(array_values($submittedBannerIds))
            ->reject(fn (int $id): bool => $ownedBannerIds->contains($id));

        if ($unknownBannerIds->isNotEmpty()) {
            throw new AuthorizationException('You cannot modify another Store\'s promotional banner.');
        }

        $syncPromotions = $this->shouldSyncPromotions($data);
        $newPaths = [];
        $pathsToDelete = [];
        $retainedPaths = [];

        try {
            $newProfilePath = $profilePhoto
                ? $this->storeUploadedFile($profilePhoto, $newPaths)
                : null;
            $newCoverPath = $coverPhoto
                ? $this->storeUploadedFile($coverPhoto, $newPaths)
                : null;
            $newPromoPaths = [];

            foreach ($promoFiles as $position => $file) {
                if (! $file instanceof UploadedFile) {
                    continue;
                }

                $newPromoPaths[(int) $position] = $this->storeUploadedFile($file, $newPaths);
            }

            DB::transaction(function () use (
                $store,
                $data,
                $profilePhoto,
                $coverPhoto,
                $newProfilePath,
                $newCoverPath,
                $newPromoPaths,
                $existingBanners,
                $submittedBannerIds,
                $syncPromotions,
                &$pathsToDelete,
                &$retainedPaths,
            ): void {
                $oldLogoPath = $store->logo_path ?: null;
                $oldCoverPath = $store->banner_path ?: null;
                $removeProfilePhoto = $this->boolean($data['remove_profile_photo'] ?? false);
                $removeCoverPhoto = $this->boolean($data['remove_cover_photo'] ?? false);
                $updates = [
                    'description' => $data['description'] ?? '',
                    'announcement' => $data['announcement'] ?? '',
                    'operating_hours' => $data['operating_hours'] ?? '',
                    'shipping_policy' => $data['shipping_policy'] ?? '',
                    'return_policy' => $data['return_policy'] ?? '',
                    'warranty_policy' => $data['warranty_policy'] ?? '',
                ];

                if ($profilePhoto) {
                    $updates['logo_path'] = $newProfilePath;
                    $this->queueSupersededPath($pathsToDelete, $oldLogoPath, $newProfilePath);
                } elseif ($removeProfilePhoto) {
                    $updates['logo_path'] = null;
                    $this->queueSupersededPath($pathsToDelete, $oldLogoPath, null);
                } else {
                    $retainedPaths[] = $oldLogoPath;
                }

                if ($coverPhoto) {
                    $updates['banner_path'] = $newCoverPath;
                    $this->queueSupersededPath($pathsToDelete, $oldCoverPath, $newCoverPath);
                } elseif ($removeCoverPhoto) {
                    $updates['banner_path'] = null;
                    $this->queueSupersededPath($pathsToDelete, $oldCoverPath, null);
                } else {
                    $retainedPaths[] = $oldCoverPath;
                }

                if ($syncPromotions) {
                    $legacyPromoPath = $store->promo_banner_path ?: null;
                    $updates['promo_banner_path'] = null;
                    $updates['promo_banner_title'] = null;
                    $updates['promo_banner_link'] = null;
                    $this->queueSupersededPath($pathsToDelete, $legacyPromoPath, null);
                } else {
                    $retainedPaths = array_merge(
                        $retainedPaths,
                        $existingBanners->pluck('image_path')->all(),
                        [$store->promo_banner_path],
                    );
                }

                $store->update($updates);

                $finalLogoPath = array_key_exists('logo_path', $updates)
                    ? $updates['logo_path']
                    : $oldLogoPath;
                $finalCoverPath = array_key_exists('banner_path', $updates)
                    ? $updates['banner_path']
                    : $oldCoverPath;
                $retainedPaths[] = $finalLogoPath;
                $retainedPaths[] = $finalCoverPath;

                if ($syncPromotions) {
                    $this->syncPromotionalBanners(
                        $store,
                        $existingBanners,
                        $submittedBannerIds,
                        $newPromoPaths,
                        $data['promo_banner_titles'] ?? [],
                        $data['promo_banner_links'] ?? [],
                        $pathsToDelete,
                        $retainedPaths,
                    );
                }
            });
        } catch (Throwable $exception) {
            $this->deleteFiles($newPaths);

            throw $exception;
        }

        $this->deleteUnreferencedFiles($pathsToDelete, $retainedPaths);
    }

    /**
     * @return array<int, int>
     */
    private function submittedBannerIds(array $data): array
    {
        return collect($data['promo_banner_ids'] ?? [])
            ->filter(fn ($id): bool => is_numeric($id) && (int) $id > 0)
            ->mapWithKeys(fn ($id, $position): array => [(int) $position => (int) $id])
            ->all();
    }

    private function shouldSyncPromotions(array $data): bool
    {
        return $this->boolean($data['promo_banner_form'] ?? false)
            || array_key_exists('promo_banner_ids', $data)
            || array_key_exists('promo_banners', $data)
            || array_key_exists('promo_banner_titles', $data)
            || array_key_exists('promo_banner_links', $data);
    }

    /**
     * @param  array<int, string>  $newPaths
     */
    private function storeUploadedFile(UploadedFile $file, array &$newPaths): string
    {
        $path = $file->store('seller-store', 'public');

        if (! is_string($path) || $path === '') {
            throw new RuntimeException('Unable to store Store appearance file.');
        }

        $newPaths[] = $path;

        return $path;
    }

    /**
     * @param  array<int, string|null>  $pathsToDelete
     */
    private function queueSupersededPath(array &$pathsToDelete, ?string $oldPath, ?string $newPath): void
    {
        if ($oldPath !== null && $oldPath !== $newPath) {
            $pathsToDelete[] = $oldPath;
        }
    }

    /**
     * @param  array<int, int>  $submittedBannerIds
     * @param  array<int, string>  $newPromoPaths
     * @param  array<int, string>  $pathsToDelete
     * @param  array<int, string|null>  $retainedPaths
     */
    private function syncPromotionalBanners(
        Store $store,
        $existingBanners,
        array $submittedBannerIds,
        array $newPromoPaths,
        array $titles,
        array $links,
        array &$pathsToDelete,
        array &$retainedPaths,
    ): void {
        $submittedIds = array_values($submittedBannerIds);

        foreach ($existingBanners as $banner) {
            if (in_array((int) $banner->id, $submittedIds, true)) {
                continue;
            }

            $this->queueSupersededPath($pathsToDelete, $banner->image_path, null);
            $banner->delete();
        }

        $positions = collect(array_merge(
            array_keys($submittedBannerIds),
            array_keys($newPromoPaths),
            array_keys($titles),
            array_keys($links),
        ))
            ->map(fn ($position): int => (int) $position)
            ->unique()
            ->sort()
            ->values();

        foreach ($positions as $positionIndex => $inputPosition) {
            $bannerId = $submittedBannerIds[$inputPosition] ?? null;
            $newImagePath = $newPromoPaths[$inputPosition] ?? null;

            if (! $bannerId && ! $newImagePath) {
                continue;
            }

            $title = trim((string) ($titles[$inputPosition] ?? '')) ?: null;
            $link = trim((string) ($links[$inputPosition] ?? '')) ?: null;

            if ($bannerId) {
                $banner = $existingBanners->get($bannerId);

                if ($banner === null) {
                    throw new AuthorizationException('You cannot modify another Store\'s promotional banner.');
                }

                if ($newImagePath !== null) {
                    $this->queueSupersededPath($pathsToDelete, $banner->image_path, $newImagePath);
                    $banner->image_path = $newImagePath;
                }

                $banner->fill([
                    'title' => $title,
                    'link' => $link,
                    'position' => $positionIndex,
                    'is_active' => true,
                ])->save();
                $retainedPaths[] = $banner->image_path;

                continue;
            }

            $banner = $store->promotionalBanners()->create([
                'image_path' => $newImagePath,
                'title' => $title,
                'link' => $link,
                'position' => $positionIndex,
                'is_active' => true,
            ]);
            $retainedPaths[] = $banner->image_path;
        }
    }

    private function boolean(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @param  array<int, string>  $paths
     */
    private function deleteFiles(array $paths): void
    {
        foreach (array_unique(array_filter($paths)) as $path) {
            Storage::disk('public')->delete($path);
        }
    }

    /**
     * @param  array<int, string|null>  $pathsToDelete
     * @param  array<int, string|null>  $retainedPaths
     */
    private function deleteUnreferencedFiles(array $pathsToDelete, array $retainedPaths): void
    {
        $retained = array_flip(array_filter($retainedPaths));

        foreach (array_unique(array_filter($pathsToDelete)) as $path) {
            if (isset($retained[$path])) {
                continue;
            }

            $stillReferenced = Store::withTrashed()
                ->where(function ($stores) use ($path): void {
                    $stores
                        ->where('logo_path', $path)
                        ->orWhere('banner_path', $path)
                        ->orWhere('promo_banner_path', $path);
                })
                ->orWhereHas('promotionalBanners', fn ($banners) => $banners->where('image_path', $path))
                ->exists();

            if (! $stillReferenced) {
                Storage::disk('public')->delete($path);
            }
        }
    }
}
