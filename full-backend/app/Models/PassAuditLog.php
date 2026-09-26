<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PassAuditLog extends Model
{
    protected $fillable = ['pass_id', 'admin_user_id', 'action', 'before', 'after', 'reason'];

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array'];
    }
}
