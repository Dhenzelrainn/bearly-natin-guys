<?php

namespace App\Http\Controllers;

use App\Models\DeliveryProof;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DeliveryProofController extends Controller
{
    public function show(
        Request $request,
        DeliveryProof $deliveryProof,
        AuditService $audit,
    ): StreamedResponse {
        /** @var User|null $actor */
        $actor = $request->user();

        abort_unless($actor, 401);

        $attempt = $deliveryProof->deliveryAttempt()
            ->with('parcel.shipment')
            ->firstOrFail();
        $shipment = $attempt->parcel?->shipment;

        if ($actor->role === 'logistics') {
            $profileId = $actor->logisticsProfile()->value('id');

            abort_unless(
                $profileId
                    && $shipment
                    && (int) $shipment->logistics_profile_id === (int) $profileId,
                404,
            );
        } elseif ($actor->role !== 'admin') {
            abort(403);
        }

        abort_unless(
            $shipment
                && $deliveryProof->type === 'photo'
                && filled($deliveryProof->file_path),
            404,
        );

        $path = (string) $deliveryProof->file_path;
        $segments = explode('/', $path);

        abort_unless(
            str_starts_with($path, 'delivery-proofs/')
                && count($segments) > 1
                && ! str_contains($path, '\\')
                && ! in_array('', $segments, true)
                && ! in_array('.', $segments, true)
                && ! in_array('..', $segments, true)
                && Storage::disk('local')->exists($path),
            404,
        );

        $mimeType = strtolower((string) Storage::disk('local')->mimeType($path));
        $extension = match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => null,
        };

        abort_unless($extension, 404);

        $audit->record(
            action: 'delivery.proof.previewed',
            module: 'Fulfillment',
            subject: $deliveryProof,
            description: "Delivery proof previewed for {$shipment->shipment_no}.",
            newValues: [
                'delivery_attempt_id' => (int) $attempt->id,
                'shipment_id' => (int) $shipment->id,
                'viewer_role' => (string) $actor->role,
            ],
            severity: 'info',
            request: $request,
            actor: $actor,
        );

        return Storage::disk('local')->response(
            $path,
            "delivery-proof-{$deliveryProof->id}.{$extension}",
            [
                'Content-Type' => $mimeType,
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
                'Content-Security-Policy' => "default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'",
            ],
            'inline',
        );
    }
}
