<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApprovalRequest;
use App\Services\PaymentConfigService;
use Illuminate\Http\Request;

class SuperAdminPaymentConfigController extends Controller
{
    public function __construct(private PaymentConfigService $service) {}

    // GET /api/admin/payment-change-requests?status=pending
    public function index(Request $request)
    {
        $query = ApprovalRequest::where('type', 'payment_config_change');
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        return response()->json($query->with('property:id,name')->latest()->paginate(50));
    }

    public function approve(Request $request, ApprovalRequest $approvalRequest)
    {
        $data = $request->validate(['notes' => 'nullable|string']);

        return response()->json($this->service->approveChange($approvalRequest, $request->user(), $data['notes'] ?? null));
    }

    public function reject(Request $request, ApprovalRequest $approvalRequest)
    {
        $data = $request->validate(['reason' => 'required|string|min:5']);

        return response()->json($this->service->rejectChange($approvalRequest, $request->user(), $data['reason']));
    }
}
