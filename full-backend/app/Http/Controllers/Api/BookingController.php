<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BookingConflictException;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Services\BookingService;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    public function __construct(private BookingService $bookings) {}

    // GET /api/bookings — filterable list for the admin Bookings screen
    public function index(Request $request)
    {
        $query = Booking::with('beds', 'room')->latest();
        if ($request->filled('source')) {
            $query->where('source', $request->string('source'));
        }
        if ($request->filled('status')) {
            $query->where('booking_status', $request->string('status'));
        }
        if ($request->filled('search')) {
            $s = $request->string('search');
            $query->where(fn ($q) => $q->where('customer_name', 'like', "%{$s}%")
                ->orWhere('booking_ref', 'like', "%{$s}%")->orWhere('customer_phone', 'like', "%{$s}%"));
        }

        return response()->json($query->paginate(50));
    }

    // POST /api/bookings
    public function store(Request $request)
    {
        $data = $request->validate([
            'property_id' => 'required|integer|exists:properties,id',
            'customer_name' => 'required|string|max:255',
            'customer_phone' => 'nullable|digits:10',
            'source' => 'required|string',
            'check_in' => 'required|date_format:Y-m-d',
            'check_out' => 'required|date_format:Y-m-d|after:check_in',
            'guest_count' => 'required|integer|min:1',
            'booking_type' => 'required|in:individual,private',
            'room_id' => 'required|integer|exists:rooms,id',
            'bed_ids' => 'required_if:booking_type,individual|array',
            'bed_ids.*' => 'integer|exists:beds,id',
            'discount' => 'nullable|numeric|min:0',
            'payment_method' => 'nullable|string',
            'amount_paid' => 'nullable|numeric|min:0',
            'special_request' => 'nullable|string',
        ]);

        try {
            $booking = $this->bookings->createBooking($data);
        } catch (BookingConflictException $e) {
            return response()->json(['error' => 'bed_unavailable', 'message' => $e->getMessage(), 'bed_ids' => $e->conflictingBedIds], 409);
        }

        return response()->json($booking, 201);
    }

    // GET /api/bookings/{booking}
    public function show(Booking $booking)
    {
        return response()->json($booking->load('beds', 'room', 'payments'));
    }

    // PUT /api/bookings/{booking}
    public function update(Request $request, Booking $booking)
    {
        $data = $request->validate([
            'check_in' => 'nullable|date_format:Y-m-d',
            'check_out' => 'nullable|date_format:Y-m-d',
            'bed_ids' => 'nullable|array',
            'bed_ids.*' => 'integer|exists:beds,id',
            'amount_paid' => 'nullable|numeric|min:0',
            'payment_status' => 'nullable|in:Unpaid,Partially Paid,Paid,Refunded',
            'special_request' => 'nullable|string',
        ]);

        if (isset($data['check_in']) || isset($data['check_out']) || isset($data['bed_ids'])) {
            try {
                $this->bookings->reschedule($booking, $data['bed_ids'] ?? null, $data['check_in'] ?? null, $data['check_out'] ?? null);
            } catch (BookingConflictException $e) {
                return response()->json(['error' => 'bed_unavailable', 'message' => $e->getMessage()], 409);
            }
        }

        $booking->fill(collect($data)->only(['amount_paid', 'payment_status', 'special_request'])->all());
        if (isset($data['amount_paid'])) {
            $booking->balance = $booking->total - $data['amount_paid'];
        }
        $booking->save();

        return response()->json($booking->fresh('beds'));
    }

    // POST /api/bookings/{booking}/cancel
    public function cancel(Booking $booking)
    {
        return response()->json($this->bookings->cancel($booking));
    }

    // POST /api/bookings/{booking}/check-in
    public function checkIn(Booking $booking)
    {
        return response()->json($this->bookings->checkIn($booking));
    }

    // POST /api/bookings/{booking}/check-out
    public function checkOut(Booking $booking)
    {
        return response()->json($this->bookings->checkOut($booking));
    }
}
