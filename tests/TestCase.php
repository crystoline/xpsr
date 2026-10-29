<?php

declare(strict_types=1);

namespace Tests;

use Crystoline\Xpsr\Application;
use Crystoline\Xpsr\Auth\AuthManager;
use Crystoline\Xpsr\Auth\UserInterface;
use Crystoline\Xpsr\Session\CsrfMiddleware;
use Crystoline\Xpsr\Session\Session;
use Crystoline\Xpsr\Session\SessionManager;
use PHPUnit\Framework\TestCase as BaseTestCase;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

/**
 * Base class for application tests.
 *
 * Each test gets a fresh application booted from bootstrap/app.php. Requests go through the
 * full HTTP kernel (global middleware, routing, error rendering) without a web server, and
 * console commands run through the console kernel. Within a test, cookies that responses set
 * are sent with later requests, so a test behaves like one browser with one session.
 */
abstract class TestCase extends BaseTestCase
{
    private ?Application $application = null;

    /** @var array<string, string> cookies set by responses in this test, by name */
    private array $cookies = [];

    protected function application(): Application
    {
        return $this->application ??= require dirname(__DIR__) . '/bootstrap/app.php';
    }

    /**
     * Stop the application clock (Psr\Clock\ClockInterface) at a moment, for example
     * freezeTime('2026-01-31 09:00:00'). Move it with $clock->modify('+1 hour') or $clock->sleep(60).
     */
    protected function freezeTime(string $at = 'now'): MockClock
    {
        $clock = new MockClock($at, (string) $this->application()->getConfig()->get('app.timezone', 'UTC'));
        $this->swap(ClockInterface::class, $clock);

        return $clock;
    }

    /**
     * Replace a service for the rest of this test, for example a fake HTTP client:
     * swap(Psr\Http\Client\ClientInterface::class, $fake). Services built before the swap
     * keep the original.
     */
    protected function swap(string $id, mixed $instance): void
    {
        $application = $this->application();
        $application->boot();
        $container = $application->getContainer();
        try {
            $container->instance($id, $instance);
        } catch (ContainerExceptionInterface) {
            // A deferred provider's service can be replaced only once its provider has loaded.
            try {
                $container->get($id);
            } catch (Throwable) {
                // A provider that fails to load no longer blocks the replacement.
            }
            $container->instance($id, $instance);
        }
    }

    /**
     * Send a request through the HTTP kernel.
     *
     * @param array<string, string> $headers
     * @param array<array-key, mixed>|string|null $body an array is sent as JSON
     */
    protected function request(string $method, string $uri, array $headers = [], array|string|null $body = null): ResponseInterface
    {
        $application = $this->application();
        $request = $application->get(ServerRequestFactoryInterface::class)->createServerRequest($method, 'http://localhost' . $uri);
        if (is_array($body)) {
            $body = json_encode($body, JSON_THROW_ON_ERROR);
            $headers += ['Content-Type' => 'application/json'];
        }
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($body !== null) {
            $request = $request->withBody($application->get(StreamFactoryInterface::class)->createStream($body));
        }

        return $this->send($request);
    }

    /**
     * Submit a form as a browser does, with the session's CSRF token unless $data has a "_token".
     *
     * @param array<string, mixed> $data
     */
    protected function postForm(string $uri, array $data): ResponseInterface
    {
        $data += [CsrfMiddleware::FIELD => $this->csrfToken()];
        $request = $this->application()->get(ServerRequestFactoryInterface::class)
            ->createServerRequest('POST', 'http://localhost' . $uri)
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withBody($this->application()->get(StreamFactoryInterface::class)->createStream(http_build_query($data)))
            ->withParsedBody($data);

        return $this->send($request);
    }

    /**
     * Send a request through the HTTP kernel with the cookies earlier responses in this test set,
     * as a browser would, and keep the cookies this response sets.
     */
    protected function send(ServerRequestInterface $request): ResponseInterface
    {
        // Cookies set on the request itself win over the ones kept from earlier responses.
        $response = $this->application()->getHttpKernel()->handle($request->withCookieParams($request->getCookieParams() + $this->cookies));
        foreach ($response->getHeader('Set-Cookie') as $header) {
            $parts = array_map(trim(...), explode(';', $header));
            [$name, $value] = explode('=', array_shift($parts), 2) + [1 => ''];
            $expired = in_array('Max-Age=0', $parts, true);
            if ($expired) {
                unset($this->cookies[$name]);
            } else {
                $this->cookies[$name] = urldecode($value);
            }
        }

        return $response;
    }

    /** The CSRF token of this test's browser session, starting the session if there is none. */
    protected function csrfToken(): string
    {
        $session = $this->session();
        $token = $session->token();
        $this->saveSession($session);

        return $token;
    }

    /**
     * Store values in this test's browser session, for example withSession(['user_id' => 7]) to
     * act as a signed-in user.
     *
     * @param array<string, mixed> $values
     */
    protected function withSession(array $values): void
    {
        $session = $this->session();
        foreach ($values as $key => $value) {
            $session->set($key, $value);
        }
        $this->saveSession($session);
    }

    /** This test's browser session as stored now; read values with get(). Change it with withSession(). */
    protected function session(): Session
    {
        $sessions = $this->sessions();

        return $sessions->load($this->cookies[$sessions->options()->cookieName] ?? null);
    }

    /**
     * Authenticate every later request in this test as this user, without signing in: on every
     * guard, or only on one such as 'api'. API scopes default to everything.
     *
     * @param list<string>|null $scopes
     */
    protected function actingAs(UserInterface $user, ?string $guard = null, ?array $scopes = null): void
    {
        $this->application()->boot();
        $this->application()->get(AuthManager::class)->actingAs($user, $guard, $scopes);
    }

    private function saveSession(Session $session): void
    {
        $sessions = $this->sessions();
        $name = $sessions->options()->cookieName;
        $cookie = $sessions->save($session, isset($this->cookies[$name]), https: false);
        if ($cookie !== null) {
            $this->cookies[$name] = $cookie->value;
        }
    }

    private function sessions(): SessionManager
    {
        $this->application()->boot();

        return $this->application()->get(SessionManager::class);
    }

    /** @param array<string, string> $headers */
    protected function get(string $uri, array $headers = []): ResponseInterface
    {
        return $this->request('GET', $uri, $headers);
    }

    /** @param array<array-key, mixed> $data */
    protected function postJson(string $uri, array $data): ResponseInterface
    {
        return $this->request('POST', $uri, ['Accept' => 'application/json'], $data);
    }

    /**
     * Run a console command, for example console('app:greet', ['name' => 'Ada']).
     * Options use their full names: ['--json' => true].
     *
     * @param array<string, mixed> $input
     * @return array{int, string} exit status and output
     */
    protected function console(string $command, array $input = []): array
    {
        $arguments = new ArrayInput(['command' => $command, ...$input]);
        $arguments->setInteractive(false);
        $output = new BufferedOutput();
        $status = $this->application()->getConsoleKernel()->run($arguments, $output);

        return [$status, $output->fetch()];
    }
}
