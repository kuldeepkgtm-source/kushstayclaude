<?php

namespace App\Http\Controllers\Api\Pass;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/** Everything here reads $request->attributes->get('pass') — set only by EnsurePassToken after
 *  validating the bearer token — so there is no path by which a request can read a pass it
 *  doesn't hold a token for. This is the whole IDOR defense for the customer-facing pass API. */
class PassCustomerController extends Controller
{
    // GET /api/pass/me
    public function me(Request $request)
    {
        $pass = $request->attributes->get('pass');

        return response()->json($pass->load('product', 'customer:id,name,phone,email'));
    }

    // GET /api/pass/me/ledger
    public function ledger(Request $request)
    {
        $pass = $request->attributes->get('pass');

        return response()->json($pass->ledger);
    }

    // GET /api/pass/me/bookings
    public function bookings(Request $request)
    {
        $pass = $request->attributes->get('pass');

        return response()->json($pass->bookings()->with('booking')->latest()->get());
    }
}
