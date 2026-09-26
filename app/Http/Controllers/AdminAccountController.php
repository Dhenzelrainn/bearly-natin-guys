<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class AdminAccountController extends Controller
{
    public function show(Request $request): View
    {
        $admin = $request->user();

        return view('admin.system.account', [
            'admin' => $this->adminSummary($admin),
            'topNotifications' => [],
            'profile' => $admin,
            'accountLogs' => AuditLog::query()
                ->where('actor_user_id', $admin->id)
                ->where('module', 'admin_account')
                ->latest('created_at')
                ->limit(10)
                ->get(),
        ]);
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $admin = $request->user();
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:80', 'regex:/^[\pL\s\'\-]+$/u'],
            'middle_initial' => ['nullable', 'string', 'max:5', 'regex:/^[\pL\.]+$/u'],
            'last_name' => ['required', 'string', 'max:80', 'regex:/^[\pL\s\'\-]+$/u'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($admin->id)],
            'contact_number' => ['nullable', 'regex:/^(09|\+639)\d{9}$/'],
            'sex' => ['nullable', Rule::in(['female', 'male', 'prefer_not_to_say'])],
            'birthday' => ['nullable', 'date', 'before:today'],
            'province' => ['nullable', 'string', 'max:120'],
            'city' => ['nullable', 'string', 'max:120'],
            'barangay' => ['nullable', 'string', 'max:120'],
            'street_address' => ['nullable', 'string', 'max:255'],
            'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        DB::transaction(function () use ($admin, $request, $validated): void {
            $admin = User::query()->lockForUpdate()->findOrFail($admin->id);
            unset($validated['avatar']);
            $oldAvatar = $admin->avatar_path;
            if ($request->hasFile('avatar')) {
                $validated['avatar_path'] = $request->file('avatar')->store('avatars/admins', 'public');
            }
            $oldValues = collect($admin->only([...array_keys($validated), 'name']))->all();
            $validated['name'] = trim(implode(' ', array_filter([
                $validated['first_name'],
                $validated['middle_initial'] ?? null,
                $validated['last_name'],
            ])));

            $admin->fill($validated)->save();
            if (isset($validated['avatar_path']) && $oldAvatar && $oldAvatar !== $validated['avatar_path']) {
                Storage::disk('public')->delete($oldAvatar);
            }
            $changed = collect($admin->getChanges())->except(['updated_at'])->all();

            if ($changed !== []) {
                AuditLog::query()->create([
                    'actor_user_id' => $admin->id,
                    'action' => 'admin.profile_updated',
                    'module' => 'admin_account',
                    'auditable_type' => User::class,
                    'auditable_id' => $admin->id,
                    'description' => 'Administrator profile information was updated.',
                    'old_values' => collect($oldValues)->only(array_keys($changed))->all(),
                    'new_values' => $changed,
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'severity' => 'info',
                    'created_at' => now(),
                ]);
            }
        }, 3);

        return back()->with('success', 'Administrator profile was updated.');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password:web'],
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()],
        ]);

        DB::transaction(function () use ($request, $validated): void {
            $admin = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            $admin->forceFill(['password' => Hash::make($validated['password'])])->save();

            AuditLog::query()->create([
                'actor_user_id' => $admin->id,
                'action' => 'admin.password_updated',
                'module' => 'admin_account',
                'auditable_type' => User::class,
                'auditable_id' => $admin->id,
                'description' => 'Administrator password was updated.',
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'severity' => 'warning',
                'created_at' => now(),
            ]);
        }, 3);

        $request->session()->regenerate();

        return back()->with('success', 'Administrator password was updated.');
    }

    private function adminSummary(User $admin): array
    {
        return [
            'name' => $admin->name,
            'role' => 'Admin',
            'email' => $admin->email,
            'initials' => collect(explode(' ', $admin->name))->map(fn (string $part) => strtoupper(substr($part, 0, 1)))->take(2)->implode(''),
        ];
    }
}
