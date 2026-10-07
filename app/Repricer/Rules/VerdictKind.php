<?php

namespace App\Repricer\Rules;

enum VerdictKind: string
{
    case Propose = 'propose';
    case Pass = 'pass';
    case Veto = 'veto';
}
