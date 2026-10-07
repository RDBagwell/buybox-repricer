<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Session 2: offers can be out of stock (Sleeper bot, manual stockouts), and the simulator has
 * runtime controls (running, speed, injected API error rates) that the dashboard can change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sim_offers', function (Blueprint $table) {
            $table->boolean('in_stock')->default(true)->after('handling_days');
        });

        Schema::table('sim_state', function (Blueprint $table) {
            $table->boolean('running')->default(true);
            $table->unsignedSmallInteger('speed')->default(20);
            $table->unsignedSmallInteger('fault_429_bps')->default(0);
            $table->unsignedSmallInteger('fault_503_bps')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('sim_state', function (Blueprint $table) {
            $table->dropColumn(['running', 'speed', 'fault_429_bps', 'fault_503_bps']);
        });
        Schema::table('sim_offers', function (Blueprint $table) {
            $table->dropColumn('in_stock');
        });
    }
};
