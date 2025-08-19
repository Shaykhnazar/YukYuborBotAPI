<?php

namespace App\Enums;

enum DualStatus: string
{
    case PENDING = 'pending';
    case ACCEPTED = 'accepted';
    case REJECTED = 'rejected';

    public function getLabel(): string
    {
        return match($this) {
            self::PENDING => 'Ожидает',
            self::ACCEPTED => 'Принял',
            self::REJECTED => 'Отклонил',
        };
    }
}