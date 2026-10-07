<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Repricer tables. Money columns are integer cents. Times named *_at that describe the market
 * (event_time, decided_at, pushed_at, changed_at, last_price_change_at) are MARKET time from the
 * adapter's clock; created_at is wall-clock bookkeeping.
 *
 * Audit tables are append-only, enforced twice: by the models and, on Postgres, by triggers
 * that reject UPDATE and DELETE.
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $appendOnly = ['price_decisions', 'offer_snapshots', 'price_pushes', 'buybox_history', 'duplicate_deliveries'];

    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('asin', 16);
            $table->string('sku', 64)->unique();
            $table->string('title');
            $table->integer('cost');
            $table->integer('fees');
            $table->integer('shipping')->default(0);
            $table->integer('current_price');
            $table->boolean('paused')->default(false);
            $table->timestampTz('last_price_change_at', 3)->nullable();
            $table->timestamps();

            // Every notification is routed by ASIN.
            $table->index('asin');
        });

        Schema::create('pricing_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('strategy', 24);
            $table->integer('offset')->default(1);
            $table->integer('floor');
            $table->integer('ceiling');
            $table->integer('min_margin')->default(0);
            $table->unsignedSmallInteger('max_step_pct')->default(10);
            $table->unsignedInteger('cooldown_sec')->default(300);
            $table->unsignedSmallInteger('min_competitor_rating')->default(0);
            $table->unsignedSmallInteger('max_competitor_handling_days')->default(30);
            $table->string('no_competition', 24)->default('hold');
            $table->timestamps();
        });

        Schema::create('price_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('event_id', 64);
            $table->timestampTz('event_time', 3);
            $table->integer('old_price');
            $table->integer('new_price')->nullable();
            $table->string('outcome', 16);
            $table->string('reason_code', 32)->nullable();
            $table->text('reason');
            $table->jsonb('rule_trace');
            $table->timestampTz('decided_at', 3);
            $table->timestamp('created_at')->nullable();

            // Idempotency: one decision per (product, event), ever.
            $table->unique(['product_id', 'event_id']);
            // Stale-event check ("newest snapshot decided for this product").
            $table->index(['product_id', 'event_time']);
            // Push resume sweep: pending reprices.
            $table->index(['outcome', 'id']);
        });

        Schema::create('offer_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('decision_id')->constrained('price_decisions')->restrictOnDelete();
            $table->string('seller', 64);
            $table->integer('price');
            $table->integer('shipping');
            $table->string('fulfillment', 16);
            $table->unsignedSmallInteger('rating');
            $table->unsignedSmallInteger('handling_days');
            $table->boolean('is_buybox');
            $table->boolean('is_ours')->default(false);
            $table->timestampTz('captured_at', 3);
            $table->index('decision_id');
        });

        Schema::create('price_pushes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('decision_id')->constrained('price_decisions')->restrictOnDelete();
            $table->string('status', 16);
            $table->unsignedSmallInteger('attempts');
            $table->jsonb('api_response')->nullable();
            $table->timestampTz('pushed_at', 3)->nullable();
            $table->timestamp('created_at')->nullable();

            // One row per attempt; also makes a double-recorded attempt impossible.
            $table->unique(['decision_id', 'attempts']);
        });

        Schema::create('buybox_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('winner', 64)->nullable();
            $table->integer('our_price')->nullable();
            $table->timestampTz('changed_at', 3);
            $table->timestamp('created_at')->nullable();
            $table->index(['product_id', 'id']);
        });

        Schema::create('duplicate_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('event_id', 64);
            $table->foreignId('decision_id')->constrained('price_decisions')->restrictOnDelete();
            $table->timestamp('received_at');
            $table->index('product_id');
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 64)->primary();
            $table->text('value');
            $table->timestamps();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION reject_audit_mutation() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'audit table % is append-only (% rejected)', TG_TABLE_NAME, TG_OP;
                END;
                $$ LANGUAGE plpgsql;
            SQL);

            foreach ($this->appendOnly as $table) {
                DB::statement("CREATE TRIGGER {$table}_append_only BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION reject_audit_mutation()");
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
        Schema::dropIfExists('duplicate_deliveries');
        Schema::dropIfExists('buybox_history');
        Schema::dropIfExists('price_pushes');
        Schema::dropIfExists('offer_snapshots');
        Schema::dropIfExists('price_decisions');
        Schema::dropIfExists('pricing_rules');
        Schema::dropIfExists('products');

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS reject_audit_mutation();');
        }
    }
};
