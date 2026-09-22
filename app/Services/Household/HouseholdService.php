<?php

namespace App\Services\Household;

use App\Models\User;
use App\Repositories\Contracts\HouseholdMemberRepositoryInterface;

class HouseholdService
{
    public function __construct(
        private readonly HouseholdMemberRepositoryInterface $householdMembers,
    ) {}

    /**
     * Resolve the user's active/primary household and onboarding status.
     *
     * @return array{household: array{id: string, name: string, role: string}|null, onboarding: array{required: bool}}
     */
    public function resolveUserHouseholdContext(User $user): array
    {
        $membership = $this->householdMembers->findPrimaryMembershipForUser($user);

        if (! $membership || ! $membership->household) {
            return [
                'household' => null,
                'onboarding' => [
                    'required' => true,
                ],
            ];
        }

        return [
            'household' => [
                'id' => $membership->household->id,
                'name' => $membership->household->name,
                'role' => $membership->role,
            ],
            'onboarding' => [
                'required' => false,
            ],
        ];
    }
}
