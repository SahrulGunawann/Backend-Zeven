<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Address extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'label',
        'receiver_name',
        'phone_number',
        'full_address',
        'is_main'
    ];

    protected $casts = [
        'is_main' => 'boolean'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
