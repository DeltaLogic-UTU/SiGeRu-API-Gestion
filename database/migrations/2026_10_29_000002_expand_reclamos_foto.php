<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('reclamos', 'foto')) {
            DB::statement('ALTER TABLE reclamos MODIFY COLUMN foto LONGTEXT NULL');
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('reclamos', 'foto')) {
            DB::statement('ALTER TABLE reclamos MODIFY COLUMN foto VARCHAR(255) NULL');
        }
    }
};
