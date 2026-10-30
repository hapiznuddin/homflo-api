<?php

use App\Jobs\SendHouseholdInvitationEmail;
use App\Mail\HouseholdInvitationMail;
use App\Models\Household;
use App\Models\HouseholdInvitation;
use App\Models\HouseholdMember;
use App\Models\User;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

function emailTestHousehold(User $owner, string $name = 'Rumah Email'): Household
{
    $household = Household::create([
        'name' => $name,
        'created_by' => $owner->id,
    ]);

    HouseholdMember::create([
        'household_id' => $household->id,
        'user_id' => $owner->id,
        'role' => 'owner',
    ]);

    return $household->refresh();
}

function emailTestInvite(object $testCase, User $owner, Household $household, string $email): array
{
    $response = $testCase->actingAs($owner)->postJson(
        "/api/households/{$household->id}/invitations",
        ['email' => $email]
    );

    $response->assertStatus(201);

    return [
        'id' => $response->json('data.invitation.id'),
        'token' => $response->json('data.token'),
        'email_queued' => $response->json('data.email_queued'),
    ];
}

describe('Invitation email delivery', function () {
    test('creating an invitation queues exactly one email job and reports email_queued', function () {
        Queue::fake();

        $owner = User::factory()->create();
        $household = emailTestHousehold($owner);
        $invited = User::factory()->create();

        $created = emailTestInvite($this, $owner, $household, $invited->email);

        expect($created['email_queued'])->toBeTrue();

        Queue::assertPushed(SendHouseholdInvitationEmail::class, 1);
        Queue::assertPushed(
            SendHouseholdInvitationEmail::class,
            fn (SendHouseholdInvitationEmail $job) => $job->invitationId === $created['id']
                && $job->token === $created['token']
        );

        $this->assertDatabaseHas('household_invitations', [
            'id' => $created['id'],
            'status' => 'pending',
        ]);
    });

    test('the queued job sends the invitation to the invited address with a frontend link', function () {
        Mail::fake();
        Queue::fake();

        config(['app.frontend_url' => 'https://app.homflo.test']);

        $owner = User::factory()->create();
        $household = emailTestHousehold($owner, 'Rumah Tangga Budi');
        $invited = User::factory()->create();

        $created = emailTestInvite($this, $owner, $household, $invited->email);

        (new SendHouseholdInvitationEmail($created['id'], $created['token']))->handle();

        Mail::assertSent(HouseholdInvitationMail::class, function (HouseholdInvitationMail $mail) use ($created, $invited, $owner) {
            return $mail->hasTo($invited->email)
                && $mail->householdName === 'Rumah Tangga Budi'
                && $mail->inviterName === $owner->name
                && str_starts_with($mail->acceptUrl, 'https://app.homflo.test/invitations/'.$created['id'])
                && str_contains($mail->acceptUrl, 'token='.urlencode($created['token']));
        });
    });

    test('the email does not use the request host for the link', function () {
        Mail::fake();

        config(['app.frontend_url' => 'https://frontend.example']);

        $owner = User::factory()->create();
        $household = emailTestHousehold($owner);
        $invited = User::factory()->create();

        $created = emailTestInvite($this, $owner, $household, $invited->email);

        (new SendHouseholdInvitationEmail($created['id'], $created['token']))->handle();

        Mail::assertSent(HouseholdInvitationMail::class, function (HouseholdInvitationMail $mail) {
            return str_starts_with($mail->acceptUrl, 'https://frontend.example/invitations/');
        });
    });

    test('the job does nothing when the invitation was revoked before delivery', function () {
        Mail::fake();
        Queue::fake();

        $owner = User::factory()->create();
        $household = emailTestHousehold($owner);
        $invited = User::factory()->create();

        $created = emailTestInvite($this, $owner, $household, $invited->email);

        $this->actingAs($owner)->postJson(
            "/api/households/{$household->id}/invitations/{$created['id']}/revoke"
        )->assertOk();

        (new SendHouseholdInvitationEmail($created['id'], $created['token']))->handle();

        Mail::assertNothingSent();
    });

    test('a queue failure keeps the invitation, reports email_queued false, and the token still works', function () {
        Queue::fake();
        Mail::fake();

        app()->instance(Dispatcher::class, new class implements Dispatcher
        {
            public function dispatch($command)
            {
                throw new RuntimeException('queue unavailable');
            }

            public function dispatchSync($command, $handler = null) {}

            public function dispatchNow($command, $handler = null) {}

            public function dispatchAfterResponse($command, $handler = null) {}

            public function chain($jobs = null) {}

            public function hasCommandHandler($command)
            {
                return false;
            }

            public function getCommandHandler($command)
            {
                return null;
            }

            public function pipeThrough(array $pipes)
            {
                return $this;
            }

            public function map(array $map)
            {
                return $this;
            }
        });

        $owner = User::factory()->create();
        $household = emailTestHousehold($owner);
        $invited = User::factory()->create();

        $created = emailTestInvite($this, $owner, $household, $invited->email);

        expect($created['email_queued'])->toBeFalse();
        expect($created['token'])->toBeString()->not->toBeEmpty();

        $this->assertDatabaseHas('household_invitations', [
            'id' => $created['id'],
            'status' => 'pending',
        ]);

        $this->actingAs($invited)->postJson(
            "/api/invitations/{$created['id']}/accept",
            ['token' => $created['token']]
        )->assertStatus(201);

        Mail::assertNothingSent();
    });

    test('invitation creation is throttled to ten attempts per minute', function () {
        Queue::fake();

        $owner = User::factory()->create();
        $household = emailTestHousehold($owner);

        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->actingAs($owner)->postJson(
                "/api/households/{$household->id}/invitations",
                ['email' => "throttle{$attempt}@example.com"]
            )->assertStatus(201);
        }

        $blocked = $this->actingAs($owner)->postJson(
            "/api/households/{$household->id}/invitations",
            ['email' => 'throttle11@example.com']
        );

        $blocked->assertStatus(429);

        expect(HouseholdInvitation::query()
            ->where('household_id', $household->id)
            ->count())->toBe(10);
    });

    test('unverified owner cannot trigger any invitation email', function () {
        Queue::fake();

        $owner = User::factory()->unverified()->create();
        $household = emailTestHousehold($owner);
        $invited = User::factory()->create();

        $response = $this->actingAs($owner)->postJson(
            "/api/households/{$household->id}/invitations",
            ['email' => $invited->email]
        );

        $response->assertForbidden();
        $response->assertJsonPath('error.code', 'EMAIL_NOT_VERIFIED');

        Queue::assertNothingPushed();
    });
});
