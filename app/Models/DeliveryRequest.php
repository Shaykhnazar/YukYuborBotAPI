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
    // This should look for responses where request_id matches AND request_type is correct
    public function responses(): HasMany
    {
        return $this->hasMany(Response::class, 'request_id', 'id')
            ->where(function($query) {
                $query->where('request_type', 'delivery')
                    ->orWhere('request_type', 'send');
            });
    }

    // Responses where this delivery request is offered to send requests
    public function offerResponses(): HasMany
    {
        return $this->hasMany(Response::class, 'offer_id', 'id')
            ->where('request_type', 'send');
    }

    // All responses related to this delivery request (both as request and offer)
    public function allResponses(): HasMany
    {
        return $this->hasMany(Response::class, function($query) {
            $query->where(function($subQuery) {
                $subQuery->where('request_id', $this->id)->where('request_type', 'delivery');
            })->orWhere(function($subQuery) {
                $subQuery->where('offer_id', $this->id)->where('request_type', 'send');
            });
        });
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
