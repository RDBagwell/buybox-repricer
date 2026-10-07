<?php

namespace App\Simulator\Delivery;

use App\Simulator\Engine\AnyOfferChanged;

interface NotificationPublisher
{
    public function publish(AnyOfferChanged $event): void;
}
