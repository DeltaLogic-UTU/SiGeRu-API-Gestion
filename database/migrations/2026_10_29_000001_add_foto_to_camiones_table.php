<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('camiones', 'foto')) {
            Schema::table('camiones', function (Blueprint $table) {
                $table->longText('foto')->nullable()->after('gps');
            });
        } else {
            DB::statement('ALTER TABLE camiones MODIFY COLUMN foto LONGTEXT NULL');
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('camiones', 'foto')) {
            Schema::table('camiones', function (Blueprint $table) {
                $table->dropColumn('foto');
            });
        }
    }
};
