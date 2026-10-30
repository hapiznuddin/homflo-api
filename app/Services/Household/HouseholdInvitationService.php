<?php

namespace App\Services\Household;

use App\Exceptions\AlreadyHouseholdMemberException;
use App\Exceptions\InvitationAlreadyAcceptedException;
use App\Exceptions\InvitationAlreadyPendingException;
use App\Exceptions\InvitationExpiredException;
use App\Exceptions\InvitationNotFoundException;
use App\Exceptions\InvitationRevokedException;
use App\Jobs\SendHouseholdInvitationEmail;
use App\Models\Household;
use App\Models\HouseholdInvitation;
use App\Models\User;
use App\Repositories\Contracts\HouseholdInvitationRepositoryInterface;
use App\Repositories\Contracts\HouseholdMemberRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class HouseholdInvitationService
{
    private const EXPIRY_DAYS = 7;

    public function __construct(
        private readonly HouseholdInvitationRepositoryInterface $invitations,
        private readonly HouseholdMemberRepositoryInterface $householdMembers,
        private readonly UserRepositoryInterface $users,
    ) {}

    /**
     * Invite an email address to a household.
     *
     * The address does not need an account: the invitation stays bound to the
     * email and can be claimed after registering and verifying that address.
     * Registration and household membership are always checked against
     * server-side data only.
     *
     * @return array{invitation: array{id: string, household: array{id: string, name: string}, email: string, status: string, expires_at: mixed, created_at: mixed}, token: string, email_queued: bool}
     *
     * @throws AlreadyHouseholdMemberException
     * @throws InvitationAlreadyPendingException
     */
    public function invite(Household $household, User $inviter, string $email): array
    {
        $email = strtolower(trim($email));

        $invitedUser = $this->users->findByEmail($email);

        if ($invitedUser !== null
            && $this->householdMembers->findByHouseholdAndUser($household, $invitedUser) !== null
        ) {
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

        // The invitation is already persisted. A queue/transport failure must
        // not roll it back: the owner can still copy the manual link, and the
        // response reports `email_queued: false`.
        $emailQueued = $this->queueEmail($invitation, $token);

        return [
            'invitation' => $this->presentInvitation($invitation),
            'token' => $token,
            'email_queued' => $emailQueued,
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
     * Owner-side invitation list for one household.
     *
     * @return array<int, array{id: string, email: string, status: string, expires_at: mixed, created_at: mixed, accepted_at: mixed, revoked_at: mixed, invited_by: array{id: string, name: string}|null}>
     */
    public function listForHousehold(Household $household, string $status): array
    {
        $invitations = $this->invitations->listForHousehold($household, $status);

        return $invitations->map(fn (HouseholdInvitation $invitation) => $this->presentForOwner($invitation))->all();
    }

    /**
     * Revoke a pending invitation. Revoking an already revoked invitation is
     * idempotent; an accepted invitation can never be revoked.
     *
     * @return array{id: string, status: string, revoked_at: mixed}
     *
     * @throws InvitationNotFoundException
     * @throws InvitationAlreadyAcceptedException
     */
    public function revoke(User $actor, Household $household, string $invitationId): array
    {
        $invitation = $this->invitations->findByIdForHousehold($household, $invitationId);

        // Another household's invitation is masked as missing, never 403.
        if ($invitation === null) {
            throw new InvitationNotFoundException;
        }

        if ($invitation->isAccepted()) {
            throw new InvitationAlreadyAcceptedException;
        }

        if (! $invitation->isRevoked()) {
            $this->invitations->markAsRevoked($invitation, $actor->id);
        }

        return [
            'id' => $invitation->id,
            'status' => HouseholdInvitation::STATUS_REVOKED,
            'revoked_at' => $invitation->revoked_at,
        ];
    }

    /**
     * Accept an invitation as the invited user.
     *
     * @return array{id: string, user_id: string, username: string, name: string, email: string, role: string, created_at: mixed, updated_at: mixed}
     *
     * @throws InvitationNotFoundException
     * @throws InvitationRevokedException
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

        if ($invitation->isRevoked()) {
            throw new InvitationRevokedException;
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

                // Accepting switches the active household: the user joined
                // this household on purpose and must not need a manual switch.
                $user->active_household_id = $invitation->household_id;
                $user->save();

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
     * Queue the email that carries the one-time invitation link.
     *
     * Returns false when the queue rejects the job. The caller keeps the
     * invitation and the manual link either way.
     */
    private function queueEmail(HouseholdInvitation $invitation, string $token): bool
    {
        try {
            dispatch(new SendHouseholdInvitationEmail($invitation->id, $token));

            return true;
        } catch (Throwable $exception) {
            // Never include the token or the job payload in the log line.
            Log::warning('Household invitation email could not be queued.', [
                'invitation_id' => $invitation->id,
                'exception' => $exception::class,
            ]);

            return false;
        }
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

    /**
     * @return array{id: string, email: string, status: string, expires_at: mixed, created_at: mixed, accepted_at: mixed, revoked_at: mixed, invited_by: array{id: string, name: string}|null}
     */
    private function presentForOwner(HouseholdInvitation $invitation): array
    {
        $invitation->loadMissing('inviter');

        return [
            'id' => $invitation->id,
            'email' => $invitation->email,
            'status' => $invitation->presentStatus(),
            'expires_at' => $invitation->expires_at,
            'created_at' => $invitation->created_at,
            'accepted_at' => $invitation->accepted_at,
            'revoked_at' => $invitation->revoked_at,
            'invited_by' => $invitation->inviter === null
                ? null
                : [
                    'id' => $invitation->inviter->id,
                    'name' => $invitation->inviter->name,
                ],
        ];
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return in_array($exception->getCode(), ['23000', '23505'], true);
    }
}
