<?php

namespace App\Http\Controllers\Api\Pass;

use App\Exceptions\BookingConflictException;
use App\Exceptions\InsufficientPassBalanceException;
use App\Http\Controllers\Controller;
use App\Models\Pass;
use App\Models\PassBooking;
use App\Services\PassBookingService;
use Illuminate\Http\Request;

class PassBookingController extends Controller
{
    public function __construct(private PassBookingService $passBookings) {}

    // GET /api/pass/availability — requires the pass-token middleware; $request->attributes->get('pass')
    // is the pass that token resolved to, so a customer can only ever preview against their OWN pass.
    public function availability(Request $request)
    {
        $data = $request->validate(['check_in' => 'required|date_format:Y-m-d', 'check_out' => 'required|date_format:Y-m-d|after:check_in']);
        $pass = $request->attributes->get('pass');

        return response()->json($this->passBookings->previewAvailability($pass, $data['check_in'], $data['check_out']));
    }

    // POST /api/pass/book
    public function book(Request $request)
    {
        $data = $request->validate([
            'check_in' => 'required|date_format:Y-m-d',
            'check_out' => 'required|date_format:Y-m-d|after:check_in',
            'category' => 'required|in:AC-Upper,AC-Lower,NAC-Upper,NAC-Lower',
        ]);
        /** @var Pass $pass */
        $pass = $request->attributes->get('pass');

        try {
            $result = $this->passBookings->book($pass, $data['check_in'], $data['check_out'], $data['category']);
        } catch (InsufficientPassBalanceException $e) {
            return response()->json(['error' => 'insufficient_balance', 'message' => $e->getMessage(), 'remaining' => $e->remaining], 422);
        } catch (BookingConflictException $e) {
            return response()->json(['error' => 'bed_unavailable', 'message' => $e->getMessage()], 409);
        }

        return response()->json($result, 201);
    }

    // POST /api/pass/bookings/{passBooking}/confirm-upgrade-payment
    public function confirmUpgradePayment(Request $request, PassBooking $passBooking)
    {
        $data = $request->validate(['method' => 'required|string', 'gateway_reference' => 'nullable|string', 'simulated_success' => 'required|boolean']);
        $this->assertOwnership($request, $passBooking);

        if (! $data['simulated_success']) {
            $this->passBookings->failUpgradePayment($passBooking->id);

            return response()->json(['status' => 'failed'], 402);
        }

        try {
            $pb = $this->passBookings->confirmUpgradePayment($passBooking->id, $data);
        } catch (InsufficientPassBalanceException $e) {
            return response()->json(['error' => 'insufficient_balance', 'message' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($pb);
    }

    // POST /api/pass/bookings/{passBooking}/cancel
    public function cancel(Request $request, PassBooking $passBooking)
    {
        $this->assertOwnership($request, $passBooking);

        return response()->json($this->passBookings->cancel($passBooking, 'Cancelled by customer'));
    }

    /** IDOR guard: the bearer token's pass must own the booking being acted on. */
    private function assertOwnership(Request $request, PassBooking $passBooking): void
    {
        $pass = $request->attributes->get('pass');
        abort_unless($pass && $passBooking->pass_id === $pass->id, 403, 'This booking does not belong to your pass.');
    }
}
