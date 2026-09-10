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
        Schema::create('scanner_scan_events', function (Blueprint $table) {
            $table->id();
            $table->string('station_id')->index();
            $table->string('scan_type')->index();
            $table->string('source')->index();
            $table->longText('payload');
            $table->string('payload_hash')->index();
            $table->string('status')->index();
            $table->string('group_id')->nullable()->index();
            $table->unsignedInteger('part_number')->nullable();
            $table->unsignedInteger('total_parts')->nullable();
            $table->string('precinct_id')->nullable()->index();
            $table->string('document_hash')->nullable()->index();
            $table->string('message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('received_at')->index();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scanner_scan_events');
    }
};
