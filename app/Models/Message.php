<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    use HasFactory;

    protected $fillable = [
        'sender_id',
        'receiver_id',
        'message',
        'is_read'
    ];

    /**
     * Mutator to encrypt message before saving to database.
     */
    public function setMessageAttribute($value)
    {
        $this->attributes['message'] = encrypt($value);
    }

    /**
     * Accessor to decrypt message when retrieving from database.
     */
    public function getMessageAttribute($value)
    {
        try {
            return decrypt($value);
        } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
            // Return raw message if it was not encrypted
            return $value;
        }
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function receiver()
    {
        return $this->belongsTo(User::class, 'receiver_id');
    }
}
