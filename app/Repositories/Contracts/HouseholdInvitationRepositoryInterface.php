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

    public function markAsAccepted(HouseholdInvitation $invitation): HouseholdInvitation;
}
