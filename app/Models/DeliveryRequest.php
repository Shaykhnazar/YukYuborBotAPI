<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

class DeliveryRequest extends Model
{
    use HasFactory;

    protected $table = 'delivery_requests';

    protected $guarded = false;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    // Responses where this delivery request is the main request
    public function responses(): HasMany
    {
        return $this->hasMany(Response::class, 'request_id', 'id')
            ->where('request_type', 'delivery');
    }

    // Responses where this delivery request is offered to send requests
    public function offerResponses(): HasMany
    {
        return $this->hasMany(Response::class, 'offer_id', 'id')
            ->where('request_type', 'send');
    }

    // Get chat through accepted responses
    public function chat(): HasOneThrough
    {
        return $this->hasOneThrough(
            Chat::class,
            Response::class,
            'request_id', // Foreign key on responses table (for delivery requests)
            'id', // Foreign key on chats table
            'id', // Local key on delivery_requests table
            'chat_id' // Local key on responses table
        )->where('responses.request_type', 'delivery')
         ->whereIn('responses.status', ['accepted', 'waiting']);
    }
}
