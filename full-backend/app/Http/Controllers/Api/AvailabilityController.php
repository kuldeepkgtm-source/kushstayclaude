<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Room;
use App\Services\AvailabilityService;
use App\Services\PricingService;
use Illuminate\Http\Request;

class AvailabilityController extends Controller
{
    public function __construct(
        private AvailabilityService $availability,
        private PricingService $pricing,
    ) {}

    // GET /api/availability?property_id=&check_in=&check_out=&guests=&ac=
    public function __invoke(Request $request)
    {
        $data = $request->validate([
            'property_id' => 'required|integer|exists:properties,id',
            'check_in' => 'required|date_format:Y-m-d',
            'check_out' => 'required|date_format:Y-m-d|after:check_in',
            'guests' => 'required|integer|min:1',
            'ac' => 'nullable|in:true,false',
        ]);

        $rooms = Room::where('property_id', $data['property_id'])->get();
        $result = [];

        foreach ($rooms as $room) {
            if (array_key_exists('ac', $data) && $data['ac'] !== null) {
                $wantsAc = $data['ac'] === 'true';
                if ($wantsAc !== (bool) $room->is_ac) {
                    continue;
                }
            }

            $freeBeds = $this->availability->availableBeds($room->id, $data['check_in'], $data['check_out']);
            $priv = $this->availability->isPrivateAvailable($room->id, $data['check_in'], $data['check_out']);

            $result[$room->code] = [
                'room' => $room->name,
                'free_beds' => $freeBeds->pluck('code')->values(),
                'individual_total_if_all_free_beds_used' => $freeBeds->count() >= $data['guests']
                    ? $this->pricing->individualBedsTotal($freeBeds->take($data['guests'])->pluck('id')->all(), $data['check_in'], $data['check_out'])
                    : null,
                'private_available' => $priv['available'],
                'private_reason' => $priv['reason'],
                'private_total' => $priv['available'] ? $this->pricing->privateRoomTotal($room->id, $data['check_in'], $data['check_out']) : null,
            ];
        }

        return response()->json(['data' => $result]);
    }
}
