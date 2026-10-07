<?php

namespace App\Enums;

enum Role: string
{
    case Admin = 'admin';
    case Employee = 'employee';
    case Contractor = 'contractor';
    case Tv = 'tv';

    public function label(): string
    {
        return match ($this) {
            self::Admin => '管理者',
            self::Employee => '社員',
            self::Contractor => '外注',
            self::Tv => 'TV専用',
        };
    }

    /** 社内メンバー（管理者・社員） */
    public function isInternal(): bool
    {
        return $this === self::Admin || $this === self::Employee;
    }
}
