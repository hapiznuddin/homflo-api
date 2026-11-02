<?php

namespace App\Services\Household;

use App\Exceptions\HouseholdAlreadyExistsException;
use App\Exceptions\HouseholdNotFoundException;
use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\User;
use App\Models\Wallet;
use App\Repositories\Contracts\HouseholdMemberRepositoryInterface;
use App\Repositories\Contracts\HouseholdRepositoryInterface;
use App\Services\Finance\WalletService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class HouseholdService
{
    /**
     * Every new household starts with exactly one liquid wallet so Money,
     * Bills, and List expenses have a valid posting target immediately.
     */
    private const DEFAULT_WALLET_NAME = 'Cash';

    public function __construct(
        private readonly HouseholdMemberRepositoryInterface $householdMembers,
        private readonly HouseholdRepositoryInterface $households,
        private readonly WalletService $wallets,
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
            // Serialize concurrent creation attempts for the same user.
            // PostgreSQL locks the user row; the membership re-check below
            // then sees any household committed by the winning request.
            // SQLite serializes writes at the database level.
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            if ($this->householdMembers->findPrimaryMembershipForUser($lockedUser) !== null) {
                throw new HouseholdAlreadyExistsException;
            }

            $household = $this->households->create([
                'name' => $name,
                'created_by' => $lockedUser->id,
            ]);

            // Provision the default wallet through the existing WalletService
            // so normalization, activation, and duplicate-name rules stay in
            // one place. The unique (household_id, name) index is the final
            // guard against a second Cash wallet in this household.
            $this->wallets->record($household, [
                'name' => self::DEFAULT_WALLET_NAME,
                'type' => Wallet::TYPE_CASH,
                'opening_balance' => 0,
            ]);

            $this->householdMembers->create([
                'household_id' => $household->id,
                'user_id' => $lockedUser->id,
                'role' => 'owner',
            ]);

            // The first household becomes active immediately so onboarding
            // and every subsequent read agree on the same household.
            $lockedUser->active_household_id = $household->id;
            $lockedUser->save();
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
