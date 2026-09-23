<?php

namespace App\Repositories;

use App\Models\Household;
use App\Models\HouseholdInvitation;
use App\Repositories\Contracts\HouseholdInvitationRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class HouseholdInvitationRepository implements HouseholdInvitationRepositoryInterface
{
    public function create(array $data): HouseholdInvitation
    {
        return HouseholdInvitation::query()->create($data);
    }

    public function findById(string $id): ?HouseholdInvitation
    {
        return HouseholdInvitation::query()->find($id);
    }

    public function findPendingByHouseholdAndEmail(Household $household, string $email): ?HouseholdInvitation
    {
        return HouseholdInvitation::query()
            ->where('household_id', $household->id)
            ->where('email', $email)
            ->where('status', HouseholdInvitation::STATUS_PENDING)
            ->where('expires_at', '>', now())
            ->first();
    }

    /**
     * @return Collection<int, HouseholdInvitation>
     */
    public function listPendingForEmail(string $email): Collection
    {
        return HouseholdInvitation::query()
            ->with('household')
            ->where('email', $email)
            ->where('status', HouseholdInvitation::STATUS_PENDING)
            ->where('expires_at', '>', now())
            ->orderByDesc('created_at')
            ->get();
    }

    public function markAsAccepted(HouseholdInvitation $invitation): HouseholdInvitation
    {
        $invitation->update([
            'status' => HouseholdInvitation::STATUS_ACCEPTED,
            'accepted_at' => now(),
        ]);

        return $invitation->refresh();
    }
}
