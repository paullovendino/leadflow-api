<?php

use App\Enums\ActivityType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->morphs('activityable');
            $table->string('type');
            $table->string('description');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('created_at');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            $types = collect(ActivityType::cases())
                ->map(fn (ActivityType $type) => "'{$type->value}'")
                ->implode(', ');

            DB::statement("ALTER TABLE activities ADD CONSTRAINT activities_type_check CHECK (type IN ({$types}))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('activities');
    }
};
