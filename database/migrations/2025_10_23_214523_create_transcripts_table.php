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
        Schema::create('transcripts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dictation_session_id')->constrained()->onDelete('cascade');
            $table->enum('type', ['asr_partial', 'asr_final', 'llm_final']);
            $table->longText('text');
            $table->json('meta')->nullable()->comment('Word confidences, alternatives, timestamps');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transcripts');
    }
};
