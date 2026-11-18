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
        Schema::create('election_research_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('run_id')->constrained('election_research_runs');
            $table->foreignId('event_id')->constrained('election_research_events');
            $table->string('case_type');
            $table->string('case_id');
            $table->string('decision');
            $table->foreignId('reviewer_id')->constrained('users');
            $table->text('rationale');
            $table->json('evidence');
            $table->timestamps();
            $table->index(['run_id', 'case_type', 'case_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('election_research_decisions');
    }
};
