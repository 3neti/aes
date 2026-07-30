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
        Schema::create('simulation_precincts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('simulation_round_id')->constrained()->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('clustered_precinct', 32);
            $table->string('district')->nullable();
            $table->string('label');
            $table->string('city_municipality')->nullable();
            $table->string('province')->nullable();
            $table->string('status', 24)->default('ready');
            $table->string('officer_name');
            $table->string('officer_code', 32);
            $table->string('officer_pin_hash');
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['simulation_round_id', 'code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('simulation_precincts');
    }
};
