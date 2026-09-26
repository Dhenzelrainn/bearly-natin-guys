<?php

namespace App\Http\Controllers\auth;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\InternationalPhone;
use App\Services\EmailVerificationService;
use App\Services\PostalCodeLookup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Laravel\Socialite\Facades\Socialite;

class BearlyAuthController extends Controller
{
    public function showLogin(): View
    {
        return view('auth.login');
    }

    public function showRegister(): View
    {
        return view('auth.register');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            return back()->withInput($request->only('email'))
                ->withErrors(['email' => 'Invalid email or password.']);
        }

        $request->session()->regenerate();
        $user = $request->user();

        if ($user->status !== AccountStatus::Active->value) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if (in_array($user->status, ['pending', 'needs_revision'], true) && $user->role === 'buyer') {
                $request->session()->put('marketplace_application', [
                    'name' => $user->name,
                    'role' => $user->role,
                    'status' => $user->status,
                ]);
            }

            return $this->redirectForStatus($user->status);
        }

        $user->forceFill(['last_login_at' => now()])->save();

        $routes = [
            UserRole::Admin->value => 'admin.dashboard',
            UserRole::Buyer->value => 'home',
            UserRole::Seller->value => 'seller.dashboard',
            UserRole::Logistics->value => 'logistics.dashboard',
            UserRole::Rider->value => 'rider.dashboard.deliveries',
            UserRole::Guest->value => 'shop.home',
        ];

        $route = $routes[$user->role] ?? null;

        return $route && app('router')->has($route)
            ? redirect()->route($route)
            : redirect('/');
    }

    public function redirectToGoogle()
    {
        return Socialite::driver('google')->redirect();
    }

    public function handleGoogleCallback(Request $request): RedirectResponse
    {
        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (Throwable) {
            return redirect()
                ->route('login')
                ->withErrors([
                    'email' => 'Google sign-in could not be completed. Please try again.',
                ]);
        }

        $googleId = trim((string) $googleUser->getId());
        $email = strtolower(trim((string) $googleUser->getEmail()));

        if ($googleId === '' || $email === '') {
            return redirect()
                ->route('login')
                ->withErrors([
                    'email' => 'Google did not return a usable account email.',
                ]);
        }

        $rawGoogleUser = is_array($googleUser->user ?? null)
            ? $googleUser->user
            : [];

        if (
            array_key_exists('email_verified', $rawGoogleUser)
            && ! filter_var($rawGoogleUser['email_verified'], FILTER_VALIDATE_BOOLEAN)
        ) {
            return redirect()
                ->route('login')
                ->withErrors([
                    'email' => 'Use a verified Google email address to continue.',
                ]);
        }

        $user = User::withTrashed()
            ->where('google_id', $googleId)
            ->first();

        if (! $user) {
            $user = User::withTrashed()
                ->whereRaw('LOWER(email) = ?', [$email])
                ->first();

            if (
                $user
                && $user->google_id
                && ! hash_equals((string) $user->google_id, $googleId)
            ) {
                return redirect()
                    ->route('login')
                    ->withErrors([
                        'email' => 'This Bearly account is already linked to another Google account.',
                    ]);
            }

            if ($user && ! $user->trashed()) {
                $user->forceFill([
                    'google_id' => $googleId,
                    'email_verified_at' => $user->email_verified_at ?: now(),
                ])->save();
            }
        }

        if ($user) {
            if ($user->trashed()) {
                return redirect()
                    ->route('login')
                    ->withErrors([
                        'email' => 'This Bearly account is no longer available.',
                    ]);
            }

            if ($user->status !== AccountStatus::Active->value) {
                if (in_array($user->status, ['pending', 'needs_revision'], true)) {
                    $request->session()->put('marketplace_application', [
                        'name' => $user->name,
                        'role' => $user->role,
                        'status' => $user->status,
                    ]);
                }

                return $this->redirectForStatus($user->status);
            }

            Auth::login($user);
            $request->session()->regenerate();

            $user->forceFill([
                'last_login_at' => now(),
            ])->save();

            $routes = [
                UserRole::Admin->value => 'admin.dashboard',
                UserRole::Buyer->value => 'home',
                UserRole::Seller->value => 'seller.dashboard',
                UserRole::Logistics->value => 'logistics.dashboard',
                UserRole::Rider->value => 'rider.dashboard.deliveries',
                UserRole::Guest->value => 'shop.home',
            ];

            $route = $routes[$user->role] ?? null;

            return $route && app('router')->has($route)
                ? redirect()->route($route)
                : redirect('/');
        }

        $fullName = trim((string) $googleUser->getName());
        $firstName = trim((string) ($rawGoogleUser['given_name'] ?? ''));
        $lastName = trim((string) ($rawGoogleUser['family_name'] ?? ''));

        if ($firstName === '' && $fullName !== '') {
            $parts = preg_split('/\s+/', $fullName) ?: [];
            $firstName = (string) array_shift($parts);
            $lastName = $lastName !== '' ? $lastName : trim(implode(' ', $parts));
        }

        $request->session()->put('google_registration', [
            'google_id' => $googleId,
            'email' => $email,
            'name' => $fullName,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'verified_at' => now()->toIso8601String(),
        ]);

        return redirect()
            ->route('register')
            ->with(
                'status',
                'Google account verified. Complete the remaining Bearly registration details.'
            );
    }

    public function register(Request $request): RedirectResponse
    {
        $googleRegistration = $request->session()->get('google_registration');
        $googleRegistration = is_array($googleRegistration)
            ? $googleRegistration
            : null;

        $googleEmail = $googleRegistration
            ? strtolower(trim((string) ($googleRegistration['email'] ?? '')))
            : '';

        $request->merge([
            'email' => strtolower(trim((string) $request->input('email'))),
        ]);

        $emailRules = [
            'required',
            'email',
            'max:255',
            'unique:users,email',
        ];

        if ($googleEmail !== '') {
            $emailRules[] = Rule::in([$googleEmail]);
        }

        $data = $request->validate([
            'role' => ['required', Rule::in([UserRole::Buyer->value, UserRole::Seller->value])],
            'first_name' => ['required', 'string', 'max:60', 'regex:/^[\pL\s\'\-]+$/u'],
            'last_name' => ['required', 'string', 'max:60', 'regex:/^[\pL\s\'\-]+$/u'],
            'middle_initial' => ['nullable', 'string', 'max:2', 'regex:/^[A-Za-z]\.?$/D'],
            'sex' => ['required', Rule::in(['female', 'male', 'prefer_not_to_say'])],
            'birthday' => ['required', 'date', 'before:today'],
            'email' => $emailRules,
            'contact_number' => ['required', 'string', 'max:17'],
            'phone_country' => ['required', 'string', 'size:2'],
            'city_code' => ['nullable', 'regex:/^[0-9]{6,10}$/D'],
            'postal_manual' => ['nullable', 'boolean'],
            'province' => ['required', 'string', 'max:100'],
            'city' => ['required', 'string', 'max:100'],
            'barangay' => ['required', 'string', 'max:100'],
            'street_name' => ['required', 'string', 'max:180'],
            'house_number' => ['required', 'string', 'max:40'],
            'postal_code' => ['required', 'digits:4'],
            'business_name' => ['required_if:role,seller', 'nullable', 'string', 'max:150', 'regex:/^[\pL\pN\s&\'\.\-]+$/u'],
            'business_category' => ['required_if:role,seller', 'nullable', 'string', 'max:100'],
            'valid_id' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'business_permit' => ['required_if:role,seller', 'nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'password' => ['required', 'confirmed', 'min:8', 'regex:/[a-z]/', 'regex:/[A-Z]/', 'regex:/[0-9]/'],
            'terms' => ['accepted'],
        ], [
            'email.in' => 'Use the same email address that was verified by Google.',
        ]);

        $data['contact_number'] = app(InternationalPhone::class)
            ->normalize($data['contact_number'], $data['phone_country']);

        app(PostalCodeLookup::class)->validate($data);

        $isGoogleRegistration = $googleRegistration
            && $googleEmail !== ''
            && hash_equals($googleEmail, $data['email'])
            && ! empty($googleRegistration['google_id']);

        $verification = app(EmailVerificationService::class);

        /*
        |--------------------------------------------------------------------------
        | Email verification
        |--------------------------------------------------------------------------
        |
        | Buyer and seller registrations must both prove ownership of the
        | submitted email address. Google OAuth registrations already proved
        | the exact Google email, so only those registrations skip Bearly OTP.
        |
        */
        $verifiedAt = $isGoogleRegistration
            ? now()->toDateTimeString()
            : $verification->verifiedAt($request, $data['email']);

        $data['middle_initial'] = empty($data['middle_initial'])
            ? null
            : strtoupper(substr($data['middle_initial'], 0, 1)).'.';

        $validIdPath = $request->file('valid_id')
            ->store('registration-documents/valid-ids', 'local');

        $businessPermitPath = null;

        try {
            $businessPermitPath = $data['role'] === UserRole::Seller->value
                ? $request->file('business_permit')?->store(
                    'registration-documents/business-permits',
                    'local'
                )
                : null;

            $user = DB::transaction(fn () => User::create([
                'name' => trim($data['first_name'].' '.$data['last_name']),
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'middle_initial' => $data['middle_initial'] ?? null,
                'sex' => $data['sex'],
                'birthday' => $data['birthday'],
                'email' => $data['email'],
                'google_id' => $isGoogleRegistration
                    ? (string) $googleRegistration['google_id']
                    : null,
                'contact_number' => $data['contact_number'],
                'phone_country' => $data['phone_country'],
                'phone_verified_at' => null,
                'email_verified_at' => $verifiedAt,
                'terms_accepted_at' => now(),
                'terms_version' => config('bearly-policies.version'),
                'privacy_version' => config('bearly-policies.version'),
                'role' => $data['role'],
                'status' => AccountStatus::Pending->value,
                'province' => $data['province'],
                'city' => $data['city'],
                'barangay' => $data['barangay'],
                'street_address' => trim(
                    $data['house_number'].' '.$data['street_name']
                ).', '.$data['postal_code'],
                'business_name' => $data['role'] === UserRole::Seller->value
                    ? ($data['business_name'] ?? null)
                    : null,
                'business_category' => $data['role'] === UserRole::Seller->value
                    ? ($data['business_category'] ?? null)
                    : null,
                'valid_id_path' => $validIdPath,
                'business_permit_path' => $businessPermitPath,
                'password' => Hash::make($data['password']),
            ]));
        } catch (Throwable $exception) {
            Storage::disk('local')->delete(
                array_filter([$validIdPath, $businessPermitPath])
            );

            throw $exception;
        }

        $verification->forget($request);

        $request->session()->forget([
            'google_registration',
            'logistics_application',
            'rider_application',
        ]);

        session([
            'marketplace_application' => [
                'name' => trim(
                    $user->first_name.' '.$user->middle_initial.' '.$user->last_name
                ),
                'role' => $user->role,
                'status' => $user->status,
                'approval_authority' => 'Bearly Administrator',
            ],
        ]);

        return redirect()->route('application.pending');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    private function redirectForStatus(string $status): RedirectResponse
    {
        if (in_array($status, ['pending', 'needs_revision'], true)) {
            return redirect()->route('application.pending');
        }

        $messages = [
            'rejected' => 'Your application was not approved. Please contact Bearly support.',
            'suspended' => 'Your account is currently suspended.',
            'deactivated' => 'Your account is deactivated.',
            'banned' => 'This account can no longer access Bearly.',
        ];

        return redirect()->route('login')->withErrors([
            'email' => $messages[$status] ?? 'Your account is not available.',
        ]);
    }
}
