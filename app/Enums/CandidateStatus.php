<?php

namespace App\Enums;

enum CandidateStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';
}
