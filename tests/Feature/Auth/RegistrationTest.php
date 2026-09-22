<?php

use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Repositories\UserRepository;

describe('Registration', function () {
    test('registers a new user with all required fields', function () {
        $response = $this->postJson('/register', [
            'username' => 'johndoe',
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertSuccessful();

        $this->assertDatabaseHas('users', [
            'username' => 'johndoe',
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);
    });

    test('passwords are hashed and never stored as plaintext', function () {
        $this->postJson('/register', [
            'username' => 'johndoe',
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $user = User::where('email', 'john@example.com')->first();

        expect($user->password)->not->toBe('password123');
        expect(password_verify('password123', $user->password))->toBeTrue();
    });

    test('rejects duplicate username', function () {
        User::factory()->create(['username' => 'existinguser']);

        $response = $this->postJson('/register', [
            'username' => 'existinguser',
            'name' => 'Another User',
            'email' => 'another@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['username']);
    });

    test('rejects duplicate email', function () {
        User::factory()->create(['email' => 'existing@example.com']);

        $response = $this->postJson('/register', [
            'username' => 'newuser',
            'name' => 'New User',
            'email' => 'existing@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email']);
    });

    test('rejects missing username', function () {
        $response = $this->postJson('/register', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['username']);
    });

    test('rejects password mismatch', function () {
        $response = $this->postJson('/register', [
            'username' => 'johndoe',
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'password123',
            'password_confirmation' => 'differentpassword',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['password']);
    });

    test('UserRepositoryInterface is bound in the container', function () {
        $repo = app(UserRepositoryInterface::class);

        expect($repo)->toBeInstanceOf(UserRepository::class);
    });
});
