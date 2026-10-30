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

    /**
     * @return Collection<int, HouseholdInvitation>
     */
    public function listForHousehold(Household $household, string $status): Collection
    {
        $query = HouseholdInvitation::query()
            ->with('inviter')
            ->where('household_id', $household->id);

        match ($status) {
            'accepted' => $query->where('status', HouseholdInvitation::STATUS_ACCEPTED),
            'revoked' => $query->where('status', HouseholdInvitation::STATUS_REVOKED),
            'expired' => $query->where('status', HouseholdInvitation::STATUS_PENDING)
                ->where('expires_at', '<=', now()),
            'pending' => $query->where('status', HouseholdInvitation::STATUS_PENDING)
                ->where('expires_at', '>', now()),
            default => null,
        };

        return $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }

    public function findByIdForHousehold(Household $household, string $invitationId): ?HouseholdInvitation
    {
        return HouseholdInvitation::query()
            ->with('inviter')
            ->where('household_id', $household->id)
            ->where('id', $invitationId)
            ->first();
    }

    public function markAsAccepted(HouseholdInvitation $invitation): HouseholdInvitation
    {
        $invitation->update([
            'status' => HouseholdInvitation::STATUS_ACCEPTED,
            'accepted_at' => now(),
        ]);

        return $invitation->refresh();
    }

    public function markAsRevoked(HouseholdInvitation $invitation, ?string $revokedBy): HouseholdInvitation
    {
        $invitation->update([
            'status' => HouseholdInvitation::STATUS_REVOKED,
            'revoked_at' => $invitation->revoked_at ?? now(),
            'revoked_by' => $invitation->revoked_by ?? $revokedBy,
        ]);

        return $invitation->refresh();
    }

    public function countPendingForHousehold(Household $household): int
    {
        return HouseholdInvitation::query()
            ->where('household_id', $household->id)
            ->where('status', HouseholdInvitation::STATUS_PENDING)
            ->where('expires_at', '>', now())
            ->count();
    }
}
