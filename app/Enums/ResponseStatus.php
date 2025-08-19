<?php

namespace App\Enums;

enum ResponseStatus: string
{
    case PENDING = 'pending';
    case PARTIAL = 'partial';
    case ACCEPTED = 'accepted';
    case REJECTED = 'rejected';
    case CLOSED = 'closed';

    public function getLabel(): string
    {
        return match($this) {
            self::PENDING => 'Ожидает',
            self::PARTIAL => 'Частично принята',
            self::ACCEPTED => 'Принята',
            self::REJECTED => 'Отклонена',
            self::CLOSED => 'Закрыта',
        };
    }

    public function isActive(): bool
    {
        return in_array($this, [self::PENDING, self::PARTIAL, self::ACCEPTED]);
    }
}