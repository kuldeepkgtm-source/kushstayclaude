<?php

namespace App\Http\Controllers\Api\Pass;

use App\Http\Controllers\Controller;
use App\Models\Pass;
use Illuminate\Http\Request;

/**
 * All three actions scope strictly to $request->user()->id (the authenticated Customer from
 * the Sanctum token) — a pass_id is never trusted from the request as an ownership claim, which
 * is what stops Customer A from reading Customer B's pass by guessing/incrementing an ID (IDOR).
 */
class PassMeController extends Controller
{
    // GET /api/pass/me
    public function show(Request $request)
    {
        $pass = Pass::with('product')->where('customer_id', $request->user()->id)->latest()->first();
        if (! $pass) {
            return response()->json(['pass' => null]);
        }

        return response()->json([
            'display_id' => $pass->display_id, 'category' => $pass->product->bed_category,
            'total_days' => $pass->total_days, 'used_days' => $pass->days_used, 'remaining_days' => $pass->remaining_days,
            'activated_at' => $pass->activated_at, 'expires_at' => $pass->expires_at, 'status' => $pass->status,
        ]);
    }

    // GET /api/pass/me/bookings
    public function bookings(Request $request)
    {
        $pass = Pass::where('customer_id', $request->user()->id)->latest()->first();
        if (! $pass) {
            return response()->json(['data' => []]);
        }

        $bookings = $pass->bookings()->with('booking')->latest()->get()->map(fn ($pb) => [
            'check_in' => $pb->booking?->check_in, 'check_out' => $pb->booking?->check_out,
            'nights' => $pb->nights, 'bed_category' => $pb->bed_category, 'upgrade_fee_paise' => $pb->upgrade_fee_paise,
            'days_consumed' => $pb->days_consumed, 'status' => $pb->status,
        ]);

        return response()->json(['data' => $bookings]);
    }
}
