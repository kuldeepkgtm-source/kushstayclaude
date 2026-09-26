<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Services\PropertyApprovalService;
use Illuminate\Http\Request;

/** Every method here requires is_super_admin — enforced by the 'super_admin' middleware on the route group, not repeated per-method. */
class SuperAdminPropertyController extends Controller
{
    public function __construct(private PropertyApprovalService $approval) {}

    // GET /api/admin/properties?status=
    public function index(Request $request)
    {
        $query = Property::query();
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        return response()->json($query->with('paymentConfig', 'whatsappConfig')->latest()->paginate(50));
    }

    public function show(Property $property)
    {
        return response()->json($property->load('statusHistory', 'paymentConfig', 'whatsappConfig', 'users'));
    }

    public function moveToReview(Request $request, Property $property)
    {
        return response()->json($this->approval->moveToReview($property, $request->user()));
    }

    public function approve(Request $request, Property $property)
    {
        $data = $request->validate(['notes' => 'nullable|string']);

        return response()->json($this->approval->approve($property, $request->user(), $data['notes'] ?? null));
    }

    public function reject(Request $request, Property $property)
    {
        $data = $request->validate(['reason' => 'required|string|min:5']);

        return response()->json($this->approval->reject($property, $request->user(), $data['reason']));
    }

    public function requestChanges(Request $request, Property $property)
    {
        $data = $request->validate(['reason' => 'required|string|min:5']);

        return response()->json($this->approval->requestChanges($property, $request->user(), $data['reason']));
    }

    public function suspend(Request $request, Property $property)
    {
        $data = $request->validate(['reason' => 'required|string|min:5']);

        return response()->json($this->approval->suspend($property, $request->user(), $data['reason']));
    }

    public function reactivate(Request $request, Property $property)
    {
        $data = $request->validate(['notes' => 'nullable|string']);

        return response()->json($this->approval->reactivate($property, $request->user(), $data['notes'] ?? null));
    }
}
