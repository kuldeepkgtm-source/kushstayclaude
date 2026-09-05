<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\CalendarSource;
use App\Models\Room;
use App\Services\IcalService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class IcalController extends Controller
{
    public function __construct(private IcalService $ical) {}

    // GET /ical/{token}.ics — public, secured only by the unguessable token (no auth header needed,
    // since calendar apps/OTAs fetch this URL directly). Feed scope = whatever the token's source maps to.
    public function feed(string $token)
    {
        $token = str_replace('.ics', '', $token);
        $source = CalendarSource::where('export_token', $token)->firstOrFail();

        $query = Booking::with('beds')->active();
        if ($source->bed_id) {
            $query->whereHas('beds', fn ($q) => $q->where('beds.id', $source->bed_id));
        } elseif ($source->room_id) {
            $query->where('room_id', $source->room_id);
        }

        $ics = $this->ical->generate($query->get(), $source->name);

        return response($ics, 200, ['Content-Type' => 'text/calendar; charset=utf-8']);
    }

    // POST /api/ical/import — manual upload path (mirrors the prototype's file-upload import)
    public function import(Request $request)
    {
        $data = $request->validate([
            'calendar_source_id' => 'required|integer|exists:calendar_sources,id',
            'ics' => 'required|string',
        ]);

        $source = CalendarSource::findOrFail($data['calendar_source_id']);
        $events = $this->ical->parse($data['ics']);
        $report = $this->ical->importEvents($source, $events);

        return response()->json($report);
    }

    // POST /api/ical/sources — register a new OTA/iCal connection and mint its export token
    public function storeSource(Request $request)
    {
        $data = $request->validate([
            'property_id' => 'required|integer|exists:properties,id',
            'name' => 'required|string',
            'type' => 'required|in:ota,ical',
            'ical_url' => 'nullable|url',
            'room_id' => 'nullable|integer|exists:rooms,id',
            'bed_id' => 'nullable|integer|exists:beds,id',
            'sync_frequency_minutes' => 'nullable|integer|min:5',
        ]);

        $source = CalendarSource::create($data + ['export_token' => Str::random(40), 'status' => 'Active']);

        return response()->json($source, 201);
    }
}
