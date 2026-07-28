<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('garments', function (Blueprint $table) {
            // Client-generated idempotency key (the mobile app's local garment id).
            // Unique per user so a retried POST /garments returns the existing row
            // instead of creating a duplicate. Nullable: older clients don't send it.
            $table->string('client_ref')->nullable()->after('user_id');
            $table->unique(['user_id', 'client_ref']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('garments', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'client_ref']);
            $table->dropColumn('client_ref');
        });
    }
};
