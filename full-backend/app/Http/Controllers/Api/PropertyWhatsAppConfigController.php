<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Services\WhatsAppConfigService;
use Illuminate\Http\Request;

class PropertyWhatsAppConfigController extends Controller
{
    public function __construct(private WhatsAppConfigService $service) {}

    public function show(Request $request, Property $property)
    {
        $this->authorize('manageWhatsAppConfig', $property);

        return response()->json($property->whatsappConfig);
    }

    public function update(Request $request, Property $property)
    {
        $this->authorize('manageWhatsAppConfig', $property);
        $data = $request->validate(['phone_number' => 'nullable|string', 'display_name' => 'nullable|string', 'provider' => 'nullable|in:not_configured,meta_cloud_api,other', 'enabled' => 'nullable|boolean']);

        return response()->json($this->service->update($property, $request->user(), $data));
    }

    public function testConnection(Request $request, Property $property)
    {
        $this->authorize('manageWhatsAppConfig', $property);

        return response()->json($this->service->testConnection($property));
    }
}
