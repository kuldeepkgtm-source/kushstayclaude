<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Property;
use App\Models\PropertyWhatsAppConfig;
use App\Models\User;

/**
 * Owner-editable directly (no approval gate — spec §7's heavier flow is explicitly about payment
 * destinations, not WhatsApp). Still fully audited and strictly tenant-isolated: every method
 * takes the already-authorized Property, never a raw property_id, so there is no path into this
 * class that bypasses TenantContext.
 */
class WhatsAppConfigService
{
    public function update(Property $property, User $owner, array $data): PropertyWhatsAppConfig
    {
        $config = PropertyWhatsAppConfig::firstOrNew(['property_id' => $property->id]);
        $before = $config->exists ? $config->only(['phone_number', 'display_name', 'provider', 'enabled']) : null;

        $config->fill([
            'property_id' => $property->id,
            'phone_number' => $data['phone_number'] ?? $config->phone_number,
            'display_name' => $data['display_name'] ?? $config->display_name,
            'provider' => $data['provider'] ?? $config->provider ?? 'not_configured',
            'enabled' => $data['enabled'] ?? $config->enabled ?? false,
            'status' => 'pending_verification',
        ]);
        if (array_key_exists('credentials', $data)) {
            $config->credentials = $data['credentials'];
        }
        $config->save();

        AuditLog::create([
            'user_id' => $owner->id, 'property_id' => $property->id, 'action' => 'whatsapp_config.updated',
            'entity_type' => 'PropertyWhatsAppConfig', 'entity_id' => $config->id,
            'before' => $before, 'after' => $config->only(['phone_number', 'display_name', 'provider', 'enabled']),
        ]);

        return $config;
    }

    /** A stand-in "test connection" — real verification depends on the provider chosen later (spec §105). */
    public function testConnection(Property $property): array
    {
        $config = $property->whatsappConfig;
        if (! $config || ! $config->phone_number || $config->provider === 'not_configured') {
            return ['status' => 'error', 'message' => 'WhatsApp is not configured for this property yet.'];
        }

        // No real provider is wired up yet (spec §20/§103) — report that honestly instead of
        // claiming a connection that was never actually tested against anything.
        $config->update(['last_connection_test_at' => now(), 'last_connection_status' => 'not_available']);

        return ['status' => 'not_available', 'message' => 'No WhatsApp provider is connected yet — this is a configuration placeholder, not a live test.'];
    }
}
