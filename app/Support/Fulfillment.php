<?php

namespace App\Support;

/**
 * Who ships the order: the marketplace's own network ("FBA"-style) or the merchant.
 */
enum Fulfillment: string
{
    case Marketplace = 'marketplace';
    case Merchant = 'merchant';
}
