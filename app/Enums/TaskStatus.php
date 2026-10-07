<?php

namespace App\Enums;

enum TaskStatus: string
{
    case Todo = 'todo';
    case Doing = 'doing';
    case Done = 'done';
}
