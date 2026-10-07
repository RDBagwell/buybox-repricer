<?php

use App\Demo\Events\WorldReset;
use App\Models\User;
use App\Repricer\Events\BuyBoxChanged;
use App\Repricer\Events\DecisionRecorded;
use App\Repricer\Events\PricePushed;
use App\Repricer\Events\ProductUpdated;
use App\Repricer\Events\SettingsChanged;
use App\Repricer\Jobs\PushPriceJob;
use App\Repricer\Pricing\RepricingService;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\Factory;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Market;

beforeEach(function () {
    Market::seed();
    Queue::fake([PushPriceJob::class]);
});

/** Recursively collect every key in a payload. */
function allKeys(array $a): array
{
    $keys = [];
    foreach ($a as $k => $v) {
        if (is_string($k)) {
            $keys[] = $k;
        }
        if (is_array($v)) {
            $keys = [...$keys, ...allKeys($v)];
        }
    }

    return array_values(array_unique($keys));
}

const FORBIDDEN_KEYS = ['event_id', 'receipt_handle', 'api_response', 'submission_id', 'idempotency_key', 'password', 'email', 'remember_token', 'created_at', 'updated_at', 'rating', 'handling_days', 'bot_params', 'bot_memory'];

it('broadcasts a decision as presenter data only', function () {
    $d = app(RepricingService::class)->handle(Market::product(), Market::notification());
    $payload = (new DecisionRecorded($d->id, $d->product_id, $d->outcome))->broadcastWith();

    expect(array_keys($payload))->toBe(['id', 'product_id', 'outcome', 'reason_code', 'reason', 'old_price', 'new_price', 'event_time', 'decided_at', 'trace', 'offers', 'push'])
        ->and(array_keys($payload['trace'][0]))->toBe(['rule', 'verdict', 'reason', 'price_before', 'price_after', 'code'])
        ->and(array_keys($payload['offers'][0]))->toBe(['seller', 'price', 'shipping', 'landed', 'is_buybox', 'is_ours'])
        ->and(array_intersect(allKeys($payload), FORBIDDEN_KEYS))->toBe([])
        ->and($payload['new_price'])->toBeInt(); // integer cents, never floats
});

it('broadcasts product, push, buy box, settings and reset events without internals', function () {
    $id = Market::product()->id;
    foreach ([
        (new ProductUpdated($id))->broadcastWith(),
        (new PricePushed(1, $id, 'succeeded', 1))->broadcastWith(),
        (new BuyBoxChanged($id, 'A', 'B', false))->broadcastWith(),
        (new SettingsChanged)->broadcastWith(),
        (new WorldReset)->broadcastWith(),
    ] as $payload) {
        expect(array_intersect(allKeys($payload), FORBIDDEN_KEYS))->toBe([]);
    }

    expect(array_keys((new SettingsChanged)->broadcastWith()))->toBe(['kill_switch', 'dry_run']);
});

it('uses a public channel only in demo mode and a private one for operators', function () {
    config(['demo.enabled' => true]);
    expect((new SettingsChanged)->broadcastOn())->toBeInstanceOf(Channel::class)->not->toBeInstanceOf(PrivateChannel::class);
    config(['demo.enabled' => false]);
    expect((new SettingsChanged)->broadcastOn())->toBeInstanceOf(PrivateChannel::class);
});

it('queues broadcasts on their own queue so Reverb trouble cannot fail repricing', function () {
    expect((new SettingsChanged)->broadcastQueue())->toBe('broadcasts');
});

it('authorises the private dashboard channel for logged-in users only', function () {
    config(['demo.enabled' => false, 'broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'k', 'broadcasting.connections.reverb.secret' => 's', 'broadcasting.connections.reverb.app_id' => 'a']);
    app()->forgetInstance(Factory::class);
    app(BroadcastManager::class)->purge();
    require base_path('routes/channels.php');

    $this->postJson('/broadcasting/auth', ['socket_id' => '1.1', 'channel_name' => 'private-dashboard'])->assertForbidden();
    $this->actingAs(User::factory()->create())
        ->postJson('/broadcasting/auth', ['socket_id' => '1.1', 'channel_name' => 'private-dashboard'])->assertOk();
});
