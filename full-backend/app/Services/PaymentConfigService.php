<?php

namespace App\Services;

use App\Models\ApprovalRequest;
use App\Models\AuditLog;
use App\Models\Property;
use App\Models\PropertyPaymentConfig;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Payment-destination changes never write PropertyPaymentConfig directly from an owner action —
 * they create a pending ApprovalRequest, and the OLD config (if any) stays 'active' the entire
 * time (spec §7 step 2). Only submitInitial() (a property's very first config, before it has
 * ever gone LIVE) writes immediately, since there is no existing destination to protect yet and
 * it is reviewed as part of the property's own approval (see PropertyApprovalService doc-block).
 */
class PaymentConfigService
{
    public function submitInitial(Property $property, User $owner, array $data): PropertyPaymentConfig
    {
        abort_if($property->paymentConfig()->exists(), 409, 'A payment configuration already exists — submit a change request instead.');

        return DB::transaction(function () use ($property, $owner, $data) {
            $config = PropertyPaymentConfig::create([
                'property_id' => $property->id,
                'method_type' => $data['method_type'] ?? 'upi_manual',
                'upi_id' => $data['upi_id'] ?? null,
                'upi_display_name' => $data['upi_display_name'] ?? null,
                'qr_image_path' => $data['qr_image_path'] ?? null,
                'status' => $property->status === 'LIVE' ? 'active' : 'inactive',
            ]);

            AuditLog::create([
                'user_id' => $owner->id, 'property_id' => $property->id, 'action' => 'payment_config.created',
                'entity_type' => 'PropertyPaymentConfig', 'entity_id' => $config->id, 'before' => null, 'after' => $config->only(['method_type', 'upi_id', 'upi_display_name']),
            ]);

            return $config;
        });
    }

    public function requestChange(Property $property, User $owner, array $newData, ?string $reason = null): ApprovalRequest
    {
        $existing = $property->paymentConfig;

        return DB::transaction(function () use ($property, $owner, $newData, $reason, $existing) {
            $request = ApprovalRequest::create([
                'property_id' => $property->id, 'type' => 'payment_config_change', 'status' => 'pending',
                'submitted_by' => $owner->id, 'payload' => $newData,
                'previous_snapshot' => $existing?->only(['method_type', 'upi_id', 'upi_display_name', 'qr_image_path']),
            ]);

            if ($existing) {
                $existing->update(['status' => 'pending_change']); // stays usable — see PropertyPaymentConfig::isApiConfirmable() etc; only the *status flag* changes, not the live upi_id
            }

            AuditLog::create([
                'user_id' => $owner->id, 'property_id' => $property->id, 'action' => 'payment_config.change_requested',
                'entity_type' => 'ApprovalRequest', 'entity_id' => $request->id, 'before' => $request->previous_snapshot, 'after' => $newData,
            ]);

            return $request;
        });
    }

    public function approveChange(ApprovalRequest $request, User $admin, ?string $notes = null): PropertyPaymentConfig
    {
        abort_unless($admin->is_super_admin, 403, 'Only a Kush Stay Super Admin may approve a payment configuration change.');
        abort_unless($request->type === 'payment_config_change' && $request->status === 'pending', 422, 'This request is not a pending payment change.');

        return DB::transaction(function () use ($request, $admin, $notes) {
            $property = Property::lockForUpdate()->findOrFail($request->property_id);
            $config = PropertyPaymentConfig::firstOrNew(['property_id' => $property->id]);
            $before = $config->exists ? $config->only(['method_type', 'upi_id', 'upi_display_name', 'qr_image_path']) : null;

            $payload = $request->payload;
            $config->fill([
                'method_type' => $payload['method_type'] ?? $config->method_type ?? 'upi_manual',
                'upi_id' => $payload['upi_id'] ?? $config->upi_id,
                'upi_display_name' => $payload['upi_display_name'] ?? $config->upi_display_name,
                'qr_image_path' => $payload['qr_image_path'] ?? $config->qr_image_path,
                'status' => 'active',
            ]);
            $config->property_id = $property->id;
            $config->save();

            $request->update(['status' => 'approved', 'reviewed_by' => $admin->id, 'reviewed_at' => now(), 'review_notes' => $notes]);

            AuditLog::create([
                'user_id' => $admin->id, 'property_id' => $property->id, 'action' => 'payment_config.change_approved',
                'entity_type' => 'PropertyPaymentConfig', 'entity_id' => $config->id, 'before' => $before, 'after' => $config->only(['method_type', 'upi_id', 'upi_display_name']),
            ]);

            return $config;
        });
    }

    public function rejectChange(ApprovalRequest $request, User $admin, string $reason): ApprovalRequest
    {
        abort_unless($admin->is_super_admin, 403, 'Only a Kush Stay Super Admin may reject a payment configuration change.');

        return DB::transaction(function () use ($request, $admin, $reason) {
            $request->update(['status' => 'rejected', 'reviewed_by' => $admin->id, 'reviewed_at' => now(), 'review_notes' => $reason]);

            // The existing config, if any, goes back to active — the rejected change never took effect.
            if ($existing = PropertyPaymentConfig::where('property_id', $request->property_id)->first()) {
                $existing->update(['status' => 'active']);
            }

            AuditLog::create([
                'user_id' => $admin->id, 'property_id' => $request->property_id, 'action' => 'payment_config.change_rejected',
                'entity_type' => 'ApprovalRequest', 'entity_id' => $request->id, 'before' => null, 'after' => ['reason' => $reason],
            ]);

            return $request->fresh();
        });
    }
}
