<?php

declare(strict_types=1);

use App\Enums\Log;
use App\Http\Controllers\SessionController;
use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;

/**
 * The local sign-in routes are only registered in the local environment, so the routes are loaded again in it.
 */
function registerRoutes(string $env): void
{
    app()->instance('env', $env);

    Route::middleware('web')->group(base_path('routes/web.php'));

    resolve(Router::class)->getRoutes()->refreshNameLookups();
    resolve(Router::class)->getRoutes()->refreshActionLookups();
}

beforeEach(function (): void {
    session()->start();
});

it('opens the sign in modal on the page the guest was reading', function (): void {
    registerRoutes('local');

    $user = User::factory()->create();

    $this->from(route('user.show', $user))
        ->get(route('login'))
        ->assertRedirect(route('user.show', $user))
        ->assertInertiaFlash('localSignIn', true);
});

it('opens the sign in modal on the home page without a previous page', function (): void {
    registerRoutes('local');

    $this->get(route('login'))
        ->assertRedirectToRoute('home')
        ->assertInertiaFlash('localSignIn', true);
});

it('creates a user for a new username and signs them in', function (): void {
    registerRoutes('local');

    $this->post(route('local-login.store'), ['_token' => session()->token(), 'username' => 'octocat'])
        ->assertRedirectToRoute('log.show', Log::Following);

    $user = User::query()->where('username', 'octocat')->sole();

    expect($user)
        ->github_id->toBe('local:octocat')
        ->name->toBe('octocat');

    $this->assertAuthenticatedAs($user);
});

it('uses forwarded HTTPS for local sign-in only from loopback proxies', function (string $proxy, string $scheme): void {
    registerRoutes('local');
    app()->register(AppServiceProvider::class, force: true);

    $user = User::factory()->create(['username' => 'octocat', 'log' => Log::Following]);

    $this->withServerVariables(['REMOTE_ADDR' => $proxy])
        ->withHeader('X-Forwarded-Proto', 'https')
        ->post('http://localhost/login/local', ['_token' => session()->token(), 'username' => 'octocat'])
        ->assertRedirect($scheme.'://localhost/log/following');

    $this->assertAuthenticatedAs($user);
})->with([
    'IPv4 loopback' => ['127.0.0.1', 'https'],
    'IPv6 loopback' => ['::1', 'https'],
    'untrusted client' => ['203.0.113.10', 'http'],
]);

it('signs in as the existing user of a username', function (): void {
    registerRoutes('local');

    $user = User::factory()->create(['username' => 'octocat', 'log' => Log::Trending]);

    $this->post(route('local-login.store'), ['_token' => session()->token(), 'username' => 'octocat'])
        ->assertRedirectToRoute('log.show', Log::Trending);

    $this->assertAuthenticatedAs($user);

    expect(User::query()->count())->toBe(1);
});

it('returns to the page the guest was reading', function (): void {
    registerRoutes('local');

    $user = User::factory()->create();

    $this->get(route('user.show', $user))->assertOk();

    $this->post(route('local-login.store'), ['_token' => session()->token(), 'username' => 'octocat'])
        ->assertRedirect(route('user.show', $user));
});

it('refuses an invalid username', function (string $username): void {
    registerRoutes('local');

    $this->post(route('local-login.store'), ['_token' => session()->token(), 'username' => $username])
        ->assertSessionHasErrors('username');

    $this->assertGuest();
})->with(['', '-octocat', 'octocat-', 'octo--cat', 'octo/cat', str_repeat('a', 40)]);

it('signs in with GitHub outside the local environment', function (): void {
    registerRoutes('production');

    expect(Route::has('local-login.store'))->toBeFalse()
        ->and(Route::getRoutes()->getByName('login')->getActionName())->toBe(SessionController::class.'@create');
});
