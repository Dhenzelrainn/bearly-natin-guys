<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Models\AuditLog;
use App\Models\Role;
use App\Services\AnnouncementPublishingService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AdminAnnouncementController extends Controller
{
    private const AUDIENCE_ROLES = ['buyer', 'seller', 'logistics', 'rider'];

    public function index(Request $request, AnnouncementPublishingService $publishing): View
    {
        $publishing->process();

        $search = trim((string) $request->query('search'));
        $status = (string) $request->query('status');
        $audience = (string) $request->query('audience');
        $statuses = ['draft', 'scheduled', 'published', 'archived'];

        $announcements = Announcement::query()
            ->with(['creator', 'roles'])
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

        return view('admin.communication.announcements', [
            'admin' => $this->adminSummary($request),
            'topNotifications' => [],
            'announcements' => $announcements,
            'roles' => Role::query()->whereIn('name', self::AUDIENCE_ROLES)->orderBy('display_name')->get(),
            'filters' => compact('search', 'status', 'audience'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateAnnouncement($request);

        DB::transaction(function () use ($request, $validated): void {
            $announcement = Announcement::query()->create($this->attributes($validated) + [
                'announcement_no' => $this->newAnnouncementNumber(),
                'created_by' => $request->user()->id,
            ]);
            $announcement->roles()->sync($this->roleIds($validated['audience_roles']));
            $this->audit($request, $announcement, 'announcement.created', null, $announcement->fresh('roles')->toArray());
        }, 3);

        return back()->with('success', 'Announcement was created.');
    }

    public function update(Request $request, Announcement $announcement): RedirectResponse
    {
        $validated = $this->validateAnnouncement($request, true);

        DB::transaction(function () use ($announcement, $request, $validated): void {
            $announcement = Announcement::query()->with('roles')->lockForUpdate()->findOrFail($announcement->id);
            $oldValues = $announcement->toArray();
            $announcement->fill($this->attributes($validated, $announcement))->save();
            $announcement->roles()->sync($this->roleIds($validated['audience_roles']));
            $this->audit($request, $announcement, 'announcement.updated', $oldValues, $announcement->fresh('roles')->toArray());
        }, 3);

        return back()->with('success', 'Announcement was updated.');
    }

    public function destroy(Request $request, Announcement $announcement): RedirectResponse
    {
        DB::transaction(function () use ($announcement, $request): void {
            $announcement = Announcement::query()->with('roles')->lockForUpdate()->findOrFail($announcement->id);
            $oldValues = $announcement->toArray();
            $announcement->delete();
            $this->audit($request, $announcement, 'announcement.deleted', $oldValues, null);
        }, 3);

        return back()->with('success', 'Announcement was deleted.');
    }

    /** @return array<string, mixed> */
    private function validateAnnouncement(Request $request, bool $updating = false): array
    {
        if ($request->filled('audience') && ! $request->has('audience_roles')) {
            $audienceRoles = $request->string('audience')->toString() === 'all'
                ? self::AUDIENCE_ROLES
                : [$request->string('audience')->toString()];

            $request->merge(['audience_roles' => $audienceRoles]);
        }

        $statuses = $updating
            ? ['draft', 'scheduled', 'published', 'archived']
            : ['draft', 'scheduled', 'published'];

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'body' => ['required', 'string', 'max:10000'],
            'status' => ['required', Rule::in($statuses)],
            'publish_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'audience_roles' => ['required', 'array', 'min:1'],
            'audience_roles.*' => ['required', 'distinct', Rule::in(self::AUDIENCE_ROLES)],
        ]);

        if ($validated['status'] === 'scheduled') {
            if (empty($validated['publish_at']) || now()->greaterThanOrEqualTo(Carbon::parse($validated['publish_at']))) {
                throw ValidationException::withMessages([
                    'publish_at' => 'A scheduled announcement requires a future publish date.',
                ]);
            }
        }

        $effectivePublishAt = $validated['status'] === 'published'
            ? now()
            : ($validated['publish_at'] ?? null);
        if (! empty($validated['expires_at']) && $effectivePublishAt && Carbon::parse($validated['expires_at'])->lessThanOrEqualTo(Carbon::parse($effectivePublishAt))) {
            throw ValidationException::withMessages([
                'expires_at' => 'The expiration date must be after the publication date.',
            ]);
        }

        return $validated;
    }

    /** @return array<string, mixed> */
    private function attributes(array $validated, ?Announcement $announcement = null): array
    {
        $status = $validated['status'];

        $publishAt = match ($status) {
            'published' => now(),
            'scheduled' => $validated['publish_at'],
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
            'expires_at' => $validated['expires_at'] ?? null,
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
