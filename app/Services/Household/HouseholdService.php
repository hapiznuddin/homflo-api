<?php

namespace App\Services\Household;

use App\Exceptions\HouseholdAlreadyExistsException;
use App\Exceptions\HouseholdNotFoundException;
use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\User;
use App\Repositories\Contracts\HouseholdMemberRepositoryInterface;
use App\Repositories\Contracts\HouseholdRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class HouseholdService
{
    public function __construct(
        private readonly HouseholdMemberRepositoryInterface $householdMembers,
        private readonly HouseholdRepositoryInterface $households,
    ) {}

    /**
     * Resolve the user's active household, every membership, and onboarding.
     *
     * The active household always comes from the server-side pointer with a
     * deterministic fallback, never from client input. Because every domain
     * read goes through this method, switching households is consistent
     * across all resources at once.
     *
     * @return array{
     *     household: array{id: string, name: string, role: string}|null,
     *     onboarding: array{required: bool}
     * }
     */
    public function resolveUserHouseholdContext(User $user): array
    {
        $membership = $this->householdMembers->findActiveMembershipForUser($user);

        $active = $membership !== null && $membership->household !== null
            ? $this->presentMembership($membership)
            : null;

        return [
            'household' => $active,
            'onboarding' => [
                'required' => $active === null,
            ],
        ];
    }

    /**
     * Every household the user belongs to, ordered deterministically.
     *
     * Kept separate from resolveUserHouseholdContext() so the hot path used by
     * every domain read stays a single query; only /me and the switcher need
     * the full list.
     *
     * @return array<int, array{id: string, name: string, role: string}>
     */
    public function listHouseholdsForUser(User $user): array
    {
        return $this->householdMembers->listForUser($user)
            ->filter(fn (HouseholdMember $item) => $item->household !== null)
            ->map(fn (HouseholdMember $item) => $this->presentMembership($item))
            ->values()
            ->all();
    }

    /**
     * Create exactly one household for a user with no membership.
     *
     * @return array{household: array{id: string, name: string, role: string}, households: array<int, array{id: string, name: string, role: string}>, onboarding: array{required: bool}}
     *
     * @throws HouseholdAlreadyExistsException
     */
    public function createHouseholdForUser(User $user, string $name): array
    {
        if ($this->householdMembers->findPrimaryMembershipForUser($user) !== null) {
            throw new HouseholdAlreadyExistsException;
        }

        DB::transaction(function () use ($user, $name) {
            $household = $this->households->create([
                'name' => $name,
                'created_by' => $user->id,
            ]);

            $this->householdMembers->create([
                'household_id' => $household->id,
                'user_id' => $user->id,
                'role' => 'owner',
            ]);

            // The first household becomes active immediately so onboarding
            // and every subsequent read agree on the same household.
            $user->active_household_id = $household->id;
            $user->save();
        });

        return $this->householdContextWithList($user->refresh());
    }

    /**
     * Make one of the user's memberships the active household.
     *
     * @return array{household: array{id: string, name: string, role: string}, households: array<int, array{id: string, name: string, role: string}>, onboarding: array{required: bool}}
     *
     * @throws HouseholdNotFoundException
     */
    public function activateHouseholdForUser(User $user, Household $household): array
    {
        $membership = $this->householdMembers->findByHouseholdAndUser($household, $user);

        if ($membership === null) {
            throw new HouseholdNotFoundException;
        }

        DB::transaction(function () use ($user, $household) {
            $user->active_household_id = $household->id;
            $user->save();
        });

        return $this->householdContextWithList($user->refresh());
    }

    /**
     * Rename a household. Authorization is enforced by HouseholdPolicy.
     *
     * @return array{household: array{id: string, name: string, role: string}, onboarding: array{required: bool}}
     *
     * @throws ValidationException
     */
    public function renameHousehold(User $user, Household $household, string $name): array
    {
        $name = trim($name);

        if ($name === '') {
            throw ValidationException::withMessages([
                'name' => 'The name field is required.',
            ]);
        }

        $household->update(['name' => $name]);

        $context = $this->resolveUserHouseholdContext($user);

        return [
            'household' => $context['household'],
            'onboarding' => $context['onboarding'],
        ];
    }

    /**
     * @return array{household: array{id: string, name: string, role: string}|null, households: array<int, array{id: string, name: string, role: string}>, onboarding: array{required: bool}}
     */
    private function householdContextWithList(User $user): array
    {
        return [
            ...$this->resolveUserHouseholdContext($user),
            'households' => $this->listHouseholdsForUser($user),
        ];
    }

    /**
     * @return array{id: string, name: string, role: string}
     */
    private function presentMembership(HouseholdMember $membership): array
    {
        return [
            'id' => $membership->household->id,
            'name' => $membership->household->name,
            'role' => $membership->role,
        ];
    }
}
