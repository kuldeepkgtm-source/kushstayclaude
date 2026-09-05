<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BookingConflictException;
use App\Http\Controllers\Controller;
use App\Services\HoldService;
use Illuminate\Http\Request;

class HoldController extends Controller
{
    public function __construct(private HoldService $holds) {}

    // POST /api/holds
    public function store(Request $request)
    {
        $data = $request->validate([
            'property_id' => 'required|integer|exists:properties,id',
            'bed_ids' => 'required|array|min:1',
            'bed_ids.*' => 'integer|exists:beds,id',
            'check_in' => 'required|date_format:Y-m-d',
            'check_out' => 'required|date_format:Y-m-d|after:check_in',
            'session_token' => 'nullable|string',
        ]);

        try {
            $result = $this->holds->createHold($data['property_id'], $data['bed_ids'], $data['check_in'], $data['check_out'], $data['session_token'] ?? null);
        } catch (BookingConflictException $e) {
            return response()->json(['error' => 'bed_unavailable', 'message' => $e->getMessage()], 409);
        }

        return response()->json($result, 201);
    }

    // DELETE /api/holds/{hold}
    public function destroy(int $hold)
    {
        $this->holds->release($hold);

        return response()->json(null, 204);
    }
}
