<?php

namespace App\Services;

use App\Models\Bed;
use App\Models\PricingRule;
use Carbon\CarbonPeriod;

/**
 * Reads pricing_rules instead of a hard-coded price map. Resolution order per night, per bed
 * type: special_date (exact date match) > seasonal (date range match) > weekend (day-of-week
 * match) > base. This generalizes the prototype's flat DEFAULT_PRICES + weekendSurchargePct.
 */
class PricingService
{
    public function nightlyRate(int $propertyId, string $bedType, string $dateIso): float
    {
        $rules = PricingRule::where('property_id', $propertyId)->where('bed_type', $bedType)->get();

        $base = $rules->firstWhere('rule_type', 'base');
        $price = $base?->price ?? 0.0;

        $special = $rules->where('rule_type', 'special_date')
            ->first(fn ($r) => $r->valid_from?->toDateString() === $dateIso);
        if ($special) {
            return (float) $special->price;
        }

        $seasonal = $rules->where('rule_type', 'seasonal')
            ->first(fn ($r) => $r->valid_from && $r->valid_to
                && $dateIso >= $r->valid_from->toDateString() && $dateIso <= $r->valid_to->toDateString());
        if ($seasonal) {
            return (float) $seasonal->price;
        }

        $dow = (int) date('w', strtotime($dateIso));
        $weekend = $rules->where('rule_type', 'weekend')->first(fn ($r) => (int) $r->day_of_week === $dow);
        if ($weekend && $weekend->surcharge_pct) {
            return round($price * (1 + $weekend->surcharge_pct / 100), 2);
        }

        return (float) $price;
    }

    public function individualBedsTotal(array $bedIds, string $checkIn, string $checkOut): float
    {
        $beds = Bed::with('room')->whereIn('id', $bedIds)->get();
        $nights = CarbonPeriod::create($checkIn, '1 day', (new \DateTime($checkOut))->modify('-1 day'));

        $total = 0.0;
        foreach ($beds as $bed) {
            $bedType = ($bed->room->is_ac ? 'AC' : 'NAC').'-'.$bed->position;
            foreach ($nights as $night) {
                $total += $this->nightlyRate($bed->room->property_id, $bedType, $night->toDateString());
            }
        }

        return round($total, 2);
    }

    public function privateRoomTotal(int $roomId, string $checkIn, string $checkOut): float
    {
        $room = \App\Models\Room::findOrFail($roomId);
        $bedType = ($room->is_ac ? 'AC' : 'NAC').'-Private';
        $nights = CarbonPeriod::create($checkIn, '1 day', (new \DateTime($checkOut))->modify('-1 day'));

        $total = 0.0;
        foreach ($nights as $night) {
            $total += $this->nightlyRate($room->property_id, $bedType, $night->toDateString());
        }

        return round($total, 2);
    }
}
