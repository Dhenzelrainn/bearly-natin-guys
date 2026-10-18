<?php

namespace App\Http\Controllers;

use App\Enums\AccountStatus;
use App\Models\Announcement;
use App\Models\AnnouncementMedia;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
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
        $userAudienceRoles = collect(self::AUDIENCE_ROLES)
            ->filter(fn (string $role): bool => $user->hasRole($role))
            ->values();

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

        $audienceLabel = $recipientRoles->count() === count(self::AUDIENCE_ROLES)
            ? 'All Users'
            : $announcement->roles
                ->whereIn('name', $recipientRoles->all())
                ->pluck('display_name')
                ->implode(', ');

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
