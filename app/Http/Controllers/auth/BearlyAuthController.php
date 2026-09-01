<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

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

        if ($user->status !== 'active') {
            return $this->redirectForStatus($user->status);
        }

        $user->forceFill(['last_login_at' => now()])->save();

        $routes = [
            'admin' => 'admin.dashboard',
            'buyer' => 'buyer.home',
            'seller' => 'seller.dashboard',
            'courier' => 'courier.dashboard',
        ];

        $route = $routes[$user->role] ?? null;

        return $route && app('router')->has($route)
            ? redirect()->route($route)
            : redirect('/');
    }

    public function register(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'role' => ['required', Rule::in(['buyer', 'seller', 'courier'])],
            'first_name' => ['required', 'string', 'max:60', 'regex:/^[\pL\s\'\-]+$/u'],
            'last_name' => ['required', 'string', 'max:60', 'regex:/^[\pL\s\'\-]+$/u'],
            'middle_initial' => ['nullable', 'string', 'max:2', 'regex:/^[\pL\.]+$/u'],
            'sex' => ['required', Rule::in(['female', 'male', 'prefer_not_to_say'])],
            'birthday' => ['required', 'date', 'before:today'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'contact_number' => ['required', 'regex:/^(09|\+639)\d{9}$/'],
            'province' => ['required', 'string', 'max:100'],
            'city' => ['required', 'string', 'max:100'],
            'barangay' => ['required', 'string', 'max:100'],
            'street_address' => ['required', 'string', 'max:255'],
            'business_name' => ['required_if:role,seller', 'nullable', 'string', 'max:150', 'regex:/^[\pL\pN\s&\'\.\-]+$/u'],
            'business_category' => ['required_if:role,seller', 'nullable', 'string', 'max:100'],
            'vehicle_type' => ['required_if:role,courier', 'nullable', 'string', 'max:80'],
            'plate_number' => ['required_if:role,courier', 'nullable', 'string', 'max:20'],
            'valid_id' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'business_permit' => ['required_if:role,seller', 'nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'courier_documents' => ['required_if:role,courier', 'nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'password' => ['required', 'confirmed', 'min:8', 'regex:/[a-z]/', 'regex:/[A-Z]/', 'regex:/[0-9]/'],
            'terms' => ['accepted'],
        ]);

        $status = $data['role'] === 'buyer' ? 'active' : 'pending';
        $validIdPath = $request->file('valid_id')->store('registration-documents/valid-ids', 'private');
        $businessPermitPath = $request->file('business_permit')?->store('registration-documents/business-permits', 'private');
        $courierDocumentsPath = $request->file('courier_documents')?->store('registration-documents/courier', 'private');

        $user = User::create([
            'name' => trim($data['first_name'].' '.$data['last_name']),
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'middle_initial' => $data['middle_initial'] ?? null,
            'sex' => $data['sex'],
            'birthday' => $data['birthday'],
            'email' => $data['email'],
            'contact_number' => $data['contact_number'],
            'role' => $data['role'],
            'status' => $status,
            'province' => $data['province'],
            'city' => $data['city'],
            'barangay' => $data['barangay'],
            'street_address' => $data['street_address'],
            'business_name' => $data['business_name'] ?? null,
            'business_category' => $data['business_category'] ?? null,
            'vehicle_type' => $data['vehicle_type'] ?? null,
            'plate_number' => isset($data['plate_number']) ? strtoupper($data['plate_number']) : null,
            'valid_id_path' => $validIdPath,
            'business_permit_path' => $businessPermitPath,
            'courier_documents_path' => $courierDocumentsPath,
            'password' => Hash::make($data['password']),
        ]);

        Auth::login($user);
        $request->session()->regenerate();

        return $status === 'pending'
            ? redirect()->route('application.pending')
            : redirect('/')->with('success', 'Your Bearly account has been created.');
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

        Auth::logout();

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
