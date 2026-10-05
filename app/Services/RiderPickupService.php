<?php

namespace App\Services;

use App\Models\PickupAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class RiderPickupService
{
    public function acceptPickup(
        string $pickupNo,
        User $rider
    ): PickupAssignment {
        return DB::transaction(
            function () use (
                $pickupNo,
                $rider
            ): PickupAssignment {
                $profile = $rider
                    ->riderProfile()
                    ->firstOrFail();

                $assignment =
                    PickupAssignment::query()
                        ->where(
                            'rider_profile_id',
                            $profile->id
                        )
                        ->whereHas(
                            'pickupRequest',
                            fn ($query) =>
                                $query->where(
                                    'pickup_no',
                                    $pickupNo
                                )
                        )
                        ->lockForUpdate()
                        ->firstOrFail();

                if (
                    $assignment->status
                    !== 'assigned'
                ) {
                    throw new ConflictHttpException(
                        'This pickup assignment can no longer be accepted.'
                    );
                }

                $assignment->update([
                    'status' =>
                        'accepted',

                    'accepted_at' =>
                        now(),
                ]);

                return $assignment
                    ->fresh([
                        'pickupRequest',
                    ]);
            },
            3
        );
    }
}