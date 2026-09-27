<?php

namespace App\Http\Controllers\Api;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\InternationalPhone;
use App\Services\MobileEmailVerificationService;
use App\Services\PostalCodeLookup;
use App\Services\RegistrationLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Throwable;

class MobileRegistrationController extends Controller
{
    public function sendEmailCode(
        Request $request,
        MobileEmailVerificationService $verification
    ): JsonResponse {
        $request->merge([
            'email' => strtolower(
                trim((string) $request->input('email'))
            ),
        ]);

        $data = $request->validate([
            'email' => [
                'required',
                'email',
                'max:255',
                'unique:users,email',
            ],
        ]);

        $result = $verification->send(
            $request,
            $data['email']
        );

        return response()->json($result);
    }

    public function verifyEmailCode(
        Request $request,
        MobileEmailVerificationService $verification
    ): JsonResponse {
        $request->merge([
            'email' => strtolower(
                trim((string) $request->input('email'))
            ),
            'code' => trim(
                (string) $request->input('code')
            ),
            'verification_id' => trim(
                (string) $request->input('verification_id')
            ),
        ]);

        $data = $request->validate([
            'email' => [
                'required',
                'email',
                'max:255',
                'unique:users,email',
            ],

            'code' => [
                'required',
                'digits:6',
            ],

            'verification_id' => [
                'required',
                'string',
                'size:64',
            ],
        ]);

        $result = $verification->check(
            $data['email'],
            $data['code'],
            $data['verification_id']
        );

        return response()->json($result);
    }

    public function registerBuyer(
        Request $request,
        MobileEmailVerificationService $verification,
        InternationalPhone $phone,
        PostalCodeLookup $postalCodes,
        RegistrationLifecycleService $lifecycle
    ): JsonResponse {
        $request->merge([
            'email' => strtolower(
                trim((string) $request->input('email'))
            ),
        ]);

        $data = $request->validate([
            'first_name' => [
                'required',
                'string',
                'max:60',
                'regex:/^[\pL\s\'\-]+$/u',
            ],

            'last_name' => [
                'required',
                'string',
                'max:60',
                'regex:/^[\pL\s\'\-]+$/u',
            ],

            'middle_initial' => [
                'nullable',
                'string',
                'max:2',
                'regex:/^[A-Za-z]\.?$/D',
            ],

            'sex' => [
                'required',
                Rule::in([
                    'female',
                    'male',
                    'prefer_not_to_say',
                ]),
            ],

            'birthday' => [
                'required',
                'date',
                'before:today',
            ],

            'email' => [
                'required',
                'email',
                'max:255',
                'unique:users,email',
            ],

            'verification_id' => [
                'required',
                'string',
                'size:64',
            ],

            'contact_number' => [
                'required',
                'string',
                'max:17',
            ],

            'phone_country' => [
                'required',
                'string',
                'size:2',
            ],

            'city_code' => [
                'nullable',
                'regex:/^[0-9]{6,10}$/D',
            ],

            'postal_manual' => [
                'nullable',
                'boolean',
            ],

            'province' => [
                'required',
                'string',
                'max:100',
            ],

            'city' => [
                'required',
                'string',
                'max:100',
            ],

            'barangay' => [
                'required',
                'string',
                'max:100',
            ],

            'street_name' => [
                'required',
                'string',
                'max:180',
            ],

            'house_number' => [
                'required',
                'string',
                'max:40',
            ],

            'postal_code' => [
                'required',
                'digits:4',
            ],

            'valid_id' => [
                'required',
                'file',
                'mimes:jpg,jpeg,png,pdf',
                'max:5120',
            ],

            'password' => [
                'required',
                'confirmed',
                'min:8',
                'regex:/[a-z]/',
                'regex:/[A-Z]/',
                'regex:/[0-9]/',
            ],

            'terms' => [
                'accepted',
            ],
        ]);

        /*
        |--------------------------------------------------------------------------
        | Verify email proof
        |--------------------------------------------------------------------------
        */

        $verifiedAt = $verification->verifiedAt(
            $data['email'],
            $data['verification_id']
        );

        /*
        |--------------------------------------------------------------------------
        | Normalize phone and validate address
        |--------------------------------------------------------------------------
        */

        $data['contact_number'] = $phone->normalize(
            $data['contact_number'],
            $data['phone_country']
        );

        $postalCodes->validate($data);

        /*
        |--------------------------------------------------------------------------
        | Normalize middle initial
        |--------------------------------------------------------------------------
        */

        $data['middle_initial'] = empty(
            $data['middle_initial']
        )
            ? null
            : strtoupper(
                substr(
                    $data['middle_initial'],
                    0,
                    1
                )
            ) . '.';

        /*
        |--------------------------------------------------------------------------
        | Store government ID
        |--------------------------------------------------------------------------
        */

        $validIdFile = $request->file('valid_id');

        $validIdPath = $validIdFile->store(
            'registration-documents/valid-ids',
            'local'
        );

        try {
            $user = DB::transaction(function () use (
                $data,
                $verifiedAt,
                $validIdPath,
                $validIdFile,
                $lifecycle
            ) {
                $user = User::create([
                    'name' => trim(
                        $data['first_name']
                        . ' '
                        . ($data['middle_initial'] ?? '')
                        . ' '
                        . $data['last_name']
                    ),

                    'first_name' =>
                        $data['first_name'],

                    'last_name' =>
                        $data['last_name'],

                    'middle_initial' =>
                        $data['middle_initial'],

                    'sex' =>
                        $data['sex'],

                    'birthday' =>
                        $data['birthday'],

                    'email' =>
                        $data['email'],

                    'google_id' => null,

                    'contact_number' =>
                        $data['contact_number'],

                    'phone_country' =>
                        $data['phone_country'],

                    'phone_verified_at' => null,

                    'email_verified_at' =>
                        $verifiedAt,

                    'terms_accepted_at' =>
                        now(),

                    'terms_version' =>
                        config(
                            'bearly-policies.version'
                        ),

                    'privacy_version' =>
                        config(
                            'bearly-policies.version'
                        ),

                    'role' =>
                        UserRole::Buyer->value,

                    'status' =>
                        AccountStatus::Pending->value,

                    'province' =>
                        $data['province'],

                    'city' =>
                        $data['city'],

                    'barangay' =>
                        $data['barangay'],

                    'street_address' => trim(
                        $data['house_number']
                        . ' '
                        . $data['street_name']
                    )
                        . ', '
                        . $data['postal_code'],

                    'business_name' => null,

                    'business_category' => null,

                    'valid_id_path' =>
                        $validIdPath,

                    'business_permit_path' => null,

                    'password' =>
                        Hash::make(
                            $data['password']
                        ),
                ]);

                $lifecycle->recordPendingApplication(
                    $user,
                    UserRole::Buyer->value,
                    [
                        'house_number' =>
                            $data['house_number'],

                        'street_name' =>
                            $data['street_name'],

                        'barangay' =>
                            $data['barangay'],

                        'city' =>
                            $data['city'],

                        'province' =>
                            $data['province'],

                        'postal_code' =>
                            $data['postal_code'],

                        'city_code' =>
                            $data['city_code']
                            ?? null,
                    ],
                    [],
                    [
                        [
                            'type' => 'valid-id',
                            'path' => $validIdPath,
                            'file' => $validIdFile,
                        ],
                    ]
                );

                return $user;
            });
        } catch (Throwable $exception) {
            Storage::disk('local')
                ->delete($validIdPath);

            throw $exception;
        }

        /*
        |--------------------------------------------------------------------------
        | OTP can no longer be reused
        |--------------------------------------------------------------------------
        */

        $verification->forget(
            $data['verification_id']
        );

        return response()->json([
            'message' =>
                'Buyer registration submitted successfully.',

            'application' => [
                'user_id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'status' => $user->status,
            ],
        ], 201);
    }
}