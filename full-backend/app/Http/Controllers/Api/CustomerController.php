<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    // GET /api/customers
    public function index(Request $request)
    {
        $customers = Customer::withCount('bookings')
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->string('search').'%')
                ->orWhere('phone', 'like', '%'.$request->string('search').'%'))
            ->orderByDesc('id')->paginate(50);

        return response()->json($customers);
    }
}
