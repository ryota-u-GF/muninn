<?php

namespace App\Enums;

enum AccessPermission: string
{
    case View = 'view';
    case Edit = 'edit';
}
