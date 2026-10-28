<?php

declare(strict_types=1);

namespace Tests\Integration;

use Crystoline\Xpsr\Session\ArraySessionStore;
use Crystoline\Xpsr\Session\SessionStoreInterface;
use Tests\TestCase;

/**
 * Sessions and CSRF in the skeleton's full HTTP kernel, through the TestCase helpers that act as
 * one browser: forms with csrf_field(), flash messages after a redirect, login and logout.
 */
final class SessionApplicationTest extends TestCase
{
    protected function setUp(): void
    {
        $config = $this->application()->getConfig();
        $config->set('routing.files', ['routes/api.php', 'routes/web.php', 'tests/Fixtures/routes/sessions.php']);
        $config->set('app.view_dir', dirname(__DIR__) . '/Fixtures/views');
    }

    public function testTestsUseTheArrayStore(): void
    {
        $this->application()->boot();
        self::assertInstanceOf(ArraySessionStore::class, $this->application()->get(SessionStoreInterface::class), 'phpunit.xml sets SESSION_STORE=array.');
    }

    public function testAFormPostsWithItsTokenAndTheNextPageShowsTheFlashMessage(): void
    {
        $form = $this->get('/tasks/new');
        self::assertSame(200, $form->getStatusCode());
        self::assertMatchesRegularExpression('/\Axpsr_session=[0-9a-f]{40}; Max-Age=7200; Path=\/; HttpOnly; SameSite=Lax\z/', $form->getHeaderLine('Set-Cookie'));
        preg_match('/name="_token" value="([0-9a-f]{64})"/', (string) $form->getBody(), $field);
        self::assertNotEmpty($field, (string) $form->getBody());
        self::assertStringContainsString('<meta name="csrf-token" content="' . $field[1] . '">', (string) $form->getBody());
        self::assertSame($field[1], $this->csrfToken(), 'The helper returns the token the page shows.');

        $saved = $this->postForm('/tasks', ['title' => 'Ship it', '_token' => $field[1]]);
        self::assertSame(303, $saved->getStatusCode());
        self::assertSame('/tasks', $saved->getHeaderLine('Location'));

        self::assertSame(['status' => 'Saved Ship it.'], json_decode((string) $this->get('/tasks')->getBody(), true));
        self::assertSame(['status' => null], json_decode((string) $this->get('/tasks')->getBody(), true), 'A flash message is shown once.');
    }

    public function testAPostWithoutTheTokenIsRefusedWithAMessage(): void
    {
        $json = $this->request('POST', '/tasks', ['Accept' => 'application/json'], ['title' => 'Forged']);
        self::assertSame(403, $json->getStatusCode());
        self::assertSame(['message' => 'This form has expired. Reload the page and try again.'], json_decode((string) $json->getBody(), true));
        self::assertFalse($json->hasHeader('Set-Cookie'), 'A refused request starts no session.');

        $this->csrfToken();
        $html = $this->postForm('/tasks', ['title' => 'Forged', '_token' => 'guess']);
        self::assertSame(403, $html->getStatusCode());
        self::assertStringContainsString('This form has expired. Reload the page and try again.', (string) $html->getBody());
        self::assertSame(['status' => null], json_decode((string) $this->get('/tasks')->getBody(), true));
    }

    public function testScriptsSendTheTokenInAHeader(): void
    {
        $response = $this->request('POST', '/tasks', ['X-CSRF-Token' => $this->csrfToken()], ['title' => 'From JS']);
        self::assertSame(303, $response->getStatusCode());
    }

    public function testLoggingInMovesTheSessionAndLoggingOutEndsIt(): void
    {
        $this->csrfToken();
        $before = $this->session()->id();
        self::assertSame(303, $this->postForm('/login', [])->getStatusCode());
        $after = $this->session()->id();
        self::assertNotSame($before, $after, 'Login moves the session to a new ID.');
        self::assertSame(7, $this->session()->get('user_id'));
        self::assertSame(['user_id' => 7], json_decode((string) $this->get('/whoami')->getBody(), true));

        $logout = $this->postForm('/logout', []);
        self::assertSame(303, $logout->getStatusCode());
        self::assertSame('xpsr_session=; Max-Age=0; Path=/; HttpOnly; SameSite=Lax', $logout->getHeaderLine('Set-Cookie'));
        self::assertSame(['user_id' => null], json_decode((string) $this->get('/whoami')->getBody(), true));
        self::assertNull($this->application()->get(SessionStoreInterface::class)->read($after));
    }

    public function testWithSessionActsAsASignedInUser(): void
    {
        $this->withSession(['user_id' => 3]);
        self::assertSame(['user_id' => 3], json_decode((string) $this->get('/whoami')->getBody(), true));
    }

    public function testApiRoutesAndPagesThatDoNotUseTheSessionSetNoCookie(): void
    {
        $api = $this->request('POST', '/api/echo', ['Accept' => 'application/json'], ['ok' => true]);
        self::assertSame(200, $api->getStatusCode(), 'API routes have no CSRF check.');
        self::assertSame(['ok' => true], json_decode((string) $api->getBody(), true));
        self::assertFalse($api->hasHeader('Set-Cookie'));

        $this->application()->getConfig()->set('app.view_dir', dirname(__DIR__, 2) . '/views');
        $home = $this->get('/');
        self::assertSame(200, $home->getStatusCode());
        self::assertFalse($home->hasHeader('Set-Cookie'), 'The home page does not touch the session.');
    }
}
