<?php

namespace App\Http\Controllers;

use App\Services\PostalCodeLookup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PostalCodeController extends Controller
{
    public function __invoke(Request $request, PostalCodeLookup $lookup): JsonResponse
    {
        $data = $request->validate([
            'city_code' => ['nullable', 'regex:/^[0-9]{6,10}$/D'],
            'province' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
        ]);
        $place = $lookup->find($data['city_code'] ?? null, $data['province'] ?? '', $data['city'] ?? '');
        return response()->json(['options' => $place['options']]);
    }
}
