<?php

namespace App\Jobs;

use App\Mail\HouseholdInvitationMail;
use App\Models\HouseholdInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendHouseholdInvitationEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    /**
     * The raw token lives only in this job payload until the email is sent.
     * It is never written to application logs.
     */
    public function __construct(
        public readonly string $invitationId,
        public readonly string $token,
    ) {}

    public function handle(): void
    {
        $invitation = HouseholdInvitation::query()
            ->with(['household', 'inviter'])
            ->find($this->invitationId);

        // A revoked, accepted, or deleted invitation must not produce mail,
        // even if the job was queued before the change.
        if ($invitation === null
            || ! $invitation->isPending()
            || $invitation->isExpired()
            || $invitation->household === null
        ) {
            return;
        }

        $acceptUrl = rtrim((string) config('app.frontend_url'), '/')
            .'/invitations/'.$invitation->id
            .'?'.http_build_query(['token' => $this->token]);

        Mail::to($invitation->email)->send(new HouseholdInvitationMail(
            $invitation->household->name,
            $invitation->inviter?->name ?? 'Pemilik rumah tangga',
            $acceptUrl,
            $invitation->expires_at,
        ));
    }
}
