<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use HasFactory;

     protected $guarded = false;

    public function telegramUser(): HasOne
    {
        return $this->hasOne(TelegramUser::class, 'user_id', 'id');
    }

    public function sendRequests(): HasMany
    {
        return $this->hasMany(SendRequest::class, 'user_id', 'id');
    }
    public function deliveryRequests(): HasMany
    {
        return $this->hasMany(DeliveryRequest::class, 'user_id', 'id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class, 'user_id', 'id');
    }
}
