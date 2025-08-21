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

        /*Schema::table('webhook_configurations', function (Blueprint $table) {
            $table->dropColumn('events');
        });*/ // TODO: convert old format

        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });
        Schema::create('webhook_configurations_events', function (Blueprint $table) {
            $table->foreignId('event_id')->references('id')->on('webhook_events')->onDelete('cascade');
            $table->foreignId('configuration_id')->references('id')->on('webhook_configurations')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('webhook_configurations_events');
    }
};
