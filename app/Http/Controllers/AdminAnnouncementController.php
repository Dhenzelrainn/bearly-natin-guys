<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Models\AnnouncementMedia;
use App\Models\AuditLog;
use App\Models\Role;
use App\Services\AnnouncementDeliveryService;
use App\Services\AnnouncementMediaService;
use App\Services\AnnouncementPublishingService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class AdminAnnouncementController extends Controller
{
    private const AUDIENCE_ROLES = ['buyer', 'seller', 'logistics', 'rider'];

    /**
     * The Admin composer collects wall-clock times in Philippine time while
     * the application stores everything in UTC.
     */
    private const TIMEZONE = 'Asia/Manila';

    public function index(
        Request $request,
        AnnouncementPublishingService $publishing,
        AnnouncementDeliveryService $delivery,
    ): View {
        $publishing->process();

        $search = trim((string) $request->query('search'));
        $status = (string) $request->query('status');
        $audience = (string) $request->query('audience');
        $statuses = ['draft', 'scheduled', 'published', 'archived'];

        $announcements = Announcement::query()
            ->with(['creator', 'roles', 'media'])
            ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $query) use ($search): void {
                $query->where('announcement_no', 'like', "%{$search}%")
                    ->orWhere('title', 'like', "%{$search}%")
                    ->orWhere('body', 'like', "%{$search}%");
            }))
            ->when(in_array($status, $statuses, true), fn (Builder $query) => $query->where('status', $status))
            ->when(in_array($audience, self::AUDIENCE_ROLES, true), fn (Builder $query) => $query
                ->whereHas('roles', fn (Builder $roleQuery) => $roleQuery->where('name', $audience)))
            ->latest('created_at')
            ->paginate(10)
            ->withQueryString();

        /*
         * Backfill in-app delivery for announcements published before the
         * recipient notification workflow existed. Delivery is idempotent,
         * so revisiting this page does not resend to users already notified.
         */
        $announcements
            ->getCollection()
            ->filter(fn (Announcement $announcement): bool => $announcement->status === 'published')
            ->each(fn (Announcement $announcement) => $delivery->deliverMissing($announcement));

        return view('admin.communication.announcements', [
            'admin' => $this->adminSummary($request),
            'topNotifications' => [],
            'announcements' => $announcements,
            'roles' => Role::query()->whereIn('name', self::AUDIENCE_ROLES)->orderBy('display_name')->get(),
            'filters' => compact('search', 'status', 'audience'),
        ]);
    }

    public function store(
        Request $request,
        AnnouncementDeliveryService $delivery,
        AnnouncementMediaService $mediaService,
    ): RedirectResponse {
        $validated = $this->validateAnnouncement($request);
        $storedFiles = [];

        try {
            $announcement = DB::transaction(function () use ($request, $validated, $mediaService, &$storedFiles): Announcement {
                $announcement = Announcement::query()->create($this->attributes($validated) + [
                    'announcement_no' => $this->newAnnouncementNumber(),
                    'created_by' => $request->user()->id,
                ]);
                $announcement->roles()->sync($this->roleIds($validated['audience_roles']));
                $mediaService->storeUploads($announcement, $validated['media'] ?? [], $storedFiles);
                $announcement->load(['roles', 'media']);
                $newValues = $announcement->toArray();
                $newValues['media_summary'] = $this->mediaAuditValues($announcement->media);
                unset($newValues['media']);
                $this->audit($request, $announcement, 'announcement.created', null, $newValues);

                return $announcement;
            }, 3);
        } catch (Throwable $exception) {
            $mediaService->deleteFiles($storedFiles);

            throw $exception;
        }

        if ($announcement->status === 'published') {
            $delivery->broadcastPublished($announcement);
            $delivery->deliverMissing($announcement);
        }

        return back()->with('success', 'Announcement was created.');
    }

    public function update(
        Request $request,
        Announcement $announcement,
        AnnouncementDeliveryService $delivery,
        AnnouncementMediaService $mediaService,
    ): RedirectResponse {
        $validated = $this->validateAnnouncement($request, $announcement);
        $storedFiles = [];
        $removedFiles = [];
        $becamePublished = false;

        try {
            $announcement = DB::transaction(function () use ($announcement, $request, $validated, $mediaService, &$storedFiles, &$removedFiles, &$becamePublished): Announcement {
                $announcement = Announcement::query()->with(['roles', 'media'])->lockForUpdate()->findOrFail($announcement->id);
                $wasPublished = $announcement->status === 'published';
                $wasArchived = $announcement->status === 'archived';
                $previousArchiveReason = $announcement->archive_reason;
                $this->validateMediaCapacity($announcement, $validated);
                $oldValues = $announcement->toArray();
                $oldValues['media_summary'] = $this->mediaAuditValues($announcement->media);
                unset($oldValues['media']);
                $announcement->fill($this->attributes($validated, $announcement));
                $announcement->archive_reason = $announcement->status === 'archived'
                    ? ($wasArchived ? $previousArchiveReason : 'manual')
                    : null;
                $announcement->save();
                $announcement->roles()->sync($this->roleIds($validated['audience_roles']));
                $mediaService->removeSelected($announcement, $validated['remove_media_ids'] ?? [], $removedFiles);
                $mediaService->storeUploads($announcement, $validated['media'] ?? [], $storedFiles);
                $announcement->load(['roles', 'media']);
                $newValues = $announcement->toArray();
                $newValues['media_summary'] = $this->mediaAuditValues($announcement->media);
                unset($newValues['media']);
                $this->audit($request, $announcement, 'announcement.updated', $oldValues, $newValues);
                $becamePublished = ! $wasPublished && $announcement->status === 'published';

                return $announcement;
            }, 3);
        } catch (Throwable $exception) {
            $mediaService->deleteFiles($storedFiles);

            throw $exception;
        }

        $mediaService->deleteFiles($removedFiles);

        if ($becamePublished) {
            $delivery->broadcastPublished($announcement);
        }

        if ($announcement->status === 'published') {
            $delivery->deliverMissing($announcement);
        }

        return back()->with('success', 'Announcement was updated.');
    }

    public function destroy(
        Request $request,
        Announcement $announcement,
        AnnouncementMediaService $mediaService,
    ): RedirectResponse {
        $removedFiles = [];

        DB::transaction(function () use ($announcement, $request, &$removedFiles): void {
            $announcement = Announcement::query()->with(['roles', 'media'])->lockForUpdate()->findOrFail($announcement->id);
            $oldValues = $announcement->toArray();
            $oldValues['media_summary'] = $this->mediaAuditValues($announcement->media);
            unset($oldValues['media']);
            $removedFiles = $announcement->media
                ->map(fn (AnnouncementMedia $media): array => [
                    'disk' => (string) $media->disk,
                    'path' => (string) $media->file_path,
                ])
                ->all();
            $announcement->delete();
            $this->audit($request, $announcement, 'announcement.deleted', $oldValues, null);
        }, 3);

        $mediaService->deleteFiles($removedFiles);

        return back()->with('success', 'Announcement was deleted.');
    }

    /** @return array<string, mixed> */
    private function validateAnnouncement(Request $request, ?Announcement $announcement = null): array
    {
        if ($request->filled('audience') && ! $request->has('audience_roles')) {
            $audienceRoles = $request->string('audience')->toString() === 'all'
                ? self::AUDIENCE_ROLES
                : [$request->string('audience')->toString()];

            $request->merge(['audience_roles' => $audienceRoles]);
        }

        $statuses = $announcement
            ? ['draft', 'scheduled', 'published', 'archived']
            : ['draft', 'scheduled', 'published'];

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'body' => ['required', 'string', 'max:10000'],
            'status' => ['required', Rule::in($statuses)],
            'publish_at' => ['nullable', 'date'],
            'audience_roles' => ['required', 'array', 'min:1'],
            'audience_roles.*' => ['required', 'distinct', Rule::in(self::AUDIENCE_ROLES)],
            'media' => ['nullable', 'array', 'max:5'],
            'media.*' => [
                'file',
                'mimes:jpg,jpeg,png,webp,mp4,webm',
                'mimetypes:image/jpeg,image/png,image/webp,video/mp4,video/webm',
                function (string $attribute, mixed $file, \Closure $fail): void {
                    if (! $file instanceof UploadedFile) {
                        return;
                    }

                    $mimeType = strtolower((string) $file->getMimeType());
                    $isImage = in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true);
                    $isVideo = in_array($mimeType, ['video/mp4', 'video/webm'], true);
                    $sizeBytes = (int) $file->getSize();

                    if (! $isImage && ! $isVideo) {
                        $fail('Upload a JPG, PNG, WebP, MP4, or WebM file.');
                    } elseif ($isImage && $sizeBytes > 10 * 1024 * 1024) {
                        $fail('Each image must be 10 MB or smaller.');
                    } elseif ($isVideo && $sizeBytes > 50 * 1024 * 1024) {
                        $fail('Each video must be 50 MB or smaller.');
                    }
                },
            ],
            'remove_media_ids' => ['nullable', 'array', 'max:5'],
            'remove_media_ids.*' => ['required', 'integer', 'distinct'],
        ]);

        $this->validateMediaCapacity($announcement, $validated);

        $publishAt = filled($validated['publish_at'] ?? null)
            ? Carbon::parse((string) $validated['publish_at'], self::TIMEZONE)
            : null;
        if ($validated['status'] === 'scheduled') {
            if (! $publishAt || now()->greaterThanOrEqualTo($publishAt)) {
                throw ValidationException::withMessages([
                    'publish_at' => 'A scheduled announcement requires a future publish date.',
                ]);
            }
        }

        return $validated;
    }

    /** @param array<string, mixed> $validated */
    private function validateMediaCapacity(?Announcement $announcement, array $validated): void
    {
        $removeIds = collect($validated['remove_media_ids'] ?? [])
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        if (! $announcement && $removeIds->isNotEmpty()) {
            throw ValidationException::withMessages([
                'remove_media_ids' => 'Announcement media can only be removed while editing.',
            ]);
        }

        $existingCount = $announcement?->media()->count() ?? 0;
        $ownedRemoveCount = $announcement && $removeIds->isNotEmpty()
            ? $announcement->media()->whereIn('id', $removeIds)->count()
            : 0;

        if ($ownedRemoveCount !== $removeIds->count()) {
            throw ValidationException::withMessages([
                'remove_media_ids' => 'One or more selected files do not belong to this announcement.',
            ]);
        }

        $uploadCount = count($validated['media'] ?? []);

        if ($existingCount - $ownedRemoveCount + $uploadCount > 5) {
            throw ValidationException::withMessages([
                'media' => 'An announcement can contain up to five media files. Remove an existing file before adding more.',
            ]);
        }
    }

    /** @param iterable<AnnouncementMedia> $media */
    private function mediaAuditValues(iterable $media): array
    {
        return collect($media)
            ->map(fn (AnnouncementMedia $item): array => [
                'id' => (int) $item->id,
                'media_type' => (string) $item->media_type,
                'original_name' => (string) $item->original_name,
                'mime_type' => (string) $item->mime_type,
                'size_bytes' => (int) $item->size_bytes,
                'position' => (int) $item->position,
            ])
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function attributes(array $validated, ?Announcement $announcement = null): array
    {
        $status = $validated['status'];

        $publishAt = match ($status) {
            'published' => now(),
            'scheduled' => Carbon::parse((string) $validated['publish_at'], self::TIMEZONE)->utc(),
            'archived' => $announcement?->publish_at,
            default => null,
        };
        $publishedAt = match ($status) {
            'published' => $announcement?->published_at ?? now(),
            'archived' => $announcement?->published_at,
            default => null,
        };

        return [
            'title' => $validated['title'],
            'body' => $validated['body'],
            'status' => $status,
            'publish_at' => $publishAt,
            'published_at' => $publishedAt,
            // Preserve legacy expiry metadata for delivery backfill safety.
            // New announcements no longer receive an expiry date.
            'expires_at' => $announcement?->expires_at,
        ];
    }

    /** @param list<string> $roleNames */
    private function roleIds(array $roleNames): array
    {
        return Role::query()->whereIn('name', $roleNames)->pluck('id')->all();
    }

    private function audit(
        Request $request,
        Announcement $announcement,
        string $action,
        ?array $oldValues,
        ?array $newValues,
    ): void {
        AuditLog::query()->create([
            'actor_user_id' => $request->user()->id,
            'action' => $action,
            'module' => 'announcements',
            'auditable_type' => Announcement::class,
            'auditable_id' => $announcement->id,
            'description' => "Announcement {$announcement->announcement_no}: {$action}.",
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'severity' => $action === 'announcement.deleted' ? 'warning' : 'info',
            'created_at' => now(),
        ]);
    }

    private function newAnnouncementNumber(): string
    {
        return 'ANN-'.now()->format('Ymd').'-'.Str::upper(Str::random(8));
    }

    private function adminSummary(Request $request): array
    {
        $admin = $request->user();

        return [
            'name' => $admin->name,
            'role' => 'Admin',
            'email' => $admin->email,
            'initials' => collect(explode(' ', $admin->name))->map(fn (string $part) => strtoupper(substr($part, 0, 1)))->take(2)->implode(''),
        ];
    }
}
