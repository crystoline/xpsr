<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Auth\OAuth2;

use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\Auth\OAuth2\Console\CreateClientCommand;
use Crystoline\Xpsr\Auth\OAuth2\Console\KeysCommand;
use Crystoline\Xpsr\Auth\OAuth2\Console\ListClientsCommand;
use Crystoline\Xpsr\Auth\OAuth2\Console\MakeOAuthTablesCommand;
use Crystoline\Xpsr\Auth\OAuth2\Console\PurgeCommand;
use Crystoline\Xpsr\Auth\OAuth2\Console\RevokeClientCommand;
use Crystoline\Xpsr\Auth\OAuth2\Http\AuthorizeController;
use Crystoline\Xpsr\Auth\OAuth2\Http\TokenController;
use Crystoline\Xpsr\Configuration;
use Crystoline\Xpsr\Console\CommandRegistry;
use Crystoline\Xpsr\Database\ConnectionManager;
use Crystoline\Xpsr\ServiceContainer\ServiceRegistry;
use Crystoline\Xpsr\View\ViewHandlerInterface;
use InvalidArgumentException;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Grant\AuthCodeGrant;
use League\OAuth2\Server\Grant\ClientCredentialsGrant;
use League\OAuth2\Server\Grant\RefreshTokenGrant;
use League\OAuth2\Server\ResourceServer;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Registers the OAuth2 authorization server, configured in config/oauth.php (see OAuthOptions)
 * and OAUTH_* variables:
 *
 *   Crystoline\Xpsr\Auth\OAuth2\Clients                    register, list, and revoke clients. Shared.
 *   Crystoline\Xpsr\Auth\OAuth2\Tokens                     list, revoke, and purge issued tokens. Shared.
 *   Crystoline\Xpsr\Auth\OAuth2\Scopes                     the configured scopes and their descriptions. Shared.
 *   League\OAuth2\Server\AuthorizationServer               the server, with the enabled grants. Shared.
 *   League\OAuth2\Server\ResourceServer                    checks access tokens. Shared.
 *   Crystoline\Xpsr\Auth\OAuth2\Http\TokenController       token(), for POST /oauth/token.
 *   Crystoline\Xpsr\Auth\OAuth2\Http\AuthorizeController   show() and decide(), for /oauth/authorize.
 *   auth.driver.oauth2                                     the guard driver for 'driver' => 'oauth2' in auth.guards.
 *
 * and the make:oauth-tables, oauth:keys, oauth:client, oauth:clients, oauth:client-revoke, and
 * oauth:purge commands. Needs the database provider; list it after the database, auth, session,
 * and view providers.
 */
final class OAuth2ServiceRegistry extends ServiceRegistry
{
    private const ENVIRONMENT = ['OAUTH_PRIVATE_KEY', 'OAUTH_PUBLIC_KEY', 'OAUTH_ENCRYPTION_KEY'];

    public function register(): void
    {
        $this->container->factory(OAuthOptions::class, static function (ContainerInterface $container): OAuthOptions {
            $config = $container->get(Configuration::class)->get('oauth', []);
            if (!is_array($config)) {
                throw new InvalidArgumentException('The oauth configuration must be an array.');
            }
            $environment = [];
            foreach (self::ENVIRONMENT as $name) {
                $environment[$name] = env($name);
            }

            return OAuthOptions::from($config, $environment);
        }, shared: true);
        $this->container->factory(Keys::class, static fn(ContainerInterface $container): Keys => new Keys(
            $container->get(OAuthOptions::class),
            $container->get(Application::class)->getRootDir(),
        ), shared: true);
        $this->container->factory(Tokens::class, static function (ContainerInterface $container): Tokens {
            $options = $container->get(OAuthOptions::class);

            return new Tokens(
                $container->get(ConnectionManager::class)->connection($options->connection),
                $options,
                $container->get(ClockInterface::class),
            );
        }, shared: true);
        $this->container->factory(Clients::class, static function (ContainerInterface $container): Clients {
            $options = $container->get(OAuthOptions::class);

            return new Clients(
                $container->get(ConnectionManager::class)->connection($options->connection),
                $options,
                $container->get(ClockInterface::class),
                $container->get(Tokens::class),
            );
        }, shared: true);
        $this->container->factory(Scopes::class, static fn(ContainerInterface $container): Scopes => new Scopes($container->get(OAuthOptions::class)), shared: true);
        $this->container->factory(AuthorizationServer::class, static function (ContainerInterface $container): AuthorizationServer {
            $options = $container->get(OAuthOptions::class);
            $keys = $container->get(Keys::class);
            $tokens = $container->get(Tokens::class);
            $server = new AuthorizationServer(
                $container->get(Clients::class),
                $tokens,
                $container->get(Scopes::class),
                $keys->privateKey(),
                $keys->encryptionKey(),
            );
            $access = OAuthOptions::interval($options->accessTokenTtl);
            $refresh = OAuthOptions::interval($options->refreshTokenTtl);
            foreach ($options->grants as $name) {
                if ($name === 'client_credentials') {
                    $server->enableGrantType(new ClientCredentialsGrant(), $access);
                } elseif ($name === 'authorization_code') {
                    $grant = new AuthCodeGrant($tokens, $tokens, OAuthOptions::interval($options->authCodeTtl));
                    $grant->setRefreshTokenTTL($refresh);
                    $server->enableGrantType($grant, $access);
                } else {
                    $grant = new RefreshTokenGrant($tokens);
                    $grant->setRefreshTokenTTL($refresh);
                    $server->enableGrantType($grant, $access);
                }
            }

            return $server;
        }, shared: true);
        $this->container->factory(ResourceServer::class, static fn(ContainerInterface $container): ResourceServer => new ResourceServer(
            $container->get(Tokens::class),
            $container->get(Keys::class)->publicKey(),
        ), shared: true);
        // auth.guards driver "oauth2", resolved by the framework's AuthManager.
        $this->container->factory('auth.driver.oauth2', static fn(ContainerInterface $container): OAuth2GuardFactory => new OAuth2GuardFactory($container), shared: true);
        $this->container->factory(TokenController::class, static fn(ContainerInterface $container): TokenController => new TokenController(
            $container->get(AuthorizationServer::class),
            $container->get(ResponseFactoryInterface::class),
        ));
        $this->container->factory(AuthorizeController::class, static fn(ContainerInterface $container): AuthorizeController => new AuthorizeController(
            $container->get(AuthorizationServer::class),
            $container->get(Scopes::class),
            $container->get(OAuthOptions::class),
            $container->get(ResponseFactoryInterface::class),
            $container->get(StreamFactoryInterface::class),
            $container->has(ViewHandlerInterface::class) ? $container->get(ViewHandlerInterface::class) : null,
        ));
        foreach ([KeysCommand::class, CreateClientCommand::class, ListClientsCommand::class, RevokeClientCommand::class, PurgeCommand::class, MakeOAuthTablesCommand::class] as $command) {
            $this->container->bind($command);
        }
    }

    public function boot(): void
    {
        if (!$this->container->has(CommandRegistry::class)) {
            return;
        }
        $commands = $this->container->get(CommandRegistry::class);
        $commands->register('make:oauth-tables', MakeOAuthTablesCommand::class, 'Create the migration for the OAuth2 clients, tokens, and codes tables.');
        $commands->register('oauth:keys', KeysCommand::class, 'Make the keys that sign access tokens and encrypt refresh tokens.');
        $commands->register('oauth:client', CreateClientCommand::class, 'Register an OAuth2 client and print its secret.');
        $commands->register('oauth:clients', ListClientsCommand::class, 'List the registered OAuth2 clients.');
        $commands->register('oauth:client-revoke', RevokeClientCommand::class, 'Revoke an OAuth2 client and its tokens.');
        $commands->register('oauth:purge', PurgeCommand::class, 'Remove expired and revoked OAuth2 tokens and codes.');
    }
}
