<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

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

    // Get all applicable from location IDs (parent + children)
    public function getFromLocationIdsAttribute(): array
    {
        $ids = [$this->from_location_id];

        if ($this->fromLocation && $this->fromLocation->children->isNotEmpty()) {
            $ids = array_merge($ids, $this->fromLocation->children->pluck('id')->toArray());
        }

        return $ids;
    }

    // Get all applicable to location IDs (parent + children)
    public function getToLocationIdsAttribute(): array
    {
        $ids = [$this->to_location_id];

        if ($this->toLocation && $this->toLocation->children->isNotEmpty()) {
            $ids = array_merge($ids, $this->toLocation->children->pluck('id')->toArray());
        }

        return $ids;
    }

    // Get active requests count for this route
    public function getActiveRequestsCountAttribute(): int
    {
        return $this->countActiveRequests();
    }

    // Method to count active requests
    public function countActiveRequests(): int
    {
        $fromIds = $this->from_location_ids;
        $toIds = $this->to_location_ids;

        $sendCount = SendRequest::whereIn('from_location_id', $fromIds)
            ->whereIn('to_location_id', $toIds)
            ->whereIn('status', ['open', 'has_responses'])
            ->count();

        $deliveryCount = DeliveryRequest::whereIn('from_location_id', $fromIds)
            ->whereIn('to_location_id', $toIds)
            ->whereIn('status', ['open', 'has_responses'])
            ->count();

        return $sendCount + $deliveryCount;
    }

    // Static method to load routes with counts efficiently
    public static function withActiveRequestsCounts($query = null)
    {
        $query = $query ?: static::query();

        $routes = $query->with(['fromLocation.children', 'toLocation.children'])->get();

        if ($routes->isEmpty()) {
            return $routes;
        }

        // Collect all location IDs for batch querying
        $allFromIds = [];
        $allToIds = [];
        $routeLocationMap = [];

        foreach ($routes as $route) {
            $fromIds = $route->from_location_ids;
            $toIds = $route->to_location_ids;

            $routeLocationMap[$route->id] = [
                'from_ids' => $fromIds,
                'to_ids' => $toIds
            ];

            $allFromIds = array_merge($allFromIds, $fromIds);
            $allToIds = array_merge($allToIds, $toIds);
        }

        $allFromIds = array_unique($allFromIds);
        $allToIds = array_unique($allToIds);

        // Batch query for send requests
        $sendCounts = SendRequest::select('from_location_id', 'to_location_id', DB::raw('COUNT(*) as count'))
            ->whereIn('status', ['open', 'has_responses'])
            ->whereIn('from_location_id', $allFromIds)
            ->whereIn('to_location_id', $allToIds)
            ->groupBy('from_location_id', 'to_location_id')
            ->get()
            ->groupBy('from_location_id')
            ->map(function ($group) {
                return $group->keyBy('to_location_id');
            });

        // Batch query for delivery requests
        $deliveryCounts = DeliveryRequest::select('from_location_id', 'to_location_id', DB::raw('COUNT(*) as count'))
            ->whereIn('status', ['open', 'has_responses'])
            ->whereIn('from_location_id', $allFromIds)
            ->whereIn('to_location_id', $allToIds)
            ->groupBy('from_location_id', 'to_location_id')
            ->get()
            ->groupBy('from_location_id')
            ->map(function ($group) {
                return $group->keyBy('to_location_id');
            });

        // Calculate counts for each route
        foreach ($routes as $route) {
            $mapping = $routeLocationMap[$route->id];
            $count = 0;

            // Sum counts for all applicable location combinations
            foreach ($mapping['from_ids'] as $fromId) {
                foreach ($mapping['to_ids'] as $toId) {
                    $count += $sendCounts->get($fromId)?->get($toId)?->count ?? 0;
                    $count += $deliveryCounts->get($fromId)?->get($toId)?->count ?? 0;
                }
            }

            $route->active_requests_count = $count;
        }

        return $routes;
    }

    // Scope to get routes with counts
    public function scopeWithRequestsCounts($query)
    {
        return static::withActiveRequestsCounts($query);
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
