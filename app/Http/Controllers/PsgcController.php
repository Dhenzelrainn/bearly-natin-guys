<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Throwable;

class PsgcController extends Controller
{
    private const BASE_URL = 'https://psgc.cloud/api/v2';

    public function provinces(): JsonResponse
    {
        return $this->request('/provinces');
    }

    public function cities(string $provinceCode): JsonResponse
    {
        abort_unless(
            preg_match('/^\d{6,10}$/', $provinceCode),
            404
        );

        return $this->request(
            "/provinces/{$provinceCode}/cities-municipalities"
        );
    }

    public function barangays(string $cityCode): JsonResponse
    {
        abort_unless(
            preg_match('/^\d{6,10}$/', $cityCode),
            404
        );

        return $this->request(
            "/cities-municipalities/{$cityCode}/barangays"
        );
    }

    private function request(string $endpoint): JsonResponse
    {
        try {
            $response = Http::acceptJson()
                ->withUserAgent('Bearly-Ecommerce/1.0')
                ->connectTimeout(5)
                ->timeout(15)
                ->retry(2, 300)
                ->get(self::BASE_URL . $endpoint);

            if (! $response->successful()) {
                return response()->json([
                    'message' => 'PSGC service returned an error.',
                    'status' => $response->status(),
                ], 502);
            }

            $data = $response->json();
            $data = $this->fixEncoding($data);

            if (! is_array($data)) {
                return response()->json([
                    'message' => 'Invalid address service response.',
                ], 502);
            }

            return response()->json($data);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Address service is currently unavailable.',
                'error' => app()->isLocal()
                    ? $exception->getMessage()
                    : null,
            ], 503);
        }
    }

    private function fixEncoding(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(
                fn (mixed $item) => $this->fixEncoding($item),
                $value
            );
        }

        if (
            is_string($value) &&
            (
                str_contains($value, 'Ã') ||
                str_contains($value, 'Â')
            )
        ) {
            return mb_convert_encoding(
                $value,
                'Windows-1252',
                'UTF-8'
            );
        }

        return $value;
    }
}