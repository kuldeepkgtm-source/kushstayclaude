<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApprovalRequest;
use App\Models\Property;
use App\Services\PaymentConfigService;
use Illuminate\Http\Request;

class PropertyPaymentConfigController extends Controller
{
    public function __construct(private PaymentConfigService $service) {}

    // GET /api/properties/{property}/payment-config — never returns api_credentials (model $hidden)
    public function show(Request $request, Property $property)
    {
        $this->authorize('managePaymentConfig', $property);

        return response()->json($property->paymentConfig);
    }

    // POST /api/properties/{property}/payment-config — first-time setup only
    public function store(Request $request, Property $property)
    {
        $this->authorize('managePaymentConfig', $property);
        $data = $request->validate(['method_type' => 'required|in:upi_manual,api_gateway', 'upi_id' => 'nullable|string', 'upi_display_name' => 'nullable|string']);

        return response()->json($this->service->submitInitial($property, $request->user(), $data), 201);
    }

    // POST /api/properties/{property}/payment-config/change-request
    public function requestChange(Request $request, Property $property)
    {
        $this->authorize('managePaymentConfig', $property);
        $data = $request->validate(['upi_id' => 'nullable|string', 'upi_display_name' => 'nullable|string', 'reason' => 'nullable|string']);

        return response()->json($this->service->requestChange($property, $request->user(), $data, $data['reason'] ?? null), 201);
    }
}
