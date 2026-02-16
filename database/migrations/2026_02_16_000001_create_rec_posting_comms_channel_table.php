<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rec_posting_comms_channel', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rec_posting_id')->constrained('rec_postings')->cascadeOnDelete();
            $table->foreignId('comms_channel_id')->constrained('comms_channels')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['rec_posting_id', 'comms_channel_id'], 'rec_posting_channel_unique');
            $table->index('comms_channel_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rec_posting_comms_channel');
    }
};
