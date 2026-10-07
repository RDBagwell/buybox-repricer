<?php

namespace App\Repricer\Market;

enum Operation: string
{
    case GetItemOffers = 'getItemOffers';
    case PatchListingsItem = 'patchListingsItem';
}
