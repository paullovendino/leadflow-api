<?php

namespace App\Services\Crm;

use App\Enums\ActivityType;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Note;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class NoteService
{
    public function __construct(
        private readonly ActivityService $activities,
    ) {}

    /**
     * @return Collection<int, Note>
     */
    public function listForLead(Lead $lead): Collection
    {
        return $lead->notes()->with('user')->get();
    }

    /**
     * @return Collection<int, Note>
     */
    public function listForCustomer(Customer $customer): Collection
    {
        return $customer->notes()->with('user')->get();
    }

    public function createForLead(User $actor, Lead $lead, string $body): Note
    {
        return $this->createFor($actor, $lead, $body);
    }

    public function createForCustomer(User $actor, Customer $customer, string $body): Note
    {
        return $this->createFor($actor, $customer, $body);
    }

    private function createFor(User $actor, Lead|Customer $noteable, string $body): Note
    {
        return DB::transaction(function () use ($actor, $noteable, $body) {
            $note = $noteable->notes()->create([
                'user_id' => $actor->id,
                'body' => $body,
            ]);

            $this->activities->record(
                $noteable,
                $actor,
                ActivityType::NoteAdded,
                'Added a note',
                ['note_id' => $note->id],
            );

            return $note->load('user');
        });
    }
}
