<?php

namespace App\Services;

use App\Models\Announcement;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class AnnouncementMediaService
{
    /**
     * Persist uploaded media metadata and remember file paths for rollback cleanup.
     *
     * @param  list<UploadedFile>  $uploads
     * @param  list<array{disk: string, path: string}>  $storedFiles
     */
    public function storeUploads(Announcement $announcement, array $uploads, array &$storedFiles): void
    {
        $position = (int) $announcement->media()->max('position') + 1;

        foreach ($uploads as $upload) {
            if (! $upload instanceof UploadedFile) {
                continue;
            }

            $disk = 'local';
            $path = $upload->store("announcement-media/{$announcement->id}", $disk);

            if (! is_string($path) || $path === '') {
                throw new \RuntimeException('Announcement media could not be stored.');
            }

            $storedFiles[] = ['disk' => $disk, 'path' => $path];
            $mimeType = strtolower((string) ($upload->getMimeType() ?: 'application/octet-stream'));

            $announcement->media()->create([
                'media_type' => str_starts_with($mimeType, 'image/') ? 'image' : 'video',
                'disk' => $disk,
                'file_path' => $path,
                'original_name' => mb_substr($upload->getClientOriginalName(), 0, 255),
                'mime_type' => $mimeType,
                'size_bytes' => max(0, (int) $upload->getSize()),
                'position' => $position++,
            ]);
        }
    }

    /**
     * Delete only media rows owned by this announcement, keeping their file
     * paths for deletion after the surrounding database transaction commits.
     *
     * @param  list<int>  $mediaIds
     * @param  list<array{disk: string, path: string}>  $removedFiles
     */
    public function removeSelected(Announcement $announcement, array $mediaIds, array &$removedFiles): void
    {
        $ids = collect($mediaIds)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return;
        }

        $media = $announcement->media()
            ->whereIn('id', $ids)
            ->lockForUpdate()
            ->get();

        if ($media->count() !== $ids->count()) {
            throw ValidationException::withMessages([
                'remove_media_ids' => 'One or more selected files do not belong to this announcement.',
            ]);
        }

        foreach ($media as $item) {
            $removedFiles[] = [
                'disk' => (string) $item->disk,
                'path' => (string) $item->file_path,
            ];
            $item->delete();
        }
    }

    /**
     * @param  list<array{disk: string, path: string}>  $files
     */
    public function deleteFiles(array $files): void
    {
        foreach ($files as $file) {
            if (($file['disk'] ?? null) !== 'local' || ! filled($file['path'] ?? null)) {
                continue;
            }

            try {
                if (Storage::disk('local')->exists($file['path'])) {
                    Storage::disk('local')->delete($file['path']);
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }
}
