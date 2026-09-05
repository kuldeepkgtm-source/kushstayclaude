<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Room;

class RoomController extends Controller
{
    // GET /api/rooms
    public function index()
    {
        return response()->json(Room::with('beds')->get());
    }
}
