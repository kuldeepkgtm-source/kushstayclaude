<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    // GET /api/payments
    public function index(Request $request)
    {
        $payments = Payment::with('booking')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->latest()->paginate(50);

        return response()->json($payments);
    }

    // POST /api/payments — record a payment attempt/result against a booking
    public function store(Request $request)
    {
        $data = $request->validate([
            'booking_id' => 'required|integer|exists:bookings,id',
            'method' => 'required|string|max:40',
            'amount' => 'required|numeric|min:0',
            'status' => 'required|in:pending,success,failed,refunded',
            'gateway_reference' => 'nullable|string',
        ]);

        return DB::transaction(function () use ($data) {
            $payment = Payment::create($data + ['paid_at' => $data['status'] === 'success' ? now() : null]);

            if ($data['status'] === 'success') {
                $booking = Booking::lockForUpdate()->findOrFail($data['booking_id']);
                $newPaid = $booking->amount_paid + $data['amount'];
                $booking->update([
                    'amount_paid' => $newPaid,
                    'balance' => $booking->total - $newPaid,
                    'payment_status' => $newPaid >= $booking->total ? 'Paid' : 'Partially Paid',
                ]);
            }

            return response()->json($payment, 201);
        });
    }
}
