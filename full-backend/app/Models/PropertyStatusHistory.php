<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PropertyStatusHistory extends Model
{
    protected $table = 'property_status_history';

    protected $fillable = ['property_id', 'from_status', 'to_status', 'changed_by', 'reason'];

    public function property() { return $this->belongsTo(Property::class); }

    public function changedBy() { return $this->belongsTo(User::class, 'changed_by'); }
}
