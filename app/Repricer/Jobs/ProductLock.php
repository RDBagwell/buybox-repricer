<?php

namespace App\Repricer\Jobs;

use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Per-product serialisation: a Redis lock (via the cache store) keyed by product and scope.
 *
 *  - "reprice": decisions for one product run one at a time (no racing reads of the latest
 *    decision, stale checks or Buy Box history).
 *  - "push": pushes for one product run one at a time, so an older price can never land after
 *    a newer one.
 *
 * The scopes are separate so a decision can queue its push without the push having to wait
 * for the decision's own lock to be released. A job that cannot get its lock is released and
 * retried a second later; expireAfter bounds how long a crashed worker can hold it.
 */
final class ProductLock
{
    public const REPRICE = 'reprice';

    public const PUSH = 'push';

    public static function for(string $scope, int $productId): WithoutOverlapping
    {
        return (new WithoutOverlapping("{$scope}:product:{$productId}"))
            ->shared()
            ->releaseAfter(1)
            ->expireAfter(60);
    }
}
