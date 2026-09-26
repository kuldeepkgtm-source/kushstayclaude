<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Services\PropertyApprovalService;
use App\Services\TenantContext;
use Illuminate\Http\Request;

/** Owner-facing property management — create/edit a draft, submit for review, self-activate once approved. */
class PropertyController extends Controller
{
    public function __construct(private PropertyApprovalService $approval, private TenantContext $tenant) {}

    // GET /api/properties — only properties this user actually belongs to (or all, for a super admin)
    public function index(Request $request)
    {
        $ids = $this->tenant->accessiblePropertyIds($request->user());

        return response()->json(Property::whereIn('id', $ids)->get());
    }

    // POST /api/properties — creates a DRAFT and makes the creator its property_owner
    public function store(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:255']);
        $property = Property::create($data + ['status' => 'DRAFT', 'created_by' => $request->user()->id]);
        \App\Models\PropertyUser::create(['user_id' => $request->user()->id, 'property_id' => $property->id, 'role' => 'property_owner']);

        return response()->json($property, 201);
    }

    // GET /api/properties/{property}
    public function show(Request $request, Property $property)
    {
        $this->authorize('view', $property);

        return response()->json($property->load('statusHistory'));
    }

    // PUT /api/properties/{property} — edit draft/rejected-then-resubmitted details (not status)
    public function update(Request $request, Property $property)
    {
        $this->authorize('update', $property);
        $data = $request->validate([
            'name' => 'sometimes|string|max:255', 'address' => 'sometimes|nullable|string',
            'phone' => 'sometimes|nullable|string', 'email' => 'sometimes|nullable|email',
        ]);
        $property->update($data);

        return response()->json($property);
    }

    // POST /api/properties/{property}/submit
    public function submit(Request $request, Property $property)
    {
        $this->authorize('update', $property);

        return response()->json($this->approval->submit($property, $request->user()));
    }

    // POST /api/properties/{property}/activate — owner-only, only once APPROVED
    public function activate(Request $request, Property $property)
    {
        $this->authorize('activate', $property);

        return response()->json($this->approval->activate($property, $request->user()));
    }
}
