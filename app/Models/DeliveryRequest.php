<?php

namespace App\Models;

use App\Observers\RequestObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use App\Jobs\RecordDeliveryRequestToGoogleSheets;
use App\Jobs\CloseRequestInGoogleSheets;

#[ObservedBy(RequestObserver::class)]
class DeliveryRequest extends Model
{
    use HasFactory;

    protected $table = 'delivery_requests';
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

    public function matchedSend(): BelongsTo
    {
        return $this->belongsTo(SendRequest::class, 'matched_send_id');
    }

    // Matching responses where this delivery request is the main request
    public function responses(): HasMany
    {
        return $this->hasMany(Response::class, 'request_id', 'id')
            ->where(function($query) {
                $query->where('request_type', 'delivery')
                    ->orWhere('request_type', 'send');
            });
    }

    // Manual responses where someone manually responded to this delivery request
    public function manualResponses(): HasMany
    {
        return $this->hasMany(Response::class, 'offer_id', 'id')
            ->where('request_type', 'delivery')
            ->where('response_type', 'manual');
    }

    // All responses (both matching and manual) - returns a query builder, not a relationship
    public function allResponsesQuery()
    {
        return Response::where(function($query) {
            $query->where(function($subQuery) {
                // Matching responses: this delivery request is the primary request
                $subQuery->where('request_id', $this->id)
                    ->whereIn('request_type', ['delivery', 'send']);
            })->orWhere(function($subQuery) {
                // Manual responses: someone manually responded to this delivery request
                $subQuery->where('offer_id', $this->id)
                    ->where('request_type', 'delivery')
                    ->where('response_type', 'manual');
            });
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

    /**
     * Boot method to handle Google Sheets integration
     */
    protected static function boot()
    {
        parent::boot();

        static::created(function ($request) {
            try {
                // Dispatch queued job to add delivery request to Google Sheets
                RecordDeliveryRequestToGoogleSheets::dispatch($request->id)
                    ->delay(now()->addSeconds(2))
                    ->onQueue('gsheets');
            } catch (\Exception $e) {
                // Log error but don't fail request creation
                \Log::error('Failed to dispatch delivery request Google Sheets job during creation', [
                    'request_id' => $request->id,
                    'error' => $e->getMessage()
                ]);
            }
        });

        static::updated(function ($request) {
            try {
                // Update Google Sheets when request status changes to closed
                if ($request->isDirty('status') && in_array($request->status, ['closed', 'completed'])) {
                    CloseRequestInGoogleSheets::dispatch('delivery', $request->id)
                        ->delay(now()->addSeconds(3))
                        ->onQueue('gsheets');
                }
            } catch (\Exception $e) {
                // Log error but don't fail request update
                \Log::error('Failed to update delivery request in Google Sheets during update', [
                    'request_id' => $request->id,
                    'error' => $e->getMessage()
                ]);
            }
        });
    }
}
