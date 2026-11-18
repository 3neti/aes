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
        Schema::create('election_research_events', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('run_id')->constrained('election_research_runs');
            $table->unsignedInteger('sequence');
            $table->string('idempotency_key');
            $table->string('ceremony_id');
            $table->string('actor_id');
            $table->char('request_hash', 64);
            $table->json('result');
            $table->char('previous_hash', 64)->nullable();
            $table->char('event_hash', 64);
            $table->timestamps();
            $table->unique(['run_id', 'sequence']);
            $table->unique(['run_id', 'idempotency_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('election_research_events');
    }
};
