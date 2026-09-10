<?php

declare(strict_types=1);

use App\Enums\UserRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('role', 20)
                ->default(UserRole::Customer->value)
                ->after('email');
        });

        // The enum exists in PHP; this is what makes it true of the data.
        // Without it, a typo in a seeder or a hand-run UPDATE puts a value in
        // the column that no branch in the application matches, and every
        // permission check involving it quietly answers false.
        DB::statement(sprintf(
            "ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('%s'))",
            implode("', '", UserRole::values()),
        ));
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('role');
        });
    }
};
