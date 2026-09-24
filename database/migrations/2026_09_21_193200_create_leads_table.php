<?php

use App\Enums\LeadSource;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source')->nullable();
            $table->text('message')->nullable();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('pipeline_stage_id')->constrained('pipeline_stages')->restrictOnDelete();
            $table->timestamps();

            $table->index('source');
            $table->index('email');
            $table->index('phone');
            $table->index('created_at');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            $sources = collect(LeadSource::cases())
                ->map(fn (LeadSource $source) => "'{$source->value}'")
                ->implode(', ');

            DB::statement("ALTER TABLE leads ADD CONSTRAINT leads_source_check CHECK (source IS NULL OR source IN ({$sources}))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
