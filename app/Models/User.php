<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use HasFactory;

     protected $guarded = false;

    public function telegramUser()
    {
        return $this->hasOne(TelegramUser::class, 'user_id', 'id');
    }

    public function sendRequests()
    {
        return $this->hasMany(SendRequest::class, 'user_id', 'id');
    }
    public function deliveryRequests()
    {
        return $this->hasMany(DeliveryRequest::class, 'user_id', 'id');
    }
}
