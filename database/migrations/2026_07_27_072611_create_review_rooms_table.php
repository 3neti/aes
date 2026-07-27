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
        Schema::create('review_rooms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 12)->unique();
            $table->string('name');
            $table->string('precinct_id')->nullable()->index();
            $table->string('run_id')->nullable()->index();
            $table->string('status')->default('open')->index();
            $table->unsignedTinyInteger('voter_station_count');
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('review_rooms');
    }
};
