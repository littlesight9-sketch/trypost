<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\Invite;
use App\Models\User;
use App\Models\Workspace;

beforeEach(fn () => config()->set('trypost.self_hosted', false));

function createTestInvite(string $email): Invite
{
    $account = Account::factory()->create();
    $owner = User::factory()->create(['account_id' => $account->id]);
    $account->update(['owner_id' => $owner->id]);
    $workspace = Workspace::factory()->create([
        'account_id' => $account->id,
        'user_id' => $owner->id,
    ]);

    return Invite::factory()->create([
        'account_id' => $account->id,
        'invited_by' => $owner->id,
        'email' => $email,
        'workspaces' => [$workspace->id],
    ]);
}

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertOk();
});

test('new users can register', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => strongPassword(),
        'locale' => 'en',
    ]);

    $response->assertSessionHasNoErrors();
    $this->assertAuthenticated();
    $response->assertRedirect(route('app.welcome', absolute: false));
});

test('new users get a default workspace on registration', function () {
    $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => strongPassword(),
        'locale' => 'en',
    ]);

    $user = User::where('email', 'test@example.com')->first();

    expect($user)->not->toBeNull();
    expect($user->account_id)->not->toBeNull();
    expect($user->workspaces()->count())->toBe(1);
    expect($user->workspaces()->first()->name)->toBe("Test User's Workspace");
    expect($user->current_workspace_id)->toBe($user->workspaces()->first()->id);
});

test('new users do not have verified email by default', function () {
    $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => strongPassword(),
        'locale' => 'en',
    ]);

    $user = User::where('email', 'test@example.com')->first();

    expect($user->email_verified_at)->toBeNull();
});

test('new users registering via invite have verified email automatically', function () {
    $account = Account::factory()->create();
    $owner = User::factory()->create(['account_id' => $account->id]);
    $account->update(['owner_id' => $owner->id]);
    $workspace = Workspace::factory()->create([
        'account_id' => $account->id,
        'user_id' => $owner->id,
    ]);
    $invite = Invite::factory()->create([
        'account_id' => $account->id,
        'invited_by' => $owner->id,
        'email' => 'test@example.com',
        'workspaces' => [$workspace->id],
    ]);

    $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => strongPassword(),
        'invite' => $invite->id,
        'locale' => 'en',
    ]);

    $user = User::where('email', 'test@example.com')->first();

    expect($user->email_verified_at)->not->toBeNull();
});

test('register page returns 404 when self_hosted, an account exists and no pending invite in session', function () {
    config()->set('trypost.self_hosted', true);
    User::factory()->create();

    $response = $this->get(route('register'));

    $response->assertNotFound();
});

test('register POST returns 404 when self_hosted, an account exists and no pending invite in session', function () {
    config()->set('trypost.self_hosted', true);
    User::factory()->create();

    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => strongPassword(),
        'locale' => 'en',
    ]);

    $response->assertNotFound();
    expect(User::where('email', 'test@example.com')->exists())->toBeFalse();
});

test('register page renders when self_hosted but session has pending invite', function () {
    config()->set('trypost.self_hosted', true);
    $invite = createTestInvite('invitee@example.com');

    $response = $this
        ->withSession(['pending_invite_id' => $invite->id])
        ->get(route('register'));

    $response->assertOk();
});

test('register page renders when self_hosted with invite query param and persists it to session', function () {
    config()->set('trypost.self_hosted', true);
    $invite = createTestInvite('invitee@example.com');

    $response = $this->get(route('register', ['invite' => $invite->id]));

    $response->assertOk();
    $response->assertSessionHas('pending_invite_id', $invite->id);
});

test('signup clears pending_invite_id from session', function () {
    config()->set('trypost.self_hosted', true);
    $invite = createTestInvite('invitee@example.com');

    $this->withSession(['pending_invite_id' => $invite->id])
        ->post(route('register.store'), [
            'name' => 'Invitee',
            'email' => 'invitee@example.com',
            'password' => strongPassword(),
            'locale' => 'en',
        ]);

    expect(session('pending_invite_id'))->toBeNull();
    expect(User::where('email', 'invitee@example.com')->exists())->toBeTrue();
});

test('register POST passes when self_hosted with invite query param even without prior session', function () {
    config()->set('trypost.self_hosted', true);
    $invite = createTestInvite('invitee@example.com');

    $response = $this->post(route('register.store', ['invite' => $invite->id]), [
        'name' => 'Invitee',
        'email' => 'invitee@example.com',
        'password' => strongPassword(),
        'locale' => 'en',
    ]);

    $response->assertSessionHasNoErrors();
    expect(User::where('email', 'invitee@example.com')->exists())->toBeTrue();
});

test('register works normally when not self_hosted even with pending invite in session', function () {
    config()->set('trypost.self_hosted', false);

    $response = $this
        ->withSession(['pending_invite_id' => 'invite-abc'])
        ->post(route('register.store'), [
            'name' => 'Invitee',
            'email' => 'invitee@example.com',
            'password' => strongPassword(),
            'locale' => 'en',
        ]);

    $response->assertSessionHasNoErrors();
    expect(User::where('email', 'invitee@example.com')->exists())->toBeTrue();
    // Cleared regardless of mode, since signup consumes the marker.
    expect(session('pending_invite_id'))->toBeNull();
});

test('the first account can register on a self-hosted instance', function () {
    config()->set('trypost.self_hosted', true);

    $this->get(route('register'))->assertOk();

    $response = $this->post(route('register.store'), [
        'name' => 'Owner',
        'email' => 'owner@example.com',
        'password' => strongPassword(),
        'locale' => 'en',
    ]);

    $response->assertSessionHasNoErrors();
    expect(User::where('email', 'owner@example.com')->exists())->toBeTrue();
});

test('registration on a self-hosted instance needs an invite once the first account exists', function () {
    config()->set('trypost.self_hosted', true);
    User::factory()->create();

    $response = $this->post(route('register.store'), [
        'name' => 'Late User',
        'email' => 'late@example.com',
        'password' => strongPassword(),
        'locale' => 'en',
    ]);

    $response->assertNotFound();
    expect(User::where('email', 'late@example.com')->exists())->toBeFalse();
});
