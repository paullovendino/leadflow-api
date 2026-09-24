<?php

use App\Enums\AppointmentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('service_id')->constrained()->restrictOnDelete();
            $table->foreignId('staff_user_id')->constrained('users')->restrictOnDelete();
            $table->date('scheduled_date');
            $table->time('start_time');
            $table->time('end_time');
            $table->string('status')->default(AppointmentStatus::Scheduled->value);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('scheduled_date');
            $table->index('status');
            $table->index(['staff_user_id', 'scheduled_date']);
            $table->index(['customer_id', 'scheduled_date']);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            $statuses = collect(AppointmentStatus::cases())
                ->map(fn (AppointmentStatus $status) => "'{$status->value}'")
                ->implode(', ');

            DB::statement("ALTER TABLE appointments ADD CONSTRAINT appointments_status_check CHECK (status IN ({$statuses}))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
