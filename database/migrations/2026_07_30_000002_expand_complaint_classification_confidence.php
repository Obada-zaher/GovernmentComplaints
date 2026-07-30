<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('complaints') || ! Schema::hasColumn('complaints', 'classification_confidence')) {
            return;
        }

        $driver = DB::getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE complaints MODIFY classification_confidence DECIMAL(5,2) NULL');

            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE complaints ALTER COLUMN classification_confidence TYPE DECIMAL(5,2) USING classification_confidence::DECIMAL(5,2)');

            return;
        }

        Schema::table('complaints', function (Blueprint $table): void {
            $table->decimal('classification_confidence', 5, 2)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Do not narrow this production column: values above 9.9999 could be lost.
    }
};
