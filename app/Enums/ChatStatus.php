<?php

namespace App\Enums;

enum ChatStatus: string
{
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';
    case CLOSED = 'closed';

    public function getLabel(): string
    {
        return match($this) {
            self::ACTIVE => 'Активный',
            self::INACTIVE => 'Неактивный',
            self::CLOSED => 'Закрытый',
        };
    }

    public function isActive(): bool
    {
        return $this === self::ACTIVE;
    }
}