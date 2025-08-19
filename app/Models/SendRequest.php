<?php

namespace App\Models;

use App\Observers\RequestObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

#[ObservedBy(RequestObserver::class)]
class SendRequest extends Model
{
    use HasFactory;

    protected $table = 'send_requests';
    protected $guarded = false;

    protected $casts = [
        'from_date' => 'datetime',
        'to_date' => 'datetime',
        'price' => 'integer'
    ];

    // Relationships
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function fromLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'from_location_id');
    }

    public function toLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'to_location_id');
    }

    public function matchedDelivery(): BelongsTo
    {
        return $this->belongsTo(DeliveryRequest::class, 'matched_delivery_id');
    }

    // Matching responses where this send request is the main request
    public function responses(): HasMany
    {
        return $this->hasMany(Response::class, 'request_id', 'id')
            ->where(function($query) {
                $query->where('offer_type', 'send')
                    ->orWhere('offer_type', 'delivery');
            });
    }

    // Manual responses where someone manually responded to this send request
    public function manualResponses(): HasMany
    {
        return $this->hasMany(Response::class, 'offer_id', 'id')
            ->where('offer_type', 'send')
            ->where('response_type', 'manual');
    }

    // All responses (both matching and manual) - returns a query builder, not a relationship
    public function allResponsesQuery()
    {
        return Response::where(function($query) {
            $query->where(function($subQuery) {
                // Matching responses: this send request is the primary request
                $subQuery->where('request_id', $this->id)
                    ->whereIn('offer_type', ['send', 'delivery']);
            })->orWhere(function($subQuery) {
                // Manual responses: someone manually responded to this send request
                $subQuery->where('offer_id', $this->id)
                    ->where('offer_type', 'send')
                    ->where('response_type', 'manual');
            });
        });
    }

    // Responses where this send request is offered to delivery requests
    public function offerResponses(): HasMany
    {
        return $this->hasMany(Response::class, 'offer_id', 'id')
            ->where('offer_type', 'send');
    }

    // All responses related to this send request (both as request and offer)
    public function allResponses(): HasMany
    {
        return $this->hasMany(Response::class, function($query) {
            $query->where(function($subQuery) {
                $subQuery->where('request_id', $this->id)->where('offer_type', 'send');
            })->orWhere(function($subQuery) {
                $subQuery->where('offer_id', $this->id)->where('offer_type', 'delivery');
            });
        });
    }

    // Get chat through accepted responses
    public function chat(): HasOneThrough
    {
        return $this->hasOneThrough(
            Chat::class,
            Response::class,
            'offer_id', // Foreign key on responses table (send request as offer)
            'id', // Foreign key on chats table
            'id', // Local key on send_requests table
            'chat_id' // Local key on responses table
        )->where('responses.offer_type', 'send')
         ->whereIn('responses.overall_status', ['accepted', 'partial']);
    }


    // Scopes
    public function scopeOpen($query)
    {
        return $query->where('status', 'open');
    }

    public function scopeClosed($query)
    {
        return $query->whereIn('status', ['closed', 'completed']);
    }

    public function scopeForRoute($query, $fromLocationId, $toLocationId)
    {
        return $query->where('from_location_id', $fromLocationId)
                    ->where('to_location_id', $toLocationId);
    }

    public function scopeForCountryRoute($query, $fromCountryId, $toCountryId)
    {
        return $query->whereHas('fromLocation', function($q) use ($fromCountryId) {
            $q->where('id', $fromCountryId)->orWhere('parent_id', $fromCountryId);
        })->whereHas('toLocation', function($q) use ($toCountryId) {
            $q->where('id', $toCountryId)->orWhere('parent_id', $toCountryId);
        });
    }

    // Helper methods
    public function getRouteDisplayAttribute(): string
    {
        if ($this->fromLocation && $this->toLocation) {
            return $this->fromLocation->name . ' → ' . $this->toLocation->name;
        }

        // Fallback if locations are missing
        return "Location {$this->from_location_id} → Location {$this->to_location_id}";
    }

    public function getFromCountryAttribute(): ?Location
    {
        if (!$this->fromLocation) return null;

        return $this->fromLocation->type === 'country'
            ? $this->fromLocation
            : $this->fromLocation->parent;
    }

    public function getToCountryAttribute(): ?Location
    {
        if (!$this->toLocation) return null;

        return $this->toLocation->type === 'country'
            ? $this->toLocation
            : $this->toLocation->parent;
    }

}
