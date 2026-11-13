<?php

namespace GatewayLogin\Tests;

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use GatewayLogin\Events\GatewayLogin;
use GatewayLogin\Events\GatewayLoginRefused;
use GatewayLogin\Gateway;
use GatewayLogin\GatewayServiceProvider;

/**
 * The gateway signs a one-time token with Ed25519 after the device logs in; these
 * tests play the gateway's part with a key pair made here.
 */
class GatewayLoginTest extends TestCase
{
    private static ?string $pair = null;

    protected function getPackageProviders($app): array
    {
        return [GatewayServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set([
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'app.url' => 'https://obuka.example.com',
            'cache.default' => 'file',
            'session.driver' => 'file',
            'gateway.enabled' => true,
            'gateway.url' => 'https://login.example.com',
            'gateway.app_id' => 'main',
            'gateway.public_key' => base64_encode(sodium_crypto_sign_publickey(self::pair())),
            'gateway.issuer' => 'Prosoft apps',
            'gateway.session_hours' => 4,
        ]);
    }

    protected function defineRoutes($router): void
    {
        // A typical app: a login page for guests, the rest behind its own auth.
        Route::middleware('web')->group(function () {
            Route::get('/', fn () => 'home');
            Route::get('/login', fn () => 'login form')->name('login');
            Route::post('/login', fn () => 'logged in');
            Route::get('/up', fn () => 'up');
            Route::get('/dashboard', fn () => 'dashboard')->middleware('auth');
            Route::get('/dashboard/registranti', fn () => 'list')->middleware('auth');
            Route::get('/who', fn () => json_encode(Gateway::user()));
        });
        Route::get('/api/ping', fn () => 'pong');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['cache']->flush();
    }

    private static function pair(): string
    {
        return self::$pair ??= sodium_crypto_sign_keypair();
    }

    private static function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function token(array $overrides = [], ?string $secret = null): string
    {
        $now = now()->getTimestamp();
        $claims = array_merge([
            'iss' => 'Prosoft apps', 'aud' => 'main', 'sub' => 'dev-1', 'name' => 'Pera Perić', 'org' => 'Firma doo',
            'iat' => $now, 'exp' => $now + 60, 'access_until' => $now + 30 * 86400, 'jti' => bin2hex(random_bytes(16)),
        ], $overrides);
        $input = self::b64url('{"alg":"EdDSA","typ":"JWT"}').'.'.self::b64url(json_encode($claims));

        return $input.'.'.self::b64url(sodium_crypto_sign_detached($input, $secret ?? sodium_crypto_sign_secretkey(self::pair())));
    }

    public function test_disabled_gateway_changes_nothing(): void
    {
        config(['gateway.enabled' => false]);
        $this->get('/login')->assertOk();
        $this->get('/gateway/callback?token=x')->assertNotFound();
    }

    public function test_visitors_are_sent_to_the_gateway_login_page_included(): void
    {
        $this->get('/login')->assertRedirect('https://login.example.com/go/main?return=%2Flogin');
        $this->get('/')->assertRedirect('https://login.example.com/go/main');
        $this->get('/dashboard/registranti?page=2')
            ->assertRedirect('https://login.example.com/go/main?return=%2Fdashboard%2Fregistranti%3Fpage%3D2');
    }

    public function test_excepted_paths_and_non_web_routes_stay_open(): void
    {
        $this->get('/up')->assertOk()->assertSee('up');
        $this->get('/api/ping')->assertOk();
    }

    public function test_background_requests_get_419_instead_of_a_cross_site_redirect(): void
    {
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class);
        $this->post('/login', ['email' => 'a@b.c'])->assertStatus(419);
        $this->withHeader('X-Livewire', 'true')->get('/dashboard')->assertStatus(419);
    }

    public function test_a_valid_token_opens_the_app_for_four_hours(): void
    {
        Event::fake([GatewayLogin::class]);
        $this->get('/gateway/callback?token='.self::token().'&return=/dashboard')
            ->assertRedirect('/dashboard')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertSessionHas('gateway.name', 'Pera Perić');
        Event::assertDispatched(GatewayLogin::class, fn ($e) => $e->sub === 'dev-1' && $e->name === 'Pera Perić');

        // Past the wall, the app's own login takes over; the gateway runs before auth.
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/login')->assertOk();
        $this->actingAs(new GenericUser(['id' => 1]))->get('/dashboard')->assertOk();
        $this->assertSame('Pera Perić', json_decode($this->get('/who')->getContent(), true)['name']);

        $this->travel(4 * 3600 + 1)->seconds();
        $this->get('/dashboard')->assertRedirect('https://login.example.com/go/main?return=%2Fdashboard');
    }

    public function test_the_session_never_outlasts_the_device_access(): void
    {
        $this->get('/gateway/callback?token='.self::token(['access_until' => now()->getTimestamp() + 600]));
        $this->travel(601)->seconds();
        $this->get('/login')->assertRedirect('https://login.example.com/go/main?return=%2Flogin');
    }

    public function test_each_token_works_only_once(): void
    {
        $token = self::token();
        $this->get("/gateway/callback?token=$token")->assertRedirect('/');
        $this->flushSession();
        $this->get("/gateway/callback?token=$token")->assertForbidden()->assertSee('Prijavite se ponovo');
        $this->get('/login')->assertRedirect();
    }

    public static function badTokens(): array
    {
        return [
            'other key' => ['other-key'], 'other app' => [['aud' => 'ks']], 'other gateway' => [['iss' => 'Someone else']],
            'expired' => [['iat' => -300, 'exp' => -240]], 'issued in the future' => [['iat' => 300, 'exp' => 360]],
            'access already ended' => [['access_until' => -1]], 'no token id' => [['jti' => '']],
            'tampered claims' => ['tampered'], 'alg none' => ['none'], 'garbage' => ['not-a-token'], 'empty' => [''],
        ];
    }

    #[DataProvider('badTokens')]
    public function test_forged_foreign_expired_and_malformed_tokens_are_refused(array|string $case): void
    {
        Event::fake([GatewayLoginRefused::class]);
        $now = now()->getTimestamp();
        $token = match (true) {
            is_array($case) => self::token(array_map(fn ($v) => in_array($v, [-300, -240, 300, 360, -1], true) ? $now + $v : $v, $case)),
            $case === 'other-key' => self::token([], sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair())),
            $case === 'tampered' => (function () use ($now) {
                [$h, , $s] = explode('.', self::token());

                return "$h.".self::b64url(json_encode(['aud' => 'main', 'sub' => 'x', 'jti' => str_repeat('a', 20), 'iat' => $now, 'exp' => $now + 60, 'access_until' => $now + 999])).".$s";
            })(),
            $case === 'none' => self::b64url('{"alg":"none","typ":"JWT"}').'.'.explode('.', self::token())[1].'.',
            default => $case,
        };
        $this->get('/gateway/callback?token='.urlencode($token))->assertForbidden();
        $this->get('/login')->assertRedirect();
        Event::assertDispatched(GatewayLoginRefused::class);
    }

    public function test_a_missing_public_key_fails_closed(): void
    {
        config(['gateway.public_key' => '']);
        $this->get('/gateway/callback?token='.self::token())->assertForbidden();
    }

    public static function badReturns(): array
    {
        return [['//evil.example'], ['https://evil.example'], ['/\\evil.example'], ["/a\r\nSet-Cookie: x"], ['dashboard']];
    }

    #[DataProvider('badReturns')]
    public function test_return_paths_stay_on_this_site(string $return): void
    {
        $this->get('/gateway/callback?token='.self::token().'&return='.urlencode($return))->assertRedirect('/');
    }

    public function test_another_device_in_the_same_browser_starts_a_fresh_session(): void
    {
        $this->get('/gateway/callback?token='.self::token());
        session()->put('marker', 1);
        $this->get('/gateway/callback?token='.self::token());
        $this->assertSame(1, session('marker'));
        $this->get('/gateway/callback?token='.self::token(['sub' => 'dev-2']));
        $this->assertNull(session('marker'));
        $this->assertSame('dev-2', session('gateway.sub'));
    }

    public function test_check_command_reports_the_setup(): void
    {
        $this->artisan('gateway:check')
            ->expectsOutputToContain('GATEWAY_APP_ID main')
            ->expectsOutputToContain('https://login.example.com/go/main')
            ->expectsOutputToContain('https://obuka.example.com/gateway/callback')
            ->assertSuccessful();

        config(['gateway.public_key' => 'nope', 'cache.default' => 'array']);
        $this->artisan('gateway:check')->expectsOutputToContain('2 problem(s)')->assertFailed();
    }
}
