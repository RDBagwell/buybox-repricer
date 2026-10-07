<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Archiving instead of deleting: decisions, pushes and the audit trail point at their product
 * and are append-only, so a product that leaves the catalogue keeps its row (and its history),
 * stamped with the MARKET time it was archived.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->timestampTz('archived_at', 3)->nullable()->after('paused_at');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('archived_at');
        });
    }
};
