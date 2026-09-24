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
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('source')->nullable();
            $table->timestamps();

            $table->index('email');
            $table->index('phone');
            $table->index('source');
            $table->index('created_at');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            $sources = collect(LeadSource::cases())
                ->map(fn (LeadSource $source) => "'{$source->value}'")
                ->implode(', ');

            DB::statement("ALTER TABLE customers ADD CONSTRAINT customers_source_check CHECK (source IS NULL OR source IN ({$sources}))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
