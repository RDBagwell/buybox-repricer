<?php

namespace App\Repricer\Rules;

enum VetoCode: string
{
    case KillSwitch = 'kill_switch';
    case Paused = 'paused';
    case Cooldown = 'cooldown';
    case ConfigError = 'config_error';
    case NoChange = 'no_change';
    case GuardrailViolation = 'guardrail_violation';
}
