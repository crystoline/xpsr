<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Auth\OAuth2\Tests;

use Crystoline\Xpsr\Auth\OAuth2\Client;
use Crystoline\Xpsr\Auth\OAuth2\Console\CreateClientCommand;
use Crystoline\Xpsr\Auth\OAuth2\Console\ListClientsCommand;
use Crystoline\Xpsr\Auth\OAuth2\Console\RevokeClientCommand;
use Crystoline\Xpsr\Auth\OAuth2\Tests\Fixtures\Project;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class ClientsTest extends TestCase
{
    private Project $project;

    protected function setUp(): void
    {
        $this->project = new Project();
        $this->project->boot();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testAConfidentialClientHasASecretStoredOnlyAsAHash(): void
    {
        $clients = $this->project->clients();
        $new = $clients->create('Reporting service', scopes: ['orders:read']);
        $client = $new->client;
        self::assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', $client->id);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', (string) $new->secret);
        self::assertSame(['Reporting service', [], ['client_credentials'], ['orders:read'], true, null], [$client->name, $client->redirectUris, $client->grants, $client->scopes, $client->confidential, $client->revokedAt]);
        self::assertSame($this->project->clock->now()->getTimestamp(), $client->createdAt->getTimestamp());

        $row = $this->project->db()->fetchAssociative('SELECT secret_hash FROM oauth_clients WHERE id = ?', [$client->id]);
        self::assertSame(hash('sha256', (string) $new->secret), $row['secret_hash'] ?? null, 'The table keeps a hash, never the secret.');

        self::assertTrue($clients->validateClient($client->id, $new->secret, 'client_credentials'));
        self::assertFalse($clients->validateClient($client->id, 'wrong', 'client_credentials'));
        self::assertFalse($clients->validateClient($client->id, null, 'client_credentials'));
        self::assertFalse($clients->validateClient($client->id, $new->secret, 'authorization_code'), 'A grant the client was not given.');
        self::assertFalse($clients->validateClient('unknown', $new->secret, 'client_credentials'));
        self::assertTrue($client->allowsScope('orders:read'));
        self::assertFalse($client->allowsScope('orders:write'));
        self::assertTrue($client->supportsGrantType('client_credentials'));
        self::assertSame($client->id, $client->authIdentifier());
        self::assertNull($client->authPasswordHash());
    }

    public function testAPublicClientHasNoSecretAndUsesTheAuthorizationCodeGrant(): void
    {
        $clients = $this->project->clients();
        $new = $clients->create('Mobile app', ['myapp://callback', 'http://127.0.0.1:8080/callback'], confidential: false);
        self::assertNull($new->secret);
        self::assertSame(['authorization_code', 'refresh_token'], $new->client->grants);
        self::assertSame(['myapp://callback', 'http://127.0.0.1:8080/callback'], $new->client->getRedirectUri());
        self::assertNull($new->client->scopes, 'Every configured scope.');
        self::assertTrue($new->client->allowsScope('orders:write'));
        self::assertFalse($new->client->isConfidential());
        self::assertTrue($clients->validateClient($new->client->id, null, 'authorization_code'), 'A public client proves itself with PKCE, not a secret.');
        self::assertTrue($clients->validateClient($new->client->id, null, 'refresh_token'));
        self::assertFalse($clients->validateClient($new->client->id, null, 'client_credentials'));

        $found = $clients->find($new->client->id);
        self::assertInstanceOf(Client::class, $found);
        self::assertSame('Mobile app', $found->getName());
        self::assertSame($new->client->id, $clients->getClientEntity($new->client->id)?->getIdentifier());
    }

    public function testRevokingAClientHidesItFromTheServer(): void
    {
        $clients = $this->project->clients();
        $first = $clients->create('First')->client;
        $second = $clients->create('Second', ['https://app.example/cb'], ['authorization_code', 'refresh_token', 'client_credentials'])->client;
        self::assertEqualsCanonicalizing([$first->id, $second->id], array_map(static fn(Client $client): string => $client->id, $clients->all()));

        self::assertTrue($clients->revoke($first->id));
        self::assertFalse($clients->revoke($first->id), 'Revoked already.');
        self::assertFalse($clients->revoke('unknown'));
        $revoked = $clients->find($first->id);
        self::assertInstanceOf(Client::class, $revoked);
        self::assertTrue($revoked->isRevoked());
        self::assertSame($this->project->clock->now()->getTimestamp(), $revoked->revokedAt?->getTimestamp());
        self::assertNull($clients->getClientEntity($first->id), 'The server no longer sees it.');
        self::assertFalse($clients->validateClient($first->id, 'anything', 'client_credentials'));
        self::assertCount(2, $clients->all(), 'Listed still, as revoked.');
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalid(): iterable
    {
        yield 'an empty name' => [['name' => ' '], 'A client name must be 1 to 255 characters, such as "Mobile app".'];
        yield 'a redirect URI without a scheme' => [['name' => 'App', 'redirectUris' => ['app.example/callback']], 'A redirect URI must be an absolute URL without a fragment, such as https://app.example/callback or myapp://callback, not "app.example/callback".'];
        yield 'a redirect URI with a fragment' => [['name' => 'App', 'redirectUris' => ['https://app.example/cb#x']], 'A redirect URI must be an absolute URL without a fragment'];
        yield 'a javascript redirect URI' => [['name' => 'App', 'redirectUris' => ['javascript:alert(1)']], 'A redirect URI must be an absolute URL without a fragment'];
        yield 'an unknown grant' => [['name' => 'App', 'grants' => ['password']], 'Grants must be client_credentials, authorization_code, or refresh_token.'];
        yield 'no grants' => [['name' => 'App', 'grants' => []], 'Grants must be client_credentials, authorization_code, or refresh_token.'];
        yield 'a public client with client credentials' => [['name' => 'App', 'grants' => ['client_credentials'], 'confidential' => false], 'A public client cannot use the client_credentials grant: it has no secret to prove itself with.'];
        yield 'the authorization code grant without a redirect URI' => [['name' => 'App', 'grants' => ['authorization_code']], 'The authorization_code grant needs at least one redirect URI.'];
        yield 'a scope that is not configured' => [['name' => 'App', 'scopes' => ['admin']], 'The scope "admin" is not in oauth.scopes. Configured scopes: orders:read, orders:write, profile.'];
    }

    /** @param array<string, mixed> $arguments */
    #[DataProvider('invalid')]
    public function testInvalidClientsAreRefused(array $arguments, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        $this->project->clients()->create(...$arguments);
    }

    public function testTheCommandsRegisterListAndRevokeClients(): void
    {
        $application = $this->project->application;
        $create = new CommandTester($application->get(CreateClientCommand::class));
        self::assertSame(0, $create->execute(['name' => 'Mobile app', '--redirect' => ['https://app.example/callback'], '--scope' => ['orders:read', 'profile'], '--public' => true]));
        $output = $create->getDisplay();
        self::assertStringContainsString('Registered the public client "Mobile app": grants authorization_code, refresh_token; redirect URIs https://app.example/callback; scopes orders:read, profile.', $output);
        self::assertMatchesRegularExpression('/^Client ID:     [0-9a-f]{32}$/m', $output);
        self::assertStringNotContainsString('Client secret', $output);

        self::assertSame(0, $create->execute(['name' => 'Reporting service']));
        $output = $create->getDisplay();
        self::assertStringContainsString('Registered the confidential client "Reporting service": grants client_credentials; redirect URIs none; scopes every configured scope.', $output);
        self::assertMatchesRegularExpression('/^Client secret: [0-9a-f]{64}$/m', $output);
        self::assertStringContainsString('The secret is shown only now.', $output);
        preg_match('/^Client ID:     ([0-9a-f]{32})$/m', $output, $match);
        $id = $match[1];

        self::assertSame(2, $create->execute(['name' => 'Bad', '--grant' => ['password']]));
        self::assertStringContainsString('[ERROR] Grants must be client_credentials, authorization_code, or', $create->getDisplay());

        $list = new CommandTester($application->get(ListClientsCommand::class));
        self::assertSame(0, $list->execute([]));
        self::assertStringContainsString('Mobile app', $list->getDisplay());
        self::assertStringContainsString('confidential', $list->getDisplay());
        self::assertSame(0, $list->execute(['--json' => true]));
        $json = json_decode($list->getDisplay(), true);
        self::assertIsArray($json);
        self::assertCount(2, $json);
        $mobile = array_column($json, null, 'name')['Mobile app'] ?? [];
        self::assertSame([false, ['orders:read', 'profile'], ['https://app.example/callback']], [$mobile['confidential'] ?? null, $mobile['scopes'] ?? null, $mobile['redirect_uris'] ?? null]);

        $revoke = new CommandTester($application->get(RevokeClientCommand::class));
        self::assertSame(1, $revoke->execute(['id' => 'unknown']));
        self::assertStringContainsString('There is no client unknown.', $revoke->getDisplay());
        self::assertSame(0, $revoke->execute(['id' => $id]));
        self::assertStringContainsString("Revoked client {$id} (Reporting service) and its tokens.", $revoke->getDisplay());
        self::assertSame(0, $revoke->execute(['id' => $id]));
        self::assertStringContainsString("Client {$id} was revoked already.", $revoke->getDisplay());
        self::assertSame(0, $list->execute([]));
        self::assertMatchesRegularExpression('/Reporting service.*\d{4}-\d{2}-\d{2} \d{2}:\d{2}/', $list->getDisplay());
    }

    public function testWithoutClientsTheListSaysSo(): void
    {
        $list = new CommandTester($this->project->application->get(ListClientsCommand::class));
        self::assertSame(0, $list->execute([]));
        self::assertStringContainsString('No clients are registered. Register one with php xpsr oauth:client.', $list->getDisplay());
    }
}
