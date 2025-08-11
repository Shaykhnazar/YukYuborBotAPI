<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ObservedBy(ResponseObserver::class)]
class Response extends Model
{
    use HasFactory;

    protected $table = 'responses';
    protected $guarded = false;

    const STATUS_PENDING = 'pending';
    const STATUS_ACCEPTED = 'accepted';
    const STATUS_REJECTED = 'rejected';
    const STATUS_WAITING = 'waiting';
    const STATUS_RESPONDED = 'responded';

    const TYPE_MATCHING = 'matching';
    const TYPE_MANUAL = 'manual';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function responder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responder_id', 'id');
    }

    public function chat(): BelongsTo
    {
        return $this->belongsTo(Chat::class, 'chat_id', 'id');
    }

    // Polymorphic relationship to handle both send and delivery requests
    public function request()
    {
        return $this->morphTo();
    }

    // ✅ IMPROVED: Helper methods to get the actual request objects
    public function sendRequest(): BelongsTo
    {
        return $this->belongsTo(SendRequest::class, 'offer_id', 'id')
            ->where('request_type', 'send');
    }

    public function deliveryRequest(): BelongsTo
    {
        return $this->belongsTo(DeliveryRequest::class, 'offer_id', 'id')
            ->where('request_type', 'delivery');
    }

    // ✅ ADD: Helper method to get the request based on type
    public function getRequestAttribute()
    {
        if ($this->request_type === 'send') {
            return $this->sendRequest;
        } elseif ($this->request_type === 'delivery') {
            return $this->deliveryRequest;
        }
        return null;
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeAccepted($query)
    {
        return $query->where('status', self::STATUS_ACCEPTED);
    }

    public function scopeResponded($query)
    {
        return $query->where('status', self::STATUS_RESPONDED);
    }

    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeByType($query, $type)
    {
        return $query->where('response_type', $type);
    }

    // ✅ ADD: Helper to check if response has an active chat
    public function hasActiveChat(): bool
    {
        return $this->chat && in_array($this->chat->status, ['active']);
    }
}
