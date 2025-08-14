<?php

namespace App\Models;

use App\Observers\RouteObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

#[ObservedBy(RouteObserver::class)]
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

    protected $appends = ['active_requests_count'];

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
        // Return the dynamically set value or calculate it
        return $this->attributes['active_requests_count'] ?? $this->countActiveRequests();
    }

    public function setActiveRequestsCountAttribute(int $value): void
    {
        // Optional validation
        if ($value < 0) {
            throw new \InvalidArgumentException('Active requests count cannot be negative');
        }

        $this->attributes['active_requests_count'] = $value;
    }

    // Method to count active requests (including reverse direction)
    public function countActiveRequests(): int
    {
        $fromIds = $this->from_location_ids;
        $toIds = $this->to_location_ids;

        // Forward direction counts
        $sendForwardCount = SendRequest::whereIn('from_location_id', $fromIds)
            ->whereIn('to_location_id', $toIds)
            ->whereIn('status', ['open', 'has_responses'])
            ->count();

        $deliveryForwardCount = DeliveryRequest::whereIn('from_location_id', $fromIds)
            ->whereIn('to_location_id', $toIds)
            ->whereIn('status', ['open', 'has_responses'])
            ->count();

        // Reverse direction counts
        $sendReverseCount = SendRequest::whereIn('from_location_id', $toIds)
            ->whereIn('to_location_id', $fromIds)
            ->whereIn('status', ['open', 'has_responses'])
            ->count();

        $deliveryReverseCount = DeliveryRequest::whereIn('from_location_id', $toIds)
            ->whereIn('to_location_id', $fromIds)
            ->whereIn('status', ['open', 'has_responses'])
            ->count();

        return $sendForwardCount + $deliveryForwardCount + $sendReverseCount + $deliveryReverseCount;
    }

    // Static method to load routes with counts efficiently
    public static function withActiveRequestsCounts($query = null)
    {
        $query = $query ?: static::query();

        $routes = $query->with(['fromLocation.children', 'toLocation.children'])->get();

        if ($routes->isEmpty()) {
            return $routes;
        }

        // Build a single union query for maximum performance
        $unionQueries = [];
        $routeKeys = [];

        foreach ($routes as $route) {
            $fromIds = implode(',', $route->from_location_ids);
            $toIds = implode(',', $route->to_location_ids);
            $routeKey = "route_{$route->id}";
            $routeKeys[$routeKey] = $route->id;

            // Forward direction: from_location_id IN fromIds AND to_location_id IN toIds
            $unionQueries[] = "
                SELECT '{$routeKey}' as route_key, COUNT(*) as cnt, 'send_forward' as type
                FROM send_requests
                WHERE status IN ('open','has_responses')
                  AND from_location_id IN ({$fromIds})
                  AND to_location_id IN ({$toIds})
            ";

            $unionQueries[] = "
                SELECT '{$routeKey}' as route_key, COUNT(*) as cnt, 'delivery_forward' as type
                FROM delivery_requests
                WHERE status IN ('open','has_responses')
                  AND from_location_id IN ({$fromIds})
                  AND to_location_id IN ({$toIds})
            ";

            // Reverse direction: from_location_id IN toIds AND to_location_id IN fromIds
            $unionQueries[] = "
                SELECT '{$routeKey}' as route_key, COUNT(*) as cnt, 'send_reverse' as type
                FROM send_requests
                WHERE status IN ('open','has_responses')
                  AND from_location_id IN ({$toIds})
                  AND to_location_id IN ({$fromIds})
            ";

            $unionQueries[] = "
                SELECT '{$routeKey}' as route_key, COUNT(*) as cnt, 'delivery_reverse' as type
                FROM delivery_requests
                WHERE status IN ('open','has_responses')
                  AND from_location_id IN ({$toIds})
                  AND to_location_id IN ({$fromIds})
            ";
        }

        if (!empty($unionQueries)) {
            $counts = DB::select("
                SELECT route_key, SUM(cnt) as total
                FROM (" . implode(' UNION ALL ', $unionQueries) . ") as combined
                GROUP BY route_key
            ");

            $countsByRoute = collect($counts)->keyBy('route_key');

            foreach ($routes as $route) {
                $routeKey = "route_{$route->id}";
                $route->active_requests_count = $countsByRoute->get($routeKey)?->total ?? 0;
            }
        } else {
            foreach ($routes as $route) {
                $route->active_requests_count = 0;
            }
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
