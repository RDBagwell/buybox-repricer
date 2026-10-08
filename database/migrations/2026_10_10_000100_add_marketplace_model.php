<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two kinds of marketplace. "buybox": sellers share one listing and compete for a single
 * featured offer (Amazon-style). "open": every seller has their own listing and there is no
 * box to win; what matters is our price against comparable listings (social-commerce style).
 * The simulator knows the model of each listing; the catalogue knows each product's channel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sim_listings', function (Blueprint $table) {
            $table->string('model', 16)->default('buybox')->after('title');
        });
        Schema::table('products', function (Blueprint $table) {
            $table->string('channel', 16)->default('buybox')->after('asin');
        });
    }

    public function down(): void
    {
        Schema::table('sim_listings', fn (Blueprint $table) => $table->dropColumn('model'));
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('channel'));
    }
};
