<?php

namespace Tests\Unit;

use App\Enums\AppointmentStatus;
use PHPUnit\Framework\TestCase;

class AppointmentStatusTest extends TestCase
{
    public function test_scheduled_allows_expected_transitions(): void
    {
        $this->assertTrue(AppointmentStatus::Scheduled->canTransitionTo(AppointmentStatus::Confirmed));
        $this->assertTrue(AppointmentStatus::Scheduled->canTransitionTo(AppointmentStatus::Cancelled));
        $this->assertTrue(AppointmentStatus::Scheduled->canTransitionTo(AppointmentStatus::Completed));
        $this->assertFalse(AppointmentStatus::Scheduled->canTransitionTo(AppointmentStatus::NoShow));
    }

    public function test_confirmed_allows_expected_transitions(): void
    {
        $this->assertTrue(AppointmentStatus::Confirmed->canTransitionTo(AppointmentStatus::Completed));
        $this->assertTrue(AppointmentStatus::Confirmed->canTransitionTo(AppointmentStatus::Cancelled));
        $this->assertTrue(AppointmentStatus::Confirmed->canTransitionTo(AppointmentStatus::NoShow));
        $this->assertFalse(AppointmentStatus::Confirmed->canTransitionTo(AppointmentStatus::Scheduled));
    }

    public function test_terminal_statuses_have_no_transitions(): void
    {
        foreach ([AppointmentStatus::Completed, AppointmentStatus::Cancelled, AppointmentStatus::NoShow] as $status) {
            $this->assertSame([], $status->allowedTransitions());
            $this->assertFalse($status->canTransitionTo(AppointmentStatus::Confirmed));
        }
    }

    public function test_cancelled_does_not_occupy_a_slot(): void
    {
        $this->assertFalse(AppointmentStatus::Cancelled->occupiesSlot());
        $this->assertTrue(AppointmentStatus::Scheduled->occupiesSlot());
        $this->assertTrue(AppointmentStatus::Completed->occupiesSlot());
    }
}
