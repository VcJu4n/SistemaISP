<?php

namespace Tests\Feature;

use App\Models\PasswordHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_can_log_in_and_read_profile(): void
    {
        $user = User::factory()->create(['password' => 'secret-password']);

        $login = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'secret-password',
        ]);

        $token = $login->assertOk()
            ->assertJsonPath('data.user.email', $user->email)
            ->json('data.token');

        $this->assertNotNull($user->tokens()->first()?->expires_at);

        $this->withToken($token)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', $user->email);
    }

    public function test_invalid_credentials_are_rejected(): void
    {
        $user = User::factory()->create(['password' => 'secret-password']);

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'incorrect',
        ])->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'Correo o contraseña incorrectos');
    }

    public function test_unauthenticated_user_cannot_read_profile(): void
    {
        $this->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_logout_revokes_current_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)->postJson('/api/auth/logout')->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_administrator_can_change_password(): void
    {
        $user = User::factory()->create(['password' => 'old-password1']);
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)->putJson('/api/auth/password', [
            'current_password' => 'old-password1',
            'password' => 'New-password2',
            'password_confirmation' => 'New-password2',
        ])->assertOk();

        $this->assertTrue(Hash::check('New-password2', $user->fresh()->password));
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('password_histories', 1);
    }

    public function test_current_password_must_be_correct(): void
    {
        $user = User::factory()->create(['password' => 'old-password1']);
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)->putJson('/api/auth/password', [
            'current_password' => 'incorrect-password',
            'password' => 'New-password2',
            'password_confirmation' => 'New-password2',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');

        $this->assertTrue(Hash::check('old-password1', $user->fresh()->password));
    }

    public function test_login_is_locked_for_fifteen_minutes_after_five_failures(): void
    {
        $user = User::factory()->create(['password' => 'Correct-password1']);

        foreach (range(1, 4) as $attempt) {
            $this->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'incorrect',
            ])->assertUnprocessable();
        }

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'incorrect',
        ])->assertTooManyRequests()
            ->assertJsonPath(
                'errors.email.0',
                'Demasiados intentos fallidos. Intenta nuevamente en 15 minutos.',
            );

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'Correct-password1',
        ])->assertTooManyRequests();
    }

    public function test_last_three_passwords_cannot_be_reused(): void
    {
        $user = User::factory()->create(['password' => 'Current-password3']);
        PasswordHistory::query()->create([
            'user_id' => $user->id,
            'password' => Hash::make('Previous-password2'),
        ]);
        PasswordHistory::query()->create([
            'user_id' => $user->id,
            'password' => Hash::make('Previous-password1'),
        ]);
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)->putJson('/api/auth/password', [
            'current_password' => 'Current-password3',
            'password' => 'Previous-password1',
            'password_confirmation' => 'Previous-password1',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->assertTrue(Hash::check('Current-password3', $user->fresh()->password));
    }

    public function test_new_password_requires_an_uppercase_letter(): void
    {
        $user = User::factory()->create(['password' => 'Current-password1']);
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)->putJson('/api/auth/password', [
            'current_password' => 'Current-password1',
            'password' => 'lowercase-password2',
            'password_confirmation' => 'lowercase-password2',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    }
}
