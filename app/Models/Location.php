<?php
// app/Models/Location.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Location extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'parent_id',
        'type',
        'country_code',
        'is_active'
    ];

    protected $casts = [
        'is_active' => 'boolean'
    ];

    // Relationship to parent location (country for cities)
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'parent_id');
    }

    // Relationship to child locations (cities for countries)
    public function children(): HasMany
    {
        return $this->hasMany(Location::class, 'parent_id');
    }

    // Get country for this location
    public function country(): BelongsTo|Location|null
    {
        if ($this->type === 'country') {
            return $this;
        }

        return $this->parent();
    }

    // Scope for countries only
    public function scopeCountries($query)
    {
        return $query->where('type', 'country');
    }

    // Scope for cities only
    public function scopeCities($query)
    {
        return $query->where('type', 'city');
    }

    // Scope for active locations
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    // Get full path (Country > City)
    public function getFullPathAttribute(): string
    {
        if ($this->type === 'country') {
            return $this->name;
        }

        return $this->parent->name . ' > ' . $this->name;
    }
}
