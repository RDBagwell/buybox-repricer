<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An append-only log of operator and safety actions (rule edits, pauses, resumes, circuit-breaker
 * trips, global switches), plus why a product is paused.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_log', function (Blueprint $table) {
            $table->id();
            $table->string('action', 48);
            $table->foreignId('product_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('actor', 128);
            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();
            $table->text('reason')->nullable();
            $table->timestampTz('market_time', 3)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['product_id', 'id']);
            $table->index(['action', 'id']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->text('paused_reason')->nullable()->after('paused');
            $table->timestampTz('paused_at', 3)->nullable()->after('paused_reason');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE TRIGGER audit_log_append_only BEFORE UPDATE OR DELETE ON audit_log FOR EACH ROW EXECUTE FUNCTION reject_audit_mutation()');
        }
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['paused_reason', 'paused_at']);
        });
        Schema::dropIfExists('audit_log');
    }
};
