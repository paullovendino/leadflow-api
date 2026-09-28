<?php

use App\Enums\ActivityType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        $types = collect(ActivityType::cases())
            ->map(fn (ActivityType $type) => "'{$type->value}'")
            ->implode(', ');

        DB::statement('ALTER TABLE activities DROP CONSTRAINT IF EXISTS activities_type_check');
        DB::statement("ALTER TABLE activities ADD CONSTRAINT activities_type_check CHECK (type IN ({$types}))");
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        $types = collect(ActivityType::cases())
            ->reject(fn (ActivityType $type) => $type === ActivityType::LeadQualified)
            ->map(fn (ActivityType $type) => "'{$type->value}'")
            ->implode(', ');

        DB::statement('ALTER TABLE activities DROP CONSTRAINT IF EXISTS activities_type_check');
        DB::statement("ALTER TABLE activities ADD CONSTRAINT activities_type_check CHECK (type IN ({$types}))");
    }
};
