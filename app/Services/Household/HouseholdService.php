<?php

namespace App\Services\Household;

use App\Exceptions\HouseholdAlreadyExistsException;
use App\Models\User;
use App\Repositories\Contracts\HouseholdMemberRepositoryInterface;
use App\Repositories\Contracts\HouseholdRepositoryInterface;
use Illuminate\Support\Facades\DB;

class HouseholdService
{
    public function __construct(
        private readonly HouseholdMemberRepositoryInterface $householdMembers,
        private readonly HouseholdRepositoryInterface $households,
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

    /**
     * Create exactly one household for a user with no membership.
     *
     * @return array{household: array{id: string, name: string, role: string}, onboarding: array{required: bool}}
     *
     * @throws HouseholdAlreadyExistsException
     */
    public function createHouseholdForUser(User $user, string $name): array
    {
        if ($this->householdMembers->findPrimaryMembershipForUser($user) !== null) {
            throw new HouseholdAlreadyExistsException;
        }

        $membership = DB::transaction(function () use ($user, $name) {
            $household = $this->households->create([
                'name' => $name,
                'created_by' => $user->id,
            ]);

            return $this->householdMembers->create([
                'household_id' => $household->id,
                'user_id' => $user->id,
                'role' => 'owner',
            ]);
        });

        $membership->load('household');

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
