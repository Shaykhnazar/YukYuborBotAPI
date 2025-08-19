<?php

namespace App\Enums;

enum ResponseType: string
{
    case MANUAL = 'manual';
    case MATCHING = 'matching';

    public function getLabel(): string
    {
        return match($this) {
            self::MANUAL => 'Ручной отклик',
            self::MATCHING => 'Автоматическое сопоставление',
        };
    }

    public function isManual(): bool
    {
        return $this === self::MANUAL;
    }
}