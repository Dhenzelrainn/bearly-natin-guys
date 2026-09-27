<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MobileEmailVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
}