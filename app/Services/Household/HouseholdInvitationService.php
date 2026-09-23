<?php

namespace App\Services\Household;

use App\Exceptions\AlreadyHouseholdMemberException;
use App\Exceptions\InvitationAlreadyAcceptedException;
use App\Exceptions\InvitationAlreadyPendingException;
use App\Exceptions\InvitationExpiredException;
use App\Exceptions\InvitationNotFoundException;
use App\Exceptions\InvitedUserNotFoundException;
use App\Models\Household;
use App\Models\HouseholdInvitation;
use App\Models\User;
use App\Repositories\Contracts\HouseholdInvitationRepositoryInterface;
use App\Repositories\Contracts\HouseholdMemberRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class HouseholdInvitationService
{
    private const EXPIRY_DAYS = 7;

    public function __construct(
        private readonly HouseholdInvitationRepositoryInterface $invitations,
        private readonly HouseholdMemberRepositoryInterface $householdMembers,
        private readonly UserRepositoryInterface $users,
    ) {}

    /**
     * Invite an existing user to a household.
     *
     * @return array{invitation: array{id: string, household: array{id: string, name: string}, email: string, status: string, expires_at: mixed, created_at: mixed}, token: string}
     *
     * @throws InvitedUserNotFoundException
     * @throws AlreadyHouseholdMemberException
     * @throws InvitationAlreadyPendingException
     */
    public function invite(Household $household, User $inviter, string $email): array
    {
        $email = strtolower(trim($email));

        $invitedUser = $this->users->findByEmail($email);

        if ($invitedUser === null) {
            throw new InvitedUserNotFoundException;
        }

        if ($this->householdMembers->findByHouseholdAndUser($household, $invitedUser) !== null) {
            throw new AlreadyHouseholdMemberException;
        }

        if ($this->invitations->findPendingByHouseholdAndEmail($household, $email) !== null) {
            throw new InvitationAlreadyPendingException;
        }

        $token = Str::random(64);

        $invitation = $this->invitations->create([
            'household_id' => $household->id,
            'invited_by' => $inviter->id,
            'email' => $email,
            'token_hash' => hash('sha256', $token),
            'status' => HouseholdInvitation::STATUS_PENDING,
            'expires_at' => now()->addDays(self::EXPIRY_DAYS),
        ]);

        return [
            'invitation' => $this->presentInvitation($invitation),
            'token' => $token,
        ];
    }

    /**
     * List pending invitations addressed to the user's email.
     *
     * @return array<int, array{id: string, household: array{id: string, name: string}, email: string, status: string, expires_at: mixed, created_at: mixed}>
     */
    public function listForUser(User $user): array
    {
        $invitations = $this->invitations->listPendingForEmail(strtolower(trim($user->email)));

        return $invitations->map(fn (HouseholdInvitation $invitation) => $this->presentInvitation($invitation))->all();
    }

    /**
     * Accept an invitation as the invited user.
     *
     * @return array{id: string, user_id: string, username: string, name: string, email: string, role: string, created_at: mixed, updated_at: mixed}
     *
     * @throws InvitationNotFoundException
     * @throws InvitationAlreadyAcceptedException
     * @throws InvitationExpiredException
     * @throws AlreadyHouseholdMemberException
     */
    public function accept(User $user, string $invitationId, string $token): array
    {
        $invitation = $this->invitations->findById($invitationId);

        if ($invitation === null
            || ! hash_equals($invitation->token_hash, hash('sha256', $token))
            || strtolower(trim($user->email)) !== $invitation->email
        ) {
            throw new InvitationNotFoundException;
        }

        if (! $invitation->isPending()) {
            throw new InvitationAlreadyAcceptedException;
        }

        if ($invitation->isExpired()) {
            throw new InvitationExpiredException;
        }

        try {
            $membership = DB::transaction(function () use ($invitation, $user) {
                if ($this->householdMembers->findByHouseholdAndUser($invitation->household, $user) !== null) {
                    throw new AlreadyHouseholdMemberException;
                }

                $created = $this->householdMembers->create([
                    'household_id' => $invitation->household_id,
                    'user_id' => $user->id,
                    'role' => 'member',
                ]);

                $this->invitations->markAsAccepted($invitation);

                return $created;
            });
        } catch (QueryException $exception) {
            if ($this->isUniqueViolation($exception)) {
                throw new AlreadyHouseholdMemberException;
            }

            throw $exception;
        }

        $membership->load('user');

        return [
            'id' => $membership->id,
            'user_id' => $membership->user_id,
            'username' => $membership->user->username,
            'name' => $membership->user->name,
            'email' => $membership->user->email,
            'role' => $membership->role,
            'created_at' => $membership->created_at,
            'updated_at' => $membership->updated_at,
        ];
    }

    /**
     * @return array{id: string, household: array{id: string, name: string}, email: string, status: string, expires_at: mixed, created_at: mixed}
     */
    private function presentInvitation(HouseholdInvitation $invitation): array
    {
        $invitation->loadMissing('household');

        return [
            'id' => $invitation->id,
            'household' => [
                'id' => $invitation->household->id,
                'name' => $invitation->household->name,
            ],
            'email' => $invitation->email,
            'status' => $invitation->status,
            'expires_at' => $invitation->expires_at,
            'created_at' => $invitation->created_at,
        ];
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return in_array($exception->getCode(), ['23000', '23505'], true);
    }
}
