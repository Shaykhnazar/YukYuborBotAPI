<?php

namespace App\Models;

use App\Observers\ResponseObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ObservedBy(ResponseObserver::class)]
class Response extends Model
{
    use HasFactory;

    protected $table = 'responses';
    protected $guarded = false;

    // Legacy constants - kept for backward compatibility during migration
    const STATUS_PENDING = 'pending';
    const STATUS_ACCEPTED = 'accepted';
    const STATUS_REJECTED = 'rejected';
    const STATUS_WAITING = 'waiting';
    const STATUS_RESPONDED = 'responded';

    // New dual acceptance statuses
    const DUAL_STATUS_PENDING = 'pending';
    const DUAL_STATUS_ACCEPTED = 'accepted';
    const DUAL_STATUS_REJECTED = 'rejected';

    // Overall status values
    const OVERALL_STATUS_PENDING = 'pending';
    const OVERALL_STATUS_PARTIAL = 'partial';
    const OVERALL_STATUS_ACCEPTED = 'accepted';
    const OVERALL_STATUS_REJECTED = 'rejected';

    const TYPE_MATCHING = 'matching';
    const TYPE_MANUAL = 'manual';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function responder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responder_id', 'id');
    }

    public function chat(): BelongsTo
    {
        return $this->belongsTo(Chat::class, 'chat_id', 'id');
    }

    // Polymorphic relationship to handle both send and delivery requests
    public function request()
    {
        return $this->morphTo();
    }

    // ✅ IMPROVED: Helper methods to get the actual request objects
    public function sendRequest(): BelongsTo
    {
        return $this->belongsTo(SendRequest::class, 'offer_id', 'id');
    }

    public function deliveryRequest(): BelongsTo
    {
        return $this->belongsTo(DeliveryRequest::class, 'offer_id', 'id');
    }

    // ✅ ADD: Helper method to get the request based on type
    public function getRequestAttribute()
    {
        if ($this->offer_type === 'send') {
            return $this->sendRequest;
        } elseif ($this->offer_type === 'delivery') {
            return $this->deliveryRequest;
        }
        return null;
    }

    public function scopePending($query)
    {
        return $query->where('overall_status', self::OVERALL_STATUS_PENDING);
    }

    public function scopeAccepted($query)
    {
        return $query->where('overall_status', self::OVERALL_STATUS_ACCEPTED);
    }

    public function scopePartial($query)
    {
        return $query->where('overall_status', self::OVERALL_STATUS_PARTIAL);
    }

    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeByType($query, $type)
    {
        return $query->where('response_type', $type);
    }

    // ✅ ADD: Helper to check if response has an active chat
    public function hasActiveChat(): bool
    {
        return $this->chat && $this->chat->status === 'active';
    }

    // NEW: Helper methods for dual acceptance system

    /**
     * Get the deliverer user for this response
     */
    public function getDelivererUser()
    {
        // For matching responses, deliverer is always the user (receives notification)
        // For manual responses, logic may differ but currently we use this for matching
        if ($this->response_type === 'matching') {
            return $this->user;
        }
        
        // Legacy manual response logic
        if ($this->offer_type === 'delivery') {
            // DeliveryRequest is being offered, so responder owns the delivery request
            return $this->responder;
        }

        // SendRequest is being offered, so user owns the delivery request
        return $this->user;
    }

    /**
     * Get the sender user for this response
     */
    public function getSenderUser()
    {
        // For matching responses, sender is always the responder (owns the send request)
        // For manual responses, logic may differ but currently we use this for matching
        if ($this->response_type === 'matching') {
            return $this->responder;
        }
        
        // Legacy manual response logic
        if ($this->offer_type === 'send') {
            // SendRequest is being offered, so responder owns the send request
            return $this->responder;
        }

        // DeliveryRequest is being offered, so user owns the send request
        return $this->user;
    }

    /**
     * Get the user's role in this response (sender or deliverer)
     */
    public function getUserRole($userId): string
    {
        $delivererUser = $this->getDelivererUser();
        $senderUser = $this->getSenderUser();

        if ($delivererUser && $delivererUser->id == $userId) {
            return 'deliverer';
        } elseif ($senderUser && $senderUser->id == $userId) {
            return 'sender';
        }

        return 'unknown';
    }

    /**
     * Update status for a specific user
     */
    public function updateUserStatus($userId, $status): bool
    {
        $role = $this->getUserRole($userId);

        if ($role === 'deliverer') {
            $this->deliverer_status = $status;
        } elseif ($role === 'sender') {
            $this->sender_status = $status;
        } else {
            return false;
        }

        // Update overall status based on individual statuses
        $this->updateOverallStatus();

        return $this->save();
    }

    /**
     * Update overall status based on individual user statuses
     */
    private function updateOverallStatus(): void
    {
        if ($this->deliverer_status === self::DUAL_STATUS_REJECTED ||
            $this->sender_status === self::DUAL_STATUS_REJECTED) {
            $this->overall_status = self::OVERALL_STATUS_REJECTED;
        } elseif ($this->deliverer_status === self::DUAL_STATUS_ACCEPTED &&
                  $this->sender_status === self::DUAL_STATUS_ACCEPTED) {
            $this->overall_status = self::OVERALL_STATUS_ACCEPTED;
        } elseif ($this->deliverer_status === self::DUAL_STATUS_ACCEPTED ||
                  $this->sender_status === self::DUAL_STATUS_ACCEPTED) {
            $this->overall_status = self::OVERALL_STATUS_PARTIAL;
        } else {
            $this->overall_status = self::OVERALL_STATUS_PENDING;
        }
    }

    /**
     * Check if user can take action on this response
     */
    public function canUserTakeAction($userId): bool
    {
        // For manual responses, only the request owner (who received the response) can take action
        if ($this->response_type === self::TYPE_MANUAL) {
            // In manual responses, user_id is the request owner (receiver)
            // responder_id is the response sender
            return $this->user_id === $userId && $this->overall_status === self::OVERALL_STATUS_PENDING;
        }

        // For matching responses, use dual acceptance system
        $role = $this->getUserRole($userId);

        if ($role === 'deliverer') {
            return $this->deliverer_status === self::DUAL_STATUS_PENDING;
        } elseif ($role === 'sender') {
            return $this->sender_status === self::DUAL_STATUS_PENDING;
        }

        return false;
    }

    /**
     * Get user's current status for this response
     */
    public function getUserStatus($userId): string
    {
        $role = $this->getUserRole($userId);

        if ($role === 'deliverer') {
            return $this->deliverer_status;
        } elseif ($role === 'sender') {
            return $this->sender_status;
        }

        return 'unknown';
    }

    /**
     * Check if response is fully accepted by both users
     */
    public function isFullyAccepted(): bool
    {
        return $this->overall_status === self::OVERALL_STATUS_ACCEPTED;
    }

    /**
     * Check if response is rejected by either user
     */
    public function isRejected(): bool
    {
        return $this->overall_status === self::OVERALL_STATUS_REJECTED;
    }

    /**
     * Check if response has partial acceptance (one user accepted)
     */
    public function isPartiallyAccepted(): bool
    {
        return $this->overall_status === self::OVERALL_STATUS_PARTIAL;
    }
}
