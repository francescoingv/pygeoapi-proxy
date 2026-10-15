<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('settings entry points redirect to profile settings', function (string $uri, string $method) {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->call($method, $uri)
        ->assertRedirect(route('profile.edit'));
})->with([
    'settings' => '/settings',
    'security' => '/settings/security',
])->with(['GET', 'HEAD']);

test('settings redirects reject unsupported query requests', function (string $uri) {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->call('QUERY', $uri)
        ->assertMethodNotAllowed();
})->with([
    'settings' => '/settings',
    'security' => '/settings/security',
]);

test('password can be updated', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from(route('security.edit'))
        ->put(route('user-password.update'), [
            'current_password' => 'password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('security.edit'));

    expect(Hash::check('new-password', $user->refresh()->password))->toBeTrue();
});

test('correct password must be provided to update password', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from(route('security.edit'))
        ->put(route('user-password.update'), [
            'current_password' => 'wrong-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

    $response
        ->assertSessionHasErrors('current_password')
        ->assertRedirect(route('security.edit'));
});
