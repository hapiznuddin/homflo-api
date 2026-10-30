<?php

namespace App\Repositories\Contracts;

use App\Models\Household;
use App\Models\HouseholdInvitation;
use Illuminate\Database\Eloquent\Collection;

interface HouseholdInvitationRepositoryInterface
{
    public function create(array $data): HouseholdInvitation;

    public function findById(string $id): ?HouseholdInvitation;

    public function findPendingByHouseholdAndEmail(Household $household, string $email): ?HouseholdInvitation;

    /**
     * @return Collection<int, HouseholdInvitation>
     */
    public function listPendingForEmail(string $email): Collection;

    public function countPendingForHousehold(Household $household): int;

    /**
     * Owner-side list, filtered by presentation status
     * (pending|accepted|revoked|expired|all).
     *
     * @return Collection<int, HouseholdInvitation>
     */
    public function listForHousehold(Household $household, string $status): Collection;

    public function findByIdForHousehold(Household $household, string $invitationId): ?HouseholdInvitation;

    public function markAsAccepted(HouseholdInvitation $invitation): HouseholdInvitation;

    public function markAsRevoked(HouseholdInvitation $invitation, ?string $revokedBy): HouseholdInvitation;
}
