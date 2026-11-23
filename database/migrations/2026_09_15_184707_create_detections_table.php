<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('detections', function (Blueprint $table) {
            $table->id();

            $table->foreignId('video_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->unsignedInteger('frame_number')->nullable();

            $table->string('class_name');
            $table->unsignedInteger('class_id')->nullable();

            $table->decimal('confidence', 8, 6);

            $table->decimal('x1', 10, 2);
            $table->decimal('y1', 10, 2);
            $table->decimal('x2', 10, 2);
            $table->decimal('y2', 10, 2);

            $table->string('risk_level')->nullable();
            // low, medium, high

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('detections');
    }
};