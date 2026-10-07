<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Simulator tables. Everything the marketplace stand-in knows lives under the sim_ prefix;
 * the simulator never reads repricer tables. Money is integer cents; market time is epoch ms.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sim_state', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary(); // single row, id = 1
            $table->bigInteger('seed');
            $table->text('rng_state');
            $table->unsignedBigInteger('tick')->default(0);
            $table->bigInteger('clock_ms');
            $table->unsignedInteger('run')->default(1);
            $table->timestamps();
        });

        Schema::create('sim_listings', function (Blueprint $table) {
            $table->string('asin', 16)->primary();
            $table->string('title');
            $table->string('buybox_seller_id', 64)->nullable();
            $table->timestamps();
        });

        Schema::create('sim_offers', function (Blueprint $table) {
            $table->id();
            $table->string('asin', 16);
            $table->string('seller_id', 64);
            $table->string('sku', 64)->nullable();
            $table->integer('price');
            $table->integer('shipping')->default(0);
            $table->string('fulfillment', 16);
            $table->unsignedSmallInteger('rating');
            $table->unsignedSmallInteger('handling_days');
            $table->string('bot', 32)->nullable();
            $table->jsonb('bot_params')->default('{}');
            $table->jsonb('bot_memory')->default('{}');
            $table->timestamps();

            $table->foreign('asin')->references('asin')->on('sim_listings')->cascadeOnDelete();
            $table->unique(['asin', 'seller_id']);
            // Price updates arrive by (seller, sku), like a listings API.
            $table->unique(['seller_id', 'sku']);
        });

        // Every emitted AnyOfferChanged, in order: the replayable notification log.
        Schema::create('sim_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->unsignedInteger('run');
            $table->string('asin', 16);
            $table->unsignedBigInteger('tick');
            $table->bigInteger('market_time_ms');
            $table->jsonb('payload');
            $table->timestamp('created_at')->nullable();
            $table->index(['asin', 'id']);
        });

        // Applied price updates keyed by the caller's idempotency key: a retried request is
        // answered from here and never applied twice.
        Schema::create('sim_price_requests', function (Blueprint $table) {
            $table->id();
            $table->string('idempotency_key', 128)->unique();
            $table->string('seller_id', 64);
            $table->string('sku', 64);
            $table->integer('price');
            $table->bigInteger('applied_at_ms');
            $table->jsonb('response');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sim_price_requests');
        Schema::dropIfExists('sim_events');
        Schema::dropIfExists('sim_offers');
        Schema::dropIfExists('sim_listings');
        Schema::dropIfExists('sim_state');
    }
};
