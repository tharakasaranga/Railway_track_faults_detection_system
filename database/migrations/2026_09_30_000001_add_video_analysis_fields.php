<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            /*
             * duration already exists in the original schema.
             * This migration only adds it if your existing database
             * does not already contain it.
             */
            if (!Schema::hasColumn('videos', 'duration')) {
                $table->decimal('duration', 10, 2)
                    ->nullable()
                    ->after('file_size');
            }

            if (!Schema::hasColumn('videos', 'confidence')) {
                $table->decimal('confidence', 4, 2)
                    ->default(0.25)
                    ->after('file_size');
            }
        });
    }

    public function down(): void
    {
        /*
         * Do not automatically drop these columns if they were created
         * by older migrations in your project.
         */
    }
};
