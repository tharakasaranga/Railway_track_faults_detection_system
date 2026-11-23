<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            if (!Schema::hasColumn('videos', 'stage')) {
                $table->string('stage')->nullable()->after('status');
            }

            if (!Schema::hasColumn('videos', 'fps')) {
                $table->decimal('fps', 8, 2)->nullable()->after('processing_time');
            }

            if (!Schema::hasColumn('videos', 'eta_seconds')) {
                $table->unsignedInteger('eta_seconds')->nullable()->after('fps');
            }
        });
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            if (Schema::hasColumn('videos', 'eta_seconds')) {
                $table->dropColumn('eta_seconds');
            }

            if (Schema::hasColumn('videos', 'fps')) {
                $table->dropColumn('fps');
            }

            if (Schema::hasColumn('videos', 'stage')) {
                $table->dropColumn('stage');
            }
        });
    }
};
