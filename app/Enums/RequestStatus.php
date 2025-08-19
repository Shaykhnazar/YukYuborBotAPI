<?php

namespace App\Enums;

enum RequestStatus: string
{
    case OPEN = 'open';
    case HAS_RESPONSES = 'has_responses';
    case MATCHED = 'matched';
    case MATCHED_MANUALLY = 'matched_manually';
    case COMPLETED = 'completed';
    case CLOSED = 'closed';

    public function getLabel(): string
    {
        return match($this) {
            self::OPEN => 'Открыта',
            self::HAS_RESPONSES => 'Есть отклики',
            self::MATCHED => 'Сопоставлена',
            self::MATCHED_MANUALLY => 'Сопоставлена вручную',
            self::COMPLETED => 'Завершена',
            self::CLOSED => 'Закрыта',
        };
    }

    public function isActive(): bool
    {
        return !in_array($this, [self::COMPLETED, self::CLOSED]);
    }

    public function canBeDeleted(): bool
    {
        return !in_array($this, [self::MATCHED, self::MATCHED_MANUALLY, self::COMPLETED]);
    }

    public function canBeClosed(): bool
    {
        return in_array($this, [self::MATCHED, self::MATCHED_MANUALLY]);
    }
}