<?php

namespace App\Services\Crm;

use App\Enums\ActivityType;
use App\Enums\AppointmentStatus;
use App\Enums\DayOfWeek;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Service;
use App\Models\StaffAvailability;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class AppointmentService
{
    public function __construct(
        private readonly ActivityService $activities,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Appointment>
     */
    public function paginate(User $actor, array $filters = []): LengthAwarePaginator
    {
        $perPage = min(100, max(1, (int) ($filters['per_page'] ?? 15)));

        return Appointment::query()
            ->with(['customer', 'service', 'staffUser'])
            ->when($actor->isStaff(), function ($query) use ($actor) {
                $query->where(function ($scoped) use ($actor) {
                    $scoped->where('staff_user_id', $actor->id)
                        ->orWhereHas(
                            'customer.leads',
                            fn ($leads) => $leads->where('assigned_user_id', $actor->id),
                        );
                });
            })
            ->when(
                filled($filters['search'] ?? null),
                function ($query) use ($filters) {
                    $search = '%'.$filters['search'].'%';
                    $like = Schema::getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

                    $query->where(function ($matched) use ($search, $like) {
                        $matched->whereHas('customer', function ($customers) use ($search, $like) {
                            $customers->where('name', $like, $search)
                                ->orWhere('email', $like, $search)
                                ->orWhere('phone', $like, $search);
                        })->orWhereHas('staffUser', fn ($staff) => $staff->where('name', $like, $search))
                            ->orWhereHas('service', fn ($services) => $services->where('name', $like, $search));
                    });
                },
            )
            ->when(isset($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->when(isset($filters['staff_user_id']), fn ($query) => $query->where('staff_user_id', $filters['staff_user_id']))
            ->when(isset($filters['customer_id']), fn ($query) => $query->where('customer_id', $filters['customer_id']))
            ->when(isset($filters['service_id']), fn ($query) => $query->where('service_id', $filters['service_id']))
            ->when(isset($filters['date']), fn ($query) => $query->whereDate('scheduled_date', $filters['date']))
            ->when(isset($filters['date_from']), fn ($query) => $query->whereDate('scheduled_date', '>=', $filters['date_from']))
            ->when(isset($filters['date_to']), fn ($query) => $query->whereDate('scheduled_date', '<=', $filters['date_to']))
            ->orderBy('scheduled_date')
            ->orderBy('start_time')
            ->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(User $actor, array $attributes): Appointment
    {
        $customer = Customer::query()->findOrFail($attributes['customer_id']);
        $this->assertCanViewCustomer($actor, $customer);

        $service = $this->resolveBookableService((int) $attributes['service_id']);
        $staff = $this->resolveBookableStaff((int) $attributes['staff_user_id']);
        $date = Carbon::parse($attributes['scheduled_date'])->startOfDay();
        $start = $this->normalizeTime($attributes['start_time']);
        $end = $this->endTimeFor($start, $service->duration_minutes);

        $this->assertNotInPast($date, $start);
        $this->assertFitsAvailability($staff, $date, $start, $end);
        $this->assertNoOverlap($staff, $date, $start, $end);

        return DB::transaction(function () use ($actor, $attributes, $customer, $service, $staff, $date, $start, $end) {
            $appointment = Appointment::query()->create([
                'customer_id' => $customer->id,
                'service_id' => $service->id,
                'staff_user_id' => $staff->id,
                'scheduled_date' => $date->toDateString(),
                'start_time' => $start,
                'end_time' => $end,
                'status' => AppointmentStatus::Scheduled,
                'notes' => $attributes['notes'] ?? null,
            ]);

            $this->activities->record(
                $appointment,
                $actor,
                ActivityType::AppointmentCreated,
                'Appointment scheduled for '.$this->describeWhen($date, $start),
                $this->appointmentMetadata($appointment),
            );

            $this->activities->record(
                $customer,
                $actor,
                ActivityType::AppointmentCreated,
                'Appointment scheduled for '.$service->name.' on '.$date->toFormattedDateString(),
                $this->appointmentMetadata($appointment),
            );

            return $this->detail($appointment);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(User $actor, Appointment $appointment, array $attributes): Appointment
    {
        if (! in_array($appointment->status, [AppointmentStatus::Scheduled, AppointmentStatus::Confirmed], true)) {
            throw ValidationException::withMessages([
                'status' => ['Only scheduled or confirmed appointments can be updated.'],
            ]);
        }

        $schedulingTouched = array_key_exists('scheduled_date', $attributes)
            || array_key_exists('start_time', $attributes)
            || array_key_exists('staff_user_id', $attributes)
            || array_key_exists('service_id', $attributes);

        $service = isset($attributes['service_id'])
            ? $this->resolveBookableService((int) $attributes['service_id'])
            : $appointment->service()->firstOrFail();

        $staff = isset($attributes['staff_user_id'])
            ? $this->resolveBookableStaff((int) $attributes['staff_user_id'])
            : $appointment->staffUser()->firstOrFail();

        $date = Carbon::parse($attributes['scheduled_date'] ?? $appointment->scheduled_date)->startOfDay();
        $start = $this->normalizeTime($attributes['start_time'] ?? $this->normalizeTime((string) $appointment->start_time));
        $end = $this->endTimeFor($start, $service->duration_minutes);
        $previousWhen = $this->describeWhen($appointment->scheduled_date, $this->normalizeTime((string) $appointment->start_time));

        if ($schedulingTouched) {
            $this->assertNotInPast($date, $start);
            $this->assertFitsAvailability($staff, $date, $start, $end);
            $this->assertNoOverlap($staff, $date, $start, $end, $appointment->id);
        }

        return DB::transaction(function () use ($actor, $appointment, $attributes, $service, $staff, $date, $start, $end, $schedulingTouched, $previousWhen) {
            $appointment->update([
                'service_id' => $service->id,
                'staff_user_id' => $staff->id,
                'scheduled_date' => $date->toDateString(),
                'start_time' => $start,
                'end_time' => $end,
                'notes' => array_key_exists('notes', $attributes) ? $attributes['notes'] : $appointment->notes,
            ]);

            if ($schedulingTouched) {
                $this->activities->record(
                    $appointment,
                    $actor,
                    ActivityType::AppointmentUpdated,
                    'Appointment rescheduled from '.$previousWhen.' to '.$this->describeWhen($date, $start),
                    $this->appointmentMetadata($appointment),
                );

                $this->activities->record(
                    $appointment->customer,
                    $actor,
                    ActivityType::AppointmentUpdated,
                    'Appointment rescheduled to '.$this->describeWhen($date, $start),
                    $this->appointmentMetadata($appointment),
                );
            }

            return $this->detail($appointment);
        });
    }

    public function changeStatus(User $actor, Appointment $appointment, AppointmentStatus $status): Appointment
    {
        if (! $appointment->status->canTransitionTo($status)) {
            throw ValidationException::withMessages([
                'status' => ['This appointment cannot move from '.$appointment->status->value.' to '.$status->value.'.'],
            ]);
        }

        $previous = $appointment->status;

        return DB::transaction(function () use ($actor, $appointment, $status, $previous) {
            $appointment->update(['status' => $status]);

            [$type, $description] = match ($status) {
                AppointmentStatus::Confirmed => [ActivityType::AppointmentConfirmed, 'Appointment confirmed'],
                AppointmentStatus::Completed => [ActivityType::AppointmentCompleted, 'Appointment completed'],
                AppointmentStatus::Cancelled => [ActivityType::AppointmentCancelled, 'Appointment cancelled'],
                AppointmentStatus::NoShow => [ActivityType::AppointmentNoShow, 'Appointment marked as no show'],
                default => [ActivityType::AppointmentUpdated, 'Appointment status updated'],
            };

            $metadata = $this->appointmentMetadata($appointment, [
                'previous_status' => $previous->value,
                'new_status' => $status->value,
            ]);

            $this->activities->record($appointment, $actor, $type, $description, $metadata);
            $this->activities->record($appointment->customer, $actor, $type, $description, $metadata);

            return $this->detail($appointment);
        });
    }

    public function detail(Appointment $appointment): Appointment
    {
        return $appointment->load([
            'customer',
            'service',
            'staffUser',
            'activities.user',
        ]);
    }

    /**
     * @return list<array{start_time: string, end_time: string}>
     */
    public function availableSlots(int $staffUserId, string $date, int $serviceId, ?int $ignoreAppointmentId = null): array
    {
        $staff = $this->resolveBookableStaff($staffUserId);
        $service = $this->resolveBookableService($serviceId);
        $scheduled = Carbon::parse($date)->startOfDay();
        $windows = $this->activeWindows($staff, $scheduled);
        $stepMinutes = 30;
        $slots = [];

        foreach ($windows as $window) {
            $cursor = $this->timeToMinutes($this->normalizeTime((string) $window->start_time));
            $windowEnd = $this->timeToMinutes($this->normalizeTime((string) $window->end_time));

            while ($cursor + $service->duration_minutes <= $windowEnd) {
                $start = $this->minutesToTime($cursor);
                $end = $this->endTimeFor($start, $service->duration_minutes);

                if (! $this->hasOverlap($staff, $scheduled, $start, $end, $ignoreAppointmentId)) {
                    $slots[] = [
                        'start_time' => substr($start, 0, 5),
                        'end_time' => substr($end, 0, 5),
                    ];
                }

                $cursor += $stepMinutes;
            }
        }

        return $slots;
    }

    private function resolveBookableService(int $serviceId): Service
    {
        $service = Service::query()->find($serviceId);

        if ($service === null) {
            throw ValidationException::withMessages([
                'service_id' => ['The selected service does not exist.'],
            ]);
        }

        if (! $service->is_active) {
            throw ValidationException::withMessages([
                'service_id' => ['Inactive services cannot be booked.'],
            ]);
        }

        return $service;
    }

    private function resolveBookableStaff(int $staffUserId): User
    {
        $staff = User::query()->find($staffUserId);

        if ($staff === null) {
            throw ValidationException::withMessages([
                'staff_user_id' => ['The selected staff member does not exist.'],
            ]);
        }

        if (! $staff->canBeBooked()) {
            throw ValidationException::withMessages([
                'staff_user_id' => ['Appointments can only be assigned to active staff or manager users.'],
            ]);
        }

        return $staff;
    }

    private function assertCanViewCustomer(User $actor, Customer $customer): void
    {
        if ($actor->cannot('view', $customer)) {
            abort(403);
        }
    }

    private function assertNotInPast(Carbon $date, string $start): void
    {
        $startsAt = Carbon::parse($date->toDateString().' '.$start, config('app.timezone'));

        if ($startsAt->lt(now())) {
            throw ValidationException::withMessages([
                'scheduled_date' => ['Appointments cannot be scheduled in the past.'],
            ]);
        }
    }

    private function assertFitsAvailability(User $staff, Carbon $date, string $start, string $end): void
    {
        $windows = $this->activeWindows($staff, $date);

        if ($windows->isEmpty()) {
            throw ValidationException::withMessages([
                'start_time' => ['This staff member has no availability on the selected date.'],
            ]);
        }

        $fits = $windows->contains(function (StaffAvailability $window) use ($start, $end) {
            $windowStart = $this->normalizeTime((string) $window->start_time);
            $windowEnd = $this->normalizeTime((string) $window->end_time);

            return $windowStart <= $start && $windowEnd >= $end;
        });

        if (! $fits) {
            throw ValidationException::withMessages([
                'start_time' => ['This appointment is outside the staff member\'s availability.'],
            ]);
        }
    }

    private function assertNoOverlap(User $staff, Carbon $date, string $start, string $end, ?int $ignoreId = null): void
    {
        if ($this->hasOverlap($staff, $date, $start, $end, $ignoreId)) {
            throw ValidationException::withMessages([
                'start_time' => ['This appointment overlaps an existing appointment for that staff member.'],
            ]);
        }
    }

    private function hasOverlap(User $staff, Carbon $date, string $start, string $end, ?int $ignoreId = null): bool
    {
        return Appointment::query()
            ->where('staff_user_id', $staff->id)
            ->whereDate('scheduled_date', $date->toDateString())
            ->where('status', '!=', AppointmentStatus::Cancelled->value)
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->where('start_time', '<', $end)
            ->where('end_time', '>', $start)
            ->exists();
    }

    /**
     * @return Collection<int, StaffAvailability>
     */
    private function activeWindows(User $staff, Carbon $date)
    {
        $day = DayOfWeek::from(strtolower($date->englishDayOfWeek));

        return StaffAvailability::query()
            ->where('user_id', $staff->id)
            ->where('day_of_week', $day->value)
            ->active()
            ->orderBy('start_time')
            ->get();
    }

    private function endTimeFor(string $start, int $durationMinutes): string
    {
        $startMinutes = $this->timeToMinutes($start);
        $endMinutes = $startMinutes + $durationMinutes;

        if ($endMinutes > 24 * 60) {
            throw ValidationException::withMessages([
                'start_time' => ['This appointment would extend past midnight.'],
            ]);
        }

        return $this->minutesToTime($endMinutes);
    }

    private function normalizeTime(string $time): string
    {
        $time = substr($time, 0, 8);

        return strlen($time) === 5 ? $time.':00' : $time;
    }

    private function timeToMinutes(string $time): int
    {
        [$hour, $minute] = array_map('intval', explode(':', $this->normalizeTime($time)));

        return ($hour * 60) + $minute;
    }

    private function minutesToTime(int $minutes): string
    {
        $hour = intdiv($minutes, 60);
        $minute = $minutes % 60;

        return sprintf('%02d:%02d:00', $hour, $minute);
    }

    private function describeWhen(Carbon|string $date, string $start): string
    {
        $day = $date instanceof Carbon ? $date : Carbon::parse($date);

        return $day->toFormattedDateString().' at '.substr($this->normalizeTime($start), 0, 5);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function appointmentMetadata(Appointment $appointment, array $extra = []): array
    {
        return array_merge([
            'appointment_id' => $appointment->id,
            'service_id' => $appointment->service_id,
            'staff_user_id' => $appointment->staff_user_id,
        ], $extra);
    }
}
