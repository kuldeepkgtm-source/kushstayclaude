<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PropertyUser extends Model
{
    protected $fillable = ['user_id', 'property_id', 'role'];

    public function user() { return $this->belongsTo(User::class); }

    public function property() { return $this->belongsTo(Property::class); }
}
