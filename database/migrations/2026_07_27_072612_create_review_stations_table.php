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
        Schema::create('review_stations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('review_room_id')->constrained()->cascadeOnDelete();
            $table->string('role', 32);
            $table->string('label');
            $table->unsignedTinyInteger('slot');
            $table->text('join_token');
            $table->string('join_token_hash', 64)->unique();
            $table->string('session_id_hash', 64)->nullable();
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['review_room_id', 'role', 'slot']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('review_stations');
    }
};
