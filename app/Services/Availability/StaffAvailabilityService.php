<?php

namespace App\Services\Availability;

use App\Enums\DayOfWeek;
use App\Models\StaffAvailability;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class StaffAvailabilityService
{
    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, StaffAvailability>
     */
    public function listForUser(User $owner, array $filters = []): Collection
    {
        return $owner->availabilities()
            ->when(
                array_key_exists('is_active', $filters),
                fn ($query) => $query->where('is_active', $filters['is_active']),
            )
            ->when(
                isset($filters['day_of_week']),
                fn ($query) => $query->where('day_of_week', $filters['day_of_week']),
            )
            ->orderBy('day_of_week')
            ->orderBy('start_time')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(User $owner, array $attributes): StaffAvailability
    {
        $this->assertOwnerCanHaveAvailability($owner);
        $attributes['start_time'] = $this->normalizeTime($attributes['start_time']);
        $attributes['end_time'] = $this->normalizeTime($attributes['end_time']);
        $this->assertNoOverlap(
            $owner,
            DayOfWeek::from($attributes['day_of_week']),
            $attributes['start_time'],
            $attributes['end_time'],
        );

        return $owner->availabilities()->create($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(StaffAvailability $availability, array $attributes): StaffAvailability
    {
        $day = isset($attributes['day_of_week'])
            ? DayOfWeek::from($attributes['day_of_week'])
            : $availability->day_of_week;
        $start = $this->normalizeTime($attributes['start_time'] ?? $availability->start_time);
        $end = $this->normalizeTime($attributes['end_time'] ?? $availability->end_time);
        $isActive = $attributes['is_active'] ?? $availability->is_active;

        if (isset($attributes['start_time'])) {
            $attributes['start_time'] = $start;
        }

        if (isset($attributes['end_time'])) {
            $attributes['end_time'] = $end;
        }

        if ($isActive) {
            $this->assertNoOverlap(
                $availability->user,
                $day,
                $start,
                $end,
                $availability->id,
            );
        }

        $availability->update($attributes);

        return $availability->refresh();
    }

    public function activate(StaffAvailability $availability): StaffAvailability
    {
        $this->assertOwnerCanHaveAvailability($availability->user);
        $this->assertNoOverlap(
            $availability->user,
            $availability->day_of_week,
            $availability->start_time,
            $availability->end_time,
            $availability->id,
        );

        $availability->update(['is_active' => true]);

        return $availability->refresh();
    }

    public function deactivate(StaffAvailability $availability): StaffAvailability
    {
        $availability->update(['is_active' => false]);

        return $availability->refresh();
    }

    private function normalizeTime(string $time): string
    {
        return strlen($time) === 5 ? $time.':00' : $time;
    }

    private function assertOwnerCanHaveAvailability(User $owner): void
    {
        if (! $owner->canHaveAvailability()) {
            throw ValidationException::withMessages([
                'user_id' => ['Availability can only be assigned to active staff or manager users.'],
            ]);
        }
    }

    private function assertNoOverlap(
        User $owner,
        DayOfWeek $day,
        string $startTime,
        string $endTime,
        ?int $ignoreId = null,
    ): void {
        $overlaps = StaffAvailability::query()
            ->where('user_id', $owner->id)
            ->where('day_of_week', $day->value)
            ->active()
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->where('start_time', '<', $endTime)
            ->where('end_time', '>', $startTime)
            ->exists();

        if ($overlaps) {
            throw ValidationException::withMessages([
                'start_time' => ['This availability overlaps an existing working period for that day.'],
            ]);
        }
    }
}
