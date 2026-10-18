<?php

namespace App\Services;

use App\Models\Dispute;
use App\Models\DisputeEvent;
use App\Models\DisputeEvidence;
use App\Models\SellerOrder;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class BuyerDisputeService
{
    /**
     * @return list<string>
     */
    private function contextRelations(): array
    {
        return [
            'store.sellerProfile.user',
            'returnRequests.returnShipment.logisticsProfile.user',
            'returnRequests.returnShipment.originLogisticsProfile.user',
            'returnRequests.returnShipment.parcels.deliveryAttempts.riderProfile.user',
            'returnRequests.returnShipment.parcels.dispatchBatches.riderProfile.user',
            'returnRequests.returnShipment.parcels.pickupRequests.assignments.riderProfile.user',
            'returnRequests.returnShipment.parcels.pickupRequests.logisticsProfile.user',
            'shipments.logisticsProfile.user',
            'shipments.originLogisticsProfile.user',
            'shipments.parcels.deliveryAttempts.riderProfile.user',
            'shipments.parcels.dispatchBatches.riderProfile.user',
            'shipments.parcels.pickupRequests.assignments.riderProfile.user',
            'shipments.parcels.pickupRequests.logisticsProfile.user',
        ];
    }

    public function buyerOrder(User $buyer, int $sellerOrderId): SellerOrder
    {
        return $this->ownedSellerOrder($buyer, $sellerOrderId)
            ->with([...$this->contextRelations(), 'activeDispute'])
            ->firstOrFail();
    }

    public function create(
        User $buyer,
        int $sellerOrderId,
        string $subject,
        string $description,
        array $evidenceFiles = [],
    ): Dispute {
        $storedPaths = [];

        try {
            return DB::transaction(function () use (
                $buyer,
                $sellerOrderId,
                $subject,
                $description,
                $evidenceFiles,
                &$storedPaths,
            ): Dispute {
                $sellerOrder = $this->ownedSellerOrder($buyer, $sellerOrderId)
                    ->with($this->contextRelations())
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($sellerOrder->disputes()
                    ->whereNotIn('status', ['resolved', 'closed'])
                    ->lockForUpdate()
                    ->exists()) {
                    throw ValidationException::withMessages([
                        'seller_order' => 'This Seller Order already has an active dispute.',
                    ]);
                }

                $returnRequest = $sellerOrder->returnRequests->first();
                $shipments = $this->relatedShipments(
                    $sellerOrder,
                    $returnRequest?->returnShipment,
                );
                $shipment = $this->contextShipment($shipments);
                $seller = $sellerOrder->store?->sellerProfile?->user;
                $logistics = $this->logisticsForShipments($shipments, $shipment);
                $rider = $this->riderForShipments($shipments, $shipment);
                $now = now();

                $dispute = Dispute::query()->create([
                    'dispute_no' => $this->generateDisputeNumber(),
                    'seller_order_id' => $sellerOrder->id,
                    'return_request_id' => $returnRequest?->id,
                    'shipment_id' => $shipment?->id,
                    'opened_by' => $buyer->id,
                    'subject' => trim($subject),
                    'description' => trim($description),
                    'priority' => 'normal',
                    'status' => 'open',
                    'amount_minor' => (int) $sellerOrder->total_minor,
                    'assigned_to' => null,
                    'opened_at' => $now,
                    'response_due_at' => null,
                    'resolved_at' => null,
                    'closed_at' => null,
                    'resolution' => null,
                ]);

                $participants = collect([
                    ['user' => $buyer, 'role' => 'buyer'],
                    ['user' => $seller, 'role' => 'seller'],
                    ['user' => $logistics, 'role' => 'logistics'],
                    ['user' => $rider, 'role' => 'rider'],
                ])
                    ->filter(fn (array $participant): bool => $participant['user'] instanceof User)
                    ->unique(fn (array $participant): int => (int) $participant['user']->id)
                    ->values();

                foreach ($participants as $participant) {
                    $dispute->participants()->attach($participant['user']->id, [
                        'participant_role' => $participant['role'],
                        'joined_at' => $now,
                    ]);
                }

                foreach ($evidenceFiles as $file) {
                    if (! $file instanceof UploadedFile) {
                        continue;
                    }

                    $this->storeEvidence($dispute, $buyer, $file, $storedPaths);
                }

                DisputeEvent::query()->create([
                    'dispute_id' => $dispute->id,
                    'event_type' => 'opened',
                    'actor_user_id' => $buyer->id,
                    'from_status' => null,
                    'to_status' => 'open',
                    'note' => 'Dispute opened by Buyer.',
                    'metadata' => [
                        'visibility' => 'participants',
                        'participant_count' => $participants->count(),
                        'evidence_count' => count($evidenceFiles),
                    ],
                    'created_at' => $now,
                ]);

                return $dispute->load([
                    'sellerOrder.store',
                    'returnRequest',
                    'shipment',
                    'participants',
                    'evidence.uploader',
                    'events.actor',
                ]);
            });
        } catch (Throwable $exception) {
            foreach ($storedPaths as $path) {
                if (Storage::disk('local')->exists($path)) {
                    Storage::disk('local')->delete($path);
                }
            }

            throw $exception;
        }
    }

    public function participantDispute(User $participant, int $disputeId): Dispute
    {
        return Dispute::query()
            ->whereKey($disputeId)
            ->whereHas('participants', fn ($query) => $query->where('users.id', $participant->id))
            ->with([
                'sellerOrder.store',
                'returnRequest',
                'shipment',
                'opener',
                'participants',
                'evidence.uploader',
                'events.actor',
            ])
            ->firstOrFail();
    }

    private function ownedSellerOrder(User $buyer, int $sellerOrderId)
    {
        return SellerOrder::query()
            ->whereKey($sellerOrderId)
            ->whereHas('order', fn ($orders) => $orders->where('buyer_id', $buyer->id));
    }

    private function relatedShipments(
        SellerOrder $sellerOrder,
        ?Shipment $preferredReturnShipment,
    ): Collection {
        return collect([$preferredReturnShipment])
            ->merge($sellerOrder->returnRequests->map(
                fn ($returnRequest) => $returnRequest->returnShipment
            ))
            ->merge($sellerOrder->shipments)
            ->filter(fn ($shipment): bool => $shipment instanceof Shipment)
            ->unique('id')
            ->values();
    }

    private function contextShipment(Collection $shipments): ?Shipment
    {
        return $shipments
            ->first(fn (Shipment $shipment): bool => $shipment->purpose === 'return')
            ?? $shipments
                ->first(fn (Shipment $shipment): bool => $shipment->purpose === 'outbound')
            ?? $shipments->sortByDesc('id')->first();
    }

    private function logisticsForShipments(
        Collection $shipments,
        ?Shipment $preferredShipment,
    ): ?User {
        foreach ($this->prioritizedShipments($shipments, $preferredShipment) as $shipment) {
            $user = $shipment->logisticsProfile?->user
                ?? $shipment->originLogisticsProfile?->user;

            if ($user) {
                return $user;
            }

            foreach ($shipment->parcels as $parcel) {
                $user = $parcel->pickupRequests
                    ->sortByDesc('id')
                    ->map(fn ($pickupRequest) => $pickupRequest->logisticsProfile?->user)
                    ->filter()
                    ->first();

                if ($user) {
                    return $user;
                }
            }
        }

        return null;
    }

    private function prioritizedShipments(
        Collection $shipments,
        ?Shipment $preferredShipment,
    ): Collection {
        return collect([$preferredShipment])
            ->merge($shipments)
            ->filter(fn ($shipment): bool => $shipment instanceof Shipment)
            ->unique('id')
            ->values();
    }

    private function riderForShipments(
        Collection $shipments,
        ?Shipment $preferredShipment,
    ): ?User {
        foreach ($this->prioritizedShipments($shipments, $preferredShipment) as $shipment) {
            $rider = $this->riderForShipment($shipment);

            if ($rider) {
                return $rider;
            }
        }

        return null;
    }

    private function riderForShipment(?Shipment $shipment): ?User
    {
        if (! $shipment) {
            return null;
        }

        $candidates = collect();

        foreach ($shipment->parcels as $parcel) {
            foreach ($parcel->deliveryAttempts->sortByDesc('id') as $attempt) {
                if ($attempt->riderProfile?->user) {
                    $candidates->push($attempt->riderProfile->user);
                }
            }

            foreach ($parcel->dispatchBatches->sortByDesc('id') as $batch) {
                if ($batch->status !== 'cancelled' && $batch->riderProfile?->user) {
                    $candidates->push($batch->riderProfile->user);
                }
            }

            foreach ($parcel->pickupRequests as $pickupRequest) {
                foreach ($pickupRequest->assignments->sortByDesc('id') as $assignment) {
                    if ($assignment->status !== 'cancelled' && $assignment->riderProfile?->user) {
                        $candidates->push($assignment->riderProfile->user);
                    }
                }
            }
        }

        return $candidates->unique('id')->first();
    }

    /** @param list<string> $storedPaths */
    private function storeEvidence(
        Dispute $dispute,
        User $buyer,
        UploadedFile $file,
        array &$storedPaths,
    ): void {
        $mimeType = $file->getMimeType() ?: 'application/octet-stream';
        $extension = $file->extension() ?: 'bin';
        $path = $file->storeAs(
            "dispute-evidence/{$dispute->id}",
            Str::uuid()->toString().'.'.$extension,
            'local',
        );

        $storedPaths[] = $path;

        DisputeEvidence::query()->create([
            'dispute_id' => $dispute->id,
            'uploaded_by' => $buyer->id,
            'type' => str_starts_with($mimeType, 'image/') ? 'image' : 'document',
            'file_path' => $path,
            'original_name' => $this->safeOriginalName($file->getClientOriginalName()),
            'description' => null,
            'created_at' => now(),
        ]);
    }

    private function generateDisputeNumber(): string
    {
        do {
            $number = 'DSP-'.now()->format('Ymd').'-'.Str::upper(Str::random(8));
        } while (Dispute::query()->where('dispute_no', $number)->exists());

        return $number;
    }

    private function safeOriginalName(string $name): string
    {
        $base = basename(str_replace('\\', '/', $name));
        $safe = preg_replace('/[^A-Za-z0-9._ -]/', '_', $base) ?: 'evidence';

        return Str::limit($safe, 200, '');
    }
}
