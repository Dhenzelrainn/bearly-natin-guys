<?php

namespace App\Http\Controllers;

use App\Enums\AccountStatus;
use App\Models\Announcement;
use App\Models\AnnouncementMedia;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AnnouncementController extends Controller
{
    private const AUDIENCE_ROLES = ['buyer', 'seller', 'logistics', 'rider'];

    public function show(Request $request, Announcement $announcement): View
    {
        /** @var User $user */
        $user = $request->user();

        $announcement->loadMissing(['roles', 'media']);

        $publishedAt = $announcement->published_at ?? $announcement->publish_at;
        abort_unless($this->canView($user, $announcement), 404);

        $recipientRoles = $announcement->roles
            ->pluck('name')
            ->intersect(self::AUDIENCE_ROLES)
            ->values();
        $userAudienceRoles = $this->audienceRolesFor($user);

        $recipientRole = in_array($user->role, $recipientRoles->all(), true)
            ? $user->role
            : $recipientRoles->first(fn (string $role): bool => $user->hasRole($role));

        $roleLabel = match ($recipientRole) {
            'seller' => 'Seller',
            'logistics' => 'Logistics',
            'rider' => 'Rider',
            default => 'Buyer',
        };

        $dashboardUrl = match ($recipientRole) {
            'seller' => route('seller.dashboard'),
            'logistics' => route('logistics.dashboard'),
            'rider' => route('rider.dashboard.deliveries'),
            default => route('home'),
        };

        $audienceLabel = $this->audienceLabel($announcement);

        $pastAnnouncements = Announcement::query()
            ->with(['creator', 'media', 'roles'])
            ->whereKeyNot($announcement->id)
            ->where('status', 'published')
            ->where(function (Builder $query) use ($publishedAt): void {
                $query->where(function (Builder $query) use ($publishedAt): void {
                    $query->whereNotNull('published_at')
                        ->where('published_at', '<', $publishedAt);
                })->orWhere(function (Builder $query) use ($publishedAt): void {
                    $query->whereNull('published_at')
                        ->whereNotNull('publish_at')
                        ->where('publish_at', '<', $publishedAt);
                });
            })
            ->whereHas('roles', fn (Builder $query) => $query->whereIn('name', $userAudienceRoles))
            ->orderByRaw('COALESCE(published_at, publish_at) DESC')
            ->orderByDesc('id')
            ->paginate(6)
            ->withQueryString();

        return view('announcements.show', [
            'announcement' => $announcement,
            'audienceLabel' => $audienceLabel,
            'dashboardUrl' => $dashboardUrl,
            'pastAnnouncements' => $pastAnnouncements,
            'roleLabel' => $roleLabel,
            'publishedAt' => $publishedAt,
        ]);
    }

    /**
     * Polling fallback for an already-open announcement page. Only published
     * announcements visible to the signed-in audience roles are returned.
     */
    public function feed(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless($user->status === AccountStatus::Active->value, 403);

        $userAudienceRoles = $this->audienceRolesFor($user);
        abort_if($userAudienceRoles->isEmpty(), 403);

        $validated = $request->validate([
            'after' => ['nullable', 'date'],
            'exclude_ids' => ['nullable', 'array', 'max:30'],
            'exclude_ids.*' => ['required', 'integer', 'min:1'],
        ]);

        $after = filled($validated['after'] ?? null)
            ? Carbon::parse((string) $validated['after'])->utc()
            : now()->utc()->subMinute();

        $excludeIds = collect($validated['exclude_ids'] ?? [])
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $announcements = Announcement::query()
            ->with(['creator', 'roles', 'media'])
            ->where('status', 'published')
            ->where(function (Builder $query): void {
                $query->whereNotNull('published_at')
                    ->orWhere(function (Builder $query): void {
                        $query->whereNull('published_at')
                            ->whereNotNull('publish_at');
                    });
            })
            ->whereRaw('COALESCE(published_at, publish_at) >= ?', [$after->toDateTimeString()])
            ->whereRaw('COALESCE(published_at, publish_at) <= ?', [now()->toDateTimeString()])
            ->whereHas('roles', fn (Builder $query) => $query
                ->whereIn('roles.name', $userAudienceRoles->all()))
            ->when(
                $excludeIds !== [],
                fn (Builder $query) => $query->whereNotIn('id', $excludeIds),
            )
            ->orderByRaw('COALESCE(published_at, publish_at) ASC')
            ->orderBy('id')
            ->limit(20)
            ->get()
            ->filter(fn (Announcement $announcement): bool => $this->canView($user, $announcement))
            ->map(fn (Announcement $announcement): array => $this->feedPayload($announcement))
            ->values()
            ->all();

        return response()->json([
            'data' => ['announcements' => $announcements],
            'message' => 'Announcements loaded.',
        ]);
    }

    public function media(
        Request $request,
        Announcement $announcement,
        AnnouncementMedia $media,
    ): BinaryFileResponse {
        /** @var User $user */
        $user = $request->user();

        abort_unless((int) $media->announcement_id === (int) $announcement->id, 404);
        abort_unless(
            $user->status === AccountStatus::Active->value
            && ($user->hasRole('admin') || $this->canView($user, $announcement)),
            404,
        );

        $mimeType = strtolower((string) $media->mime_type);
        abort_unless(in_array($mimeType, [
            'image/jpeg',
            'image/png',
            'image/webp',
            'video/mp4',
            'video/webm',
        ], true), 404);
        abort_unless($media->disk === 'local', 404);
        abort_unless(Storage::disk('local')->exists($media->file_path), 404);

        $safeName = preg_replace(
            '/[^A-Za-z0-9._ -]/',
            '_',
            basename(str_replace('\\', '/', (string) $media->original_name)),
        ) ?: 'announcement-media';

        return response()->file(
            Storage::disk('local')->path($media->file_path),
            [
                'Content-Type' => $mimeType,
                'Content-Disposition' => 'inline; filename="'.$safeName.'"',
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    /** @return Collection<int, string> */
    private function audienceRolesFor(User $user): Collection
    {
        return collect(self::AUDIENCE_ROLES)
            ->filter(fn (string $role): bool => $user->hasRole($role))
            ->values();
    }

    private function audienceLabel(Announcement $announcement): string
    {
        $recipientRoles = $announcement->roles
            ->pluck('name')
            ->intersect(self::AUDIENCE_ROLES);

        if ($recipientRoles->count() === count(self::AUDIENCE_ROLES)) {
            return 'All Users';
        }

        return $announcement->roles
            ->whereIn('name', $recipientRoles->all())
            ->pluck('display_name')
            ->implode(', ');
    }

    /** @return array<string, mixed> */
    private function feedPayload(Announcement $announcement): array
    {
        $publishedAt = $announcement->published_at ?? $announcement->publish_at;

        return [
            'id' => (int) $announcement->id,
            'announcement_no' => (string) $announcement->announcement_no,
            'title' => (string) $announcement->title,
            'body' => (string) $announcement->body,
            'audience_label' => $this->audienceLabel($announcement),
            'creator_name' => $announcement->creator?->name ?? 'Bearly Admin',
            'published_at' => $publishedAt?->toIso8601String(),
            'published_label' => $publishedAt?->timezone('Asia/Manila')->format('F j, Y · g:i A'),
            'url' => route('announcements.show', $announcement),
            'media' => $announcement->media
                ->map(fn (AnnouncementMedia $media): array => [
                    'id' => (int) $media->id,
                    'media_type' => (string) $media->media_type,
                    'mime_type' => (string) $media->mime_type,
                    'original_name' => (string) $media->original_name,
                    'url' => route('announcements.media', [$announcement, $media]),
                ])
                ->values()
                ->all(),
        ];
    }

    private function canView(User $user, Announcement $announcement): bool
    {
        $publishedAt = $announcement->published_at ?? $announcement->publish_at;

        if (
            $user->status !== AccountStatus::Active->value
            || $announcement->status !== 'published'
            || ! $publishedAt
            || $publishedAt->isAfter(now())
        ) {
            return false;
        }

        return $announcement->roles
            ->pluck('name')
            ->intersect(self::AUDIENCE_ROLES)
            ->contains(fn (string $role): bool => $user->hasRole($role));
    }
}
