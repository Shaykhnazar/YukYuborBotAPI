<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SuggestedRoute extends Model
{
    use HasFactory;

    protected $fillable = [
        'from_location',
        'to_location',
        'user_id',
        'status',
        'reviewed_at',
        'reviewed_by',
        'notes'
    ];

    protected $casts = [
        'reviewed_at' => 'datetime'
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    // Scope for pending suggestions
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    // Scope for approved suggestions
    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }
}
