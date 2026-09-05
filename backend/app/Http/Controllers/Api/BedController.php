<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bed;
use Illuminate\Http\Request;

class BedController extends Controller
{
    // GET /api/beds?room_id=
    public function index(Request $request)
    {
        $beds = Bed::with('room')
            ->when($request->filled('room_id'), fn ($q) => $q->where('room_id', $request->integer('room_id')))
            ->get();

        return response()->json($beds);
    }
}
