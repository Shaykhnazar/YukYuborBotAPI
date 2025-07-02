<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

class SendRequest extends Model
{
    use HasFactory;

    protected $table = 'send_requests';

    protected $guarded = false;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    // Responses where this send request is the main request
    public function responses(): HasMany
    {
        return $this->hasMany(Response::class, 'request_id', 'id')
            ->where(function($query) {
                $query->where('request_type', 'send')
                    ->orWhere('request_type', 'delivery');
            });
    }

    // Responses where this send request is offered to delivery requests
    public function offerResponses(): HasMany
    {
        return $this->hasMany(Response::class, 'offer_id', 'id')
            ->where('request_type', 'delivery');
    }

    // All responses related to this send request (both as request and offer)
    public function allResponses(): HasMany
    {
        return $this->hasMany(Response::class, function($query) {
            $query->where(function($subQuery) {
                $subQuery->where('request_id', $this->id)->where('request_type', 'send');
            })->orWhere(function($subQuery) {
                $subQuery->where('offer_id', $this->id)->where('request_type', 'delivery');
            });
        });
    }

    // Get chat through accepted responses
    public function chat(): HasOneThrough
    {
        return $this->hasOneThrough(
            Chat::class,
            Response::class,
            'request_id', // Foreign key on responses table (for send requests)
            'id', // Foreign key on chats table
            'id', // Local key on send_requests table
            'chat_id' // Local key on responses table
        )->where('responses.request_type', 'send')
         ->whereIn('responses.status', ['accepted', 'waiting']);
    }
}
