<?php

namespace App\Repositories\Eloquent;

use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class UserRepository extends BaseRepository implements UserRepositoryInterface
{
    public function __construct(User $model)
    {
        parent::__construct($model);
    }

    public function findWithRequestsAndResponses(int $userId, array $relationshipConstraints = []): ?User
    {
        return $this->model->with($this->getRequestRelationships($relationshipConstraints))
            ->find($userId);
    }

    public function loadUserRequestsWithResponses(User $user, array $relationshipConstraints = []): User
    {
        return $user->load($this->getRequestRelationships($relationshipConstraints));
    }

    public function findUserSendRequestsWithResponses(int $userId): Collection
    {
        $user = $this->find($userId);
        if (!$user) {
            return collect();
        }

        return $user->sendRequests()
            ->with([
                'responses.chat',
                'responses.responder.telegramUser',
                'responses.user.telegramUser',
                'manualResponses.chat',
                'manualResponses.responder.telegramUser',
                'manualResponses.user.telegramUser'
            ])
            ->get();
    }

    public function findUserDeliveryRequestsWithResponses(int $userId): Collection
    {
        $user = $this->find($userId);
        if (!$user) {
            return collect();
        }

        return $user->deliveryRequests()
            ->with([
                'responses.chat',
                'responses.responder.telegramUser',
                'responses.user.telegramUser',
                'manualResponses.chat',
                'manualResponses.responder.telegramUser',
                'manualResponses.user.telegramUser'
            ])
            ->get();
    }

    public function findByTelegramId(string $telegramId): ?User
    {
        return $this->model->whereHas('telegramUser', function($query) use ($telegramId) {
            $query->where('telegram', $telegramId);
        })->first();
    }

    private function getRequestRelationships(array $constraints = []): array
    {
        $user = $constraints['user'] ?? null;

        return [
            // Load the main user relationship for each request
            'sendRequests.user.telegramUser',
            'deliveryRequests.user.telegramUser',
            // Load location relationships
            'sendRequests.fromLocation',
            'sendRequests.toLocation',
            'deliveryRequests.fromLocation',
            'deliveryRequests.toLocation',
            // Load response relationships
            'sendRequests.responses' => function ($query) use ($user) {
                if ($user) {
                    $query->where(function($q) use ($user) {
                        $q->where('user_id', $user->id)
                          ->orWhere('responder_id', $user->id);
                    });
                }
            },
            'sendRequests.manualResponses' => function ($query) use ($user) {
                if ($user) {
                    $query->where(function($q) use ($user) {
                        $q->where('user_id', $user->id)
                          ->orWhere('responder_id', $user->id);
                    });
                }
            },
            'sendRequests.responses.chat',
            'sendRequests.responses.responder.telegramUser',
            'sendRequests.responses.user.telegramUser',
            'sendRequests.manualResponses.chat',
            'sendRequests.manualResponses.responder.telegramUser',
            'sendRequests.manualResponses.user.telegramUser',
            'deliveryRequests.responses' => function ($query) use ($user) {
                if ($user) {
                    $query->where(function($q) use ($user) {
                        $q->where('user_id', $user->id)
                          ->orWhere('responder_id', $user->id);
                    });
                }
            },
            'deliveryRequests.manualResponses' => function ($query) use ($user) {
                if ($user) {
                    $query->where(function($q) use ($user) {
                        $q->where('user_id', $user->id)
                          ->orWhere('responder_id', $user->id);
                    });
                }
            },
            'deliveryRequests.responses.chat',
            'deliveryRequests.responses.responder.telegramUser',
            'deliveryRequests.responses.user.telegramUser',
            'deliveryRequests.manualResponses.chat',
            'deliveryRequests.manualResponses.responder.telegramUser',
            'deliveryRequests.manualResponses.user.telegramUser',
            // Matched requests
            'deliveryRequests.matchedSend',
            'deliveryRequests.matchedSend.fromLocation',
            'deliveryRequests.matchedSend.toLocation',
            'sendRequests.matchedDelivery',
            'sendRequests.matchedDelivery.fromLocation',
            'sendRequests.matchedDelivery.toLocation',
        ];
    }
}
