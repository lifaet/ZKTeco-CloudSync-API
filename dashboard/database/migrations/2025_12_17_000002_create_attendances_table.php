<?php
// dashboard/database/migrations/2025_12_17_000002_create_attendances_table.php
// MISSING migration — original repo only had users/attendance2/settings.
// Add this file so `php artisan migrate:fresh` creates the PRIMARY attendances table.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('attendances')) return;

        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            // Keep VARCHAR to match device SDK user_id (string). Index for lookups.
            $table->string('user_id', 50)->index();
            $table->dateTime('timestamp')->index();
            $table->string('status', 50)->nullable();
            $table->string('punch', 50)->nullable();
            $table->string('message', 255)->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'timestamp'], 'uq_user_time');
        });
    }
    public function down(): void { Schema::dropIfExists('attendances'); }
};
