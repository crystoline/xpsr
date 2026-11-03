<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Auth\OAuth2\Tests;

use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\Auth\OAuth2\Client;
use Crystoline\Xpsr\Auth\OAuth2\Console\PurgeCommand;
use Crystoline\Xpsr\Auth\OAuth2\Tests\Fixtures\Project;
use Crystoline\Xpsr\Testing\ApplicationTestCase;
use Crystoline\Xpsr\Testing\TestResponse;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Symfony\Component\Console\Tester\CommandTester;

final class ServerTest extends ApplicationTestCase
{
    private const REDIRECT = 'https://app.example/callback';

    private ?Project $project = null;

    protected function createApplication(): Application
    {
        $this->project ??= new Project();

        return $this->project->boot();
    }

    protected function tearDown(): void
    {
        $this->project?->remove();
        $this->project = null;
    }

    public function testAClientCredentialsTokenAuthenticatesAsTheClient(): void
    {
        $new = $this->project()->clients()->create('Reporting service', scopes: ['orders:read', 'profile']);
        $response = $this->token(['grant_type' => 'client_credentials', 'client_id' => $new->client->id, 'client_secret' => $new->secret, 'scope' => 'orders:read']);
        $response->assertOk()->assertHeader('Content-Type', 'application/json; charset=UTF-8')->assertHeader('Cache-Control', 'no-store');
        self::assertSame('Bearer', $response->json('token_type'));
        self::assertEqualsWithDelta(3600, $response->json('expires_in'), 2, 'An hour, give or take the second boundary.');
        self::assertArrayNotHasKey('refresh_token', (array) $response->json());
        $token = (string) $response->json('access_token');
        self::assertSame(3, substr_count($token, '.') + 1, 'A JWT.');

        $me = $this->withToken($token)->getJson('/api/me')->assertOk();
        self::assertSame(['client', $new->client->id, 'Reporting service', 'api', ['orders:read']], [$me->json('subject'), $me->json('id'), $me->json('name'), $me->json('guard'), $me->json('scopes')]);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{80}\z/', (string) $me->json('token'));
        $this->getJson('/api/orders')->assertOk();

        $claims = json_decode((string) base64_decode(strtr(explode('.', $token)[1], '-_', '+/')), true);
        self::assertIsArray($claims);
        self::assertSame([$new->client->id, ['orders:read']], [$claims['aud'], $claims['scopes']]);
        self::assertContains($claims['sub'], ['', $new->client->id], 'No user: version 9 of the library names the client as the subject, version 8 nobody.');
    }

    public function testBasicAuthenticationAndAnEmptyScopeWork(): void
    {
        $new = $this->project()->clients()->create('Reporting service');
        $request = $this->application()->get(ServerRequestFactoryInterface::class)
            ->createServerRequest('POST', 'http://localhost/oauth/token')
            ->withHeader('Authorization', 'Basic ' . base64_encode("{$new->client->id}:{$new->secret}"))
            ->withParsedBody(['grant_type' => 'client_credentials']);
        $token = (string) $this->send($request)->assertOk()->json('access_token');
        $this->withToken($token)->getJson('/api/me')->assertOk()->assertJsonPath('scopes', []);
        $this->getJson('/api/orders')->assertForbidden()->assertHeader('WWW-Authenticate', 'Bearer error="insufficient_scope", scope="orders:read"');
    }

    public function testAWrongSecretOrAnUnknownClientIsRefused(): void
    {
        $new = $this->project()->clients()->create('Reporting service');
        $this->token(['grant_type' => 'client_credentials', 'client_id' => $new->client->id, 'client_secret' => 'wrong'])->assertUnauthorized()->assertJsonPath('error', 'invalid_client');
        $this->token(['grant_type' => 'client_credentials', 'client_id' => 'unknown', 'client_secret' => $new->secret])->assertUnauthorized()->assertJsonPath('error', 'invalid_client');
        $this->token(['grant_type' => 'password', 'client_id' => $new->client->id, 'client_secret' => $new->secret])->assertStatus(400)->assertJsonPath('error', 'unsupported_grant_type');
    }

    public function testAClientGetsOnlyConfiguredScopesItMayAskFor(): void
    {
        $new = $this->project()->clients()->create('Reporting service', scopes: ['orders:read']);
        $this->token(['grant_type' => 'client_credentials', 'client_id' => $new->client->id, 'client_secret' => $new->secret, 'scope' => 'orders:write'])
            ->assertStatus(400)->assertJsonPath('error', 'invalid_scope');
        $this->token(['grant_type' => 'client_credentials', 'client_id' => $new->client->id, 'client_secret' => $new->secret, 'scope' => 'admin'])
            ->assertStatus(400)->assertJsonPath('error', 'invalid_scope');
    }

    public function testAPublicClientCannotUseClientCredentials(): void
    {
        $new = $this->project()->clients()->create('Mobile app', [self::REDIRECT], confidential: false);
        $response = $this->token(['grant_type' => 'client_credentials', 'client_id' => $new->client->id]);
        self::assertContains($response->status(), [400, 401]);
        self::assertContains($response->json('error'), ['invalid_client', 'unauthorized_client']);
    }

    /** league/oauth2-server 8.5 checks a redirect_uri with a league/uri 7 method that is deprecated; 9 does not. */
    #[IgnoreDeprecations]
    public function testTheAuthorizationCodeFlowWithPkce(): void
    {
        $project = $this->project();
        $new = $project->clients()->create('Mobile app', [self::REDIRECT], confidential: false);
        [$verifier, $challenge] = self::pkce();
        $query = http_build_query(['response_type' => 'code', 'client_id' => $new->client->id, 'redirect_uri' => self::REDIRECT, 'scope' => 'orders:read profile', 'state' => 'xyz', 'code_challenge' => $challenge, 'code_challenge_method' => 'S256']);

        $this->get('/oauth/authorize?' . $query)->assertRedirect('/login');

        $this->actingAs($project->ada, 'web');
        $page = $this->get('/oauth/authorize?' . $query)->assertOk()->assertHeader('Cache-Control', 'no-store');
        $page->assertSee('Mobile app wants access to your account')->assertSee('Read your orders')->assertSee('<code>orders:read</code>')->assertSee('See your name and email address');
        $page->assertSee('name="_token"')->assertSee('value="approve"')->assertSee('value="deny"')->assertSee('action="/oauth/authorize"');

        $location = $this->postForm('/oauth/authorize', ['action' => 'approve'])->assertStatus(302)->getHeaderLine('Location');
        self::assertStringStartsWith(self::REDIRECT . '?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $params);
        self::assertSame('xyz', $params['state'] ?? null);
        self::assertIsString($params['code'] ?? null);

        $response = $this->token(['grant_type' => 'authorization_code', 'client_id' => $new->client->id, 'redirect_uri' => self::REDIRECT, 'code' => $params['code'], 'code_verifier' => $verifier])->assertOk();
        $access = (string) $response->json('access_token');
        $refresh = (string) $response->json('refresh_token');
        self::assertNotSame('', $refresh);
        $me = $this->withToken($access)->getJson('/api/me')->assertOk();
        self::assertSame(['user', 7, 'Ada', ['orders:read', 'profile']], [$me->json('subject'), $me->json('id'), $me->json('name'), $me->json('scopes')]);
        $this->getJson('/api/orders')->assertOk();

        $issued = $project->tokens()->forUser($project->ada);
        self::assertCount(1, $issued);
        self::assertSame([$new->client->id, 'Mobile app', '7', ['orders:read', 'profile'], $me->json('token')], [$issued[0]->clientId, $issued[0]->clientName, $issued[0]->userId, $issued[0]->scopes, $issued[0]->id]);

        $reused = $this->token(['grant_type' => 'authorization_code', 'client_id' => $new->client->id, 'redirect_uri' => self::REDIRECT, 'code' => $params['code'], 'code_verifier' => $verifier])->assertStatus(400);
        self::assertContains($reused->json('error'), ['invalid_request', 'invalid_grant'], 'A code is good once; the two majors of the library name the error differently.');

        $renewed = $this->token(['grant_type' => 'refresh_token', 'client_id' => $new->client->id, 'refresh_token' => $refresh])->assertOk();
        $this->withToken($access)->getJson('/api/me')->assertUnauthorized();
        $this->withToken((string) $renewed->json('access_token'))->getJson('/api/me')->assertOk()->assertJsonPath('scopes', ['orders:read', 'profile']);
        self::assertCount(1, $project->tokens()->forUser($project->ada), 'The old token is revoked.');
    }

    public function testTheUserMayDeny(): void
    {
        $project = $this->project();
        $new = $project->clients()->create('Mobile app', [self::REDIRECT]);
        $this->actingAs($project->ada, 'web');
        $this->get('/oauth/authorize?' . http_build_query(['response_type' => 'code', 'client_id' => $new->client->id, 'state' => 'abc']))->assertOk();
        $location = $this->postForm('/oauth/authorize', ['action' => 'deny'])->assertStatus(302)->getHeaderLine('Location');
        self::assertStringStartsWith(self::REDIRECT . '?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $params);
        self::assertSame(['access_denied', 'abc'], [$params['error'] ?? null, $params['state'] ?? null]);
        self::assertArrayNotHasKey('code', $params);
        self::assertSame([], $project->tokens()->forUser($project->ada));
    }

    public function testAWrongVerifierOrAMissingChallengeIsRefused(): void
    {
        $project = $this->project();
        $new = $project->clients()->create('Mobile app', [self::REDIRECT], confidential: false);
        $this->actingAs($project->ada, 'web');
        $this->get('/oauth/authorize?' . http_build_query(['response_type' => 'code', 'client_id' => $new->client->id]))
            ->assertStatus(400)->assertJsonPath('error', 'invalid_request');

        [, $challenge] = self::pkce();
        $this->get('/oauth/authorize?' . http_build_query(['response_type' => 'code', 'client_id' => $new->client->id, 'code_challenge' => $challenge, 'code_challenge_method' => 'S256']))->assertOk();
        $location = $this->postForm('/oauth/authorize', ['action' => 'approve'])->assertStatus(302)->getHeaderLine('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $params);
        $this->token(['grant_type' => 'authorization_code', 'client_id' => $new->client->id, 'code' => $params['code'] ?? '', 'code_verifier' => str_repeat('x', 43)])
            ->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
    }

    public function testAScopeTheClientMayNotAskForGoesBackToTheApplication(): void
    {
        $project = $this->project();
        $new = $project->clients()->create('Mobile app', [self::REDIRECT], scopes: ['profile']);
        $this->actingAs($project->ada, 'web');
        $location = $this->get('/oauth/authorize?' . http_build_query(['response_type' => 'code', 'client_id' => $new->client->id, 'scope' => 'orders:read', 'state' => 's']))
            ->assertStatus(302)->getHeaderLine('Location');
        self::assertStringStartsWith(self::REDIRECT . '?', $location);
        self::assertStringContainsString('error=invalid_scope', $location);
    }

    public function testADecisionNeedsAPendingRequest(): void
    {
        $this->actingAs($this->project()->ada, 'web');
        $this->postForm('/oauth/authorize', ['action' => 'approve'])->assertStatus(400);
    }

    public function testTheApplicationsOwnConsentViewIsUsed(): void
    {
        $project = $this->project(new Project(views: true));
        $new = $project->clients()->create('Mobile app', [self::REDIRECT]);
        $this->actingAs($project->ada, 'web');
        $page = $this->get('/oauth/authorize?' . http_build_query(['response_type' => 'code', 'client_id' => $new->client->id, 'scope' => 'profile']))->assertOk();
        $page->assertSee('Custom consent: Mobile app for Ada')->assertSee('<p>See your name and email address</p>')->assertDontSee('wants access to your account');
        $location = $this->postForm('/oauth/authorize', ['action' => 'approve'])->assertStatus(302)->getHeaderLine('Location');
        self::assertStringContainsString('code=', $location);
    }

    public function testRevokedAndExpiredTokensAreRefused(): void
    {
        $project = $this->project();
        $new = $project->clients()->create('Reporting service');
        $first = (string) $this->token(['grant_type' => 'client_credentials', 'client_id' => $new->client->id, 'client_secret' => $new->secret])->json('access_token');
        $id = (string) $this->withToken($first)->getJson('/api/me')->assertOk()->json('token');
        self::assertTrue($project->tokens()->revoke($id));
        self::assertFalse($project->tokens()->revoke($id));
        $this->getJson('/api/me')->assertUnauthorized()->assertHeader('WWW-Authenticate', 'Bearer error="invalid_token"');

        $second = (string) $this->token(['grant_type' => 'client_credentials', 'client_id' => $new->client->id, 'client_secret' => $new->secret])->json('access_token');
        $this->withToken($second)->getJson('/api/me')->assertOk();
        $project->clock->modify('+61 minutes');
        $this->getJson('/api/me')->assertUnauthorized();
    }

    public function testRevokingAClientEndsItsTokens(): void
    {
        $project = $this->project();
        $new = $project->clients()->create('Reporting service');
        $token = (string) $this->token(['grant_type' => 'client_credentials', 'client_id' => $new->client->id, 'client_secret' => $new->secret])->json('access_token');
        $this->withToken($token)->getJson('/api/me')->assertOk();
        $project->clients()->revoke($new->client->id);
        $this->getJson('/api/me')->assertUnauthorized();
        $this->token(['grant_type' => 'client_credentials', 'client_id' => $new->client->id, 'client_secret' => $new->secret])->assertUnauthorized();
    }

    public function testRequestsWithoutAValidBearerTokenAreGuests(): void
    {
        $this->project();
        $this->getJson('/api/me')->assertUnauthorized()->assertHeader('WWW-Authenticate', 'Bearer');
        $this->withToken('not.a.jwt')->getJson('/api/me')->assertUnauthorized()->assertHeader('WWW-Authenticate', 'Bearer error="invalid_token"');
    }

    public function testPurgeRemovesExpiredAndRevokedRows(): void
    {
        $project = $this->project();
        $new = $project->clients()->create('Mobile app', [self::REDIRECT]);
        $this->actingAs($project->ada, 'web');
        $this->get('/oauth/authorize?' . http_build_query(['response_type' => 'code', 'client_id' => $new->client->id]))->assertOk();
        $this->postForm('/oauth/authorize', ['action' => 'deny'])->assertStatus(302);
        $this->get('/oauth/authorize?' . http_build_query(['response_type' => 'code', 'client_id' => $new->client->id]))->assertOk();
        $location = $this->postForm('/oauth/authorize', ['action' => 'approve'])->assertStatus(302)->getHeaderLine('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $params);
        $this->token(['grant_type' => 'authorization_code', 'client_id' => $new->client->id, 'client_secret' => $new->secret, 'code' => $params['code'] ?? ''])->assertOk();
        $service = $project->clients()->create('Reporting service');
        $this->token(['grant_type' => 'client_credentials', 'client_id' => $service->client->id, 'client_secret' => $service->secret])->assertOk();
        self::assertSame(1, $project->tokens()->revokeForClient($service->client->id));

        $count = fn(string $table): int => (int) $project->db()->fetchOne("SELECT COUNT(*) FROM {$table}");
        self::assertSame([1, 2, 1], [$count('oauth_auth_codes'), $count('oauth_access_tokens'), $count('oauth_refresh_tokens')]);

        self::assertSame(2, $project->tokens()->purge(), 'The used code, and the revoked client credentials token; the user token is live.');
        $project->clock->modify('+2 hours');
        self::assertSame(0, $project->tokens()->purge(), 'The access token expired, but its refresh token is live.');
        $project->clock->modify('+31 days');
        $purge = new CommandTester($this->application()->get(PurgeCommand::class));
        self::assertSame(0, $purge->execute([]));
        self::assertStringContainsString('Removed 2 expired or revoked tokens and codes.', $purge->getDisplay());
        self::assertSame([0, 0, 0], [$count('oauth_auth_codes'), $count('oauth_access_tokens'), $count('oauth_refresh_tokens')]);
    }

    public function testRevokingAUsersTokensDisconnectsTheirApplications(): void
    {
        $project = $this->project();
        $new = $project->clients()->create('Mobile app', [self::REDIRECT]);
        $this->actingAs($project->ada, 'web');
        $this->get('/oauth/authorize?' . http_build_query(['response_type' => 'code', 'client_id' => $new->client->id]))->assertOk();
        $location = $this->postForm('/oauth/authorize', ['action' => 'approve'])->assertStatus(302)->getHeaderLine('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $params);
        $response = $this->token(['grant_type' => 'authorization_code', 'client_id' => $new->client->id, 'client_secret' => $new->secret, 'code' => $params['code'] ?? ''])->assertOk();
        self::assertSame(1, $project->tokens()->revokeForUser($project->ada));
        self::assertSame(0, $project->tokens()->revokeForUser($project->ada, $new->client->id));
        $this->withToken((string) $response->json('access_token'))->getJson('/api/me')->assertUnauthorized();
        $refused = $this->token(['grant_type' => 'refresh_token', 'client_id' => $new->client->id, 'client_secret' => $new->secret, 'refresh_token' => (string) $response->json('refresh_token')]);
        self::assertContains($refused->status(), [400, 401]);
        self::assertContains($refused->json('error'), ['invalid_request', 'invalid_grant'], 'The two majors of the library name a revoked refresh token differently.');
    }

    public function testMissingKeysAreNamed(): void
    {
        $project = $this->project(new Project(keys: false));
        $new = $project->clients()->create('Reporting service');
        $response = $this->token(['grant_type' => 'client_credentials', 'client_id' => $new->client->id, 'client_secret' => $new->secret]);
        $response->assertStatus(500);
        self::assertStringContainsString('oauth.private_key: the key file var/oauth/private.key does not exist or cannot be read. Run php xpsr oauth:keys to make the keys.', $response->body());
    }

    public function testTheGuardIsRegisteredAsADriver(): void
    {
        $this->project();
        self::assertInstanceOf(Client::class, $this->project()->clients()->create('Service')->client);
    }

    /** The project, created now with these settings or earlier with the defaults; the application comes from it. */
    private function project(?Project $project = null): Project
    {
        if ($project !== null) {
            $this->project = $project;
        }
        $this->application();

        return $this->project ?? self::fail('No project.');
    }

    /** @param array<string, mixed> $parameters */
    private function token(array $parameters): TestResponse
    {
        $request = $this->application()->get(ServerRequestFactoryInterface::class)
            ->createServerRequest('POST', 'http://localhost/oauth/token')
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withParsedBody($parameters);

        return $this->send($request);
    }

    /** @return array{string, string} a PKCE verifier and its S256 challenge */
    private static function pkce(): array
    {
        $verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        return [$verifier, rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=')];
    }
}
