<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApprovalRequest extends Model
{
    protected $fillable = [
        'property_id', 'type', 'status', 'submitted_by', 'payload', 'previous_snapshot',
        'submitted_documents', 'reviewed_by', 'reviewed_at', 'review_notes',
    ];

    protected function casts(): array
    {
        return ['payload' => 'array', 'previous_snapshot' => 'array', 'submitted_documents' => 'array', 'reviewed_at' => 'datetime'];
    }

    public function property() { return $this->belongsTo(Property::class); }

    public function submittedBy() { return $this->belongsTo(User::class, 'submitted_by'); }

    public function reviewedBy() { return $this->belongsTo(User::class, 'reviewed_by'); }
}
