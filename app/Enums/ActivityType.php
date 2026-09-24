<?php

namespace App\Enums;

enum ActivityType: string
{
    case LeadCreated = 'lead_created';
    case LeadAssigned = 'lead_assigned';
    case StageChanged = 'stage_changed';
    case NoteAdded = 'note_added';
    case LeadUpdated = 'lead_updated';
    case LeadConverted = 'lead_converted';
    case CustomerCreated = 'customer_created';
    case AppointmentCreated = 'appointment_created';
    case AppointmentUpdated = 'appointment_updated';
    case AppointmentConfirmed = 'appointment_confirmed';
    case AppointmentCompleted = 'appointment_completed';
    case AppointmentCancelled = 'appointment_cancelled';
    case AppointmentNoShow = 'appointment_no_show';
}
