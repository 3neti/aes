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
        Schema::create('election_research_runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('bundle_id');
            $table->char('bundle_hash', 64)->unique();
            $table->char('assignment_hash', 64);
            $table->char('profile_hash', 64);
            $table->json('allowed_ceremonies');
            $table->string('stage')->nullable();
            $table->char('head_hash', 64)->nullable();
            $table->foreignId('started_by')->nullable()->constrained('users');
            $table->string('artifact_directory');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('election_research_runs');
    }
};
