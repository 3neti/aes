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
        Schema::create('review_room_events', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('review_room_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('sequence');
            $table->string('event_type');
            $table->json('payload');
            $table->string('previous_hash', 64)->nullable();
            $table->string('event_hash', 64)->unique();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->unique(['review_room_id', 'sequence']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('review_room_events');
    }
};
