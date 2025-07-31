<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Route extends Model
{
    use HasFactory;

    protected $fillable = [
        'from_location_id',
        'to_location_id',
        'is_active',
        'priority',
        'description'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'priority' => 'integer'
    ];

    // Relationships
    public function fromLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'from_location_id');
    }

    public function toLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'to_location_id');
    }

    // Get total active requests count for this route
    public function getActiveRequestsCountAttribute(): int
    {
        $sendRequestsCount = SendRequest::where('from_location_id', $this->from_location_id)
            ->where('to_location_id', $this->to_location_id)
            ->whereIn('status', ['open', 'has_responses'])
            ->count();
            
        $deliveryRequestsCount = DeliveryRequest::where('from_location_id', $this->from_location_id)
            ->where('to_location_id', $this->to_location_id)
            ->whereIn('status', ['open', 'has_responses'])
            ->count();
            
        return $sendRequestsCount + $deliveryRequestsCount;
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByPriority($query)
    {
        return $query->orderBy('priority', 'desc');
    }

    public function scopeForCountries($query, $fromCountryId, $toCountryId)
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
        return $this->fromLocation->name . ' → ' . $this->toLocation->name;
    }

    public function isCountryToCountry(): bool
    {
        return $this->fromLocation->type === 'country' &&
            $this->toLocation->type === 'country';
    }

    public function isCityToCity(): bool
    {
        return $this->fromLocation->type === 'city' &&
            $this->toLocation->type === 'city';
    }
}
