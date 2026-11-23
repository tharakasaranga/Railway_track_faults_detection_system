<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('videos', 'progress')) {
            Schema::table('videos', function (Blueprint $table) {
                $table->decimal('progress', 5, 2)->default(0)->after('stage');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('videos', 'progress')) {
            Schema::table('videos', function (Blueprint $table) {
                $table->dropColumn('progress');
            });
        }
    }
};
