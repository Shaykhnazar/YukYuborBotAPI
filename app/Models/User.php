<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use App\Jobs\RecordUserToGoogleSheets;

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

    // Chat relationships
    public function sentChats(): HasMany
    {
        return $this->hasMany(Chat::class, 'sender_id', 'id');
    }

    public function receivedChats(): HasMany
    {
        return $this->hasMany(Chat::class, 'receiver_id', 'id');
    }

    public function chatMessages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'sender_id', 'id');
    }

    // Helper method to get all chats for this user
    public function getAllChats()
    {
        return Chat::where('sender_id', $this->id)
            ->orWhere('receiver_id', $this->id)
            ->with(['sender', 'receiver', 'latestMessage'])
            ->orderByDesc('updated_at')
            ->get();
    }

    /**
     * Boot method to handle Google Sheets integration
     */
    protected static function boot()
    {
        parent::boot();

        static::created(function ($user) {
            try {
                // Dispatch queued job to add user to Google Sheets
                RecordUserToGoogleSheets::dispatch($user->id)
                    ->delay(now()->addSeconds(1))
                    ->onQueue('gsheets');
            } catch (\Exception $e) {
                // Log error but don't fail user creation
                \Log::error('Failed to dispatch user Google Sheets job during creation', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage()
                ]);
            }
        });
    }
}
