<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('processing_jobs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('video_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('status')->default('queued');
            // queued, running, completed, failed

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->decimal('processing_time', 10, 2)->nullable();

            $table->text('error_message')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processing_jobs');
    }
};