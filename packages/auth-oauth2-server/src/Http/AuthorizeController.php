<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Auth\OAuth2\Http;

use Crystoline\Xpsr\Auth\Identity;
use Crystoline\Xpsr\Auth\OAuth2\Client;
use Crystoline\Xpsr\Auth\OAuth2\Entity\ResourceOwner;
use Crystoline\Xpsr\Auth\OAuth2\OAuthOptions;
use Crystoline\Xpsr\Auth\OAuth2\Scopes;
use Crystoline\Xpsr\Http\Exception\BadRequestHttpException;
use Crystoline\Xpsr\Session\CsrfMiddleware;
use Crystoline\Xpsr\Session\Session;
use Crystoline\Xpsr\View\ViewHandlerInterface;
use Crystoline\Xpsr\View\ViewNotFoundException;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\RequestTypes\AuthorizationRequest;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * The authorization endpoint of the authorization code grant, for a browser route such as
 * /oauth/authorize behind SessionMiddleware, CsrfMiddleware, and Authenticate on the session
 * guard, so that a guest signs in first and comes back:
 *
 *     $routes->get('/oauth/authorize', [AuthorizeController::class, 'show'])->middleware(Authenticate::class);
 *     $routes->post('/oauth/authorize', [AuthorizeController::class, 'decide'])->middleware(Authenticate::class);
 *
 * show() checks the application's request and renders the consent page: the view oauth.view
 * (views/oauth/authorize.twig) when the application has one, otherwise a built-in page. The
 * page gets client (id, name), scopes (id, description), user, action (the URL to post to), and
 * csrf_token, and posts "action" as approve or deny to decide(), which sends the browser back to
 * the application with a code, or with error=access_denied.
 */
final class AuthorizeController
{
    private const PENDING = '_oauth.authorize';

    public function __construct(
        private readonly AuthorizationServer $server,
        private readonly Scopes $scopes,
        private readonly OAuthOptions $options,
        private readonly ResponseFactoryInterface $responses,
        private readonly StreamFactoryInterface $streams,
        private readonly ?ViewHandlerInterface $views = null,
    ) {}

    /** GET: checks the application's request and shows the consent page. */
    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $identity = Identity::of($request);
        $session = Session::of($request);
        $session->remove(self::PENDING);
        try {
            $authRequest = $this->validate($request);
        } catch (OAuthServerException $exception) {
            return $exception->generateHttpResponse($this->responses->createResponse());
        }
        $session->set(self::PENDING, $request->getQueryParams());
        $client = $authRequest->getClient();
        $scopes = [];
        foreach ($authRequest->getScopes() as $scope) {
            $id = (string) $scope->getIdentifier();
            $scopes[] = ['id' => $id, 'description' => $this->scopes->describe($id)];
        }
        $html = $this->render((string) $client->getName(), (string) $client->getIdentifier(), $scopes, $identity, $request->getUri()->getPath(), $session->token());

        return $this->responses->createResponse()
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store')
            ->withBody($this->streams->createStream($html));
    }

    /** POST: completes the request the user approved, or refuses it, and sends the browser back to the application. */
    public function decide(ServerRequestInterface $request): ResponseInterface
    {
        $identity = Identity::of($request);
        $session = Session::of($request);
        $query = $session->get(self::PENDING);
        $session->remove(self::PENDING);
        if (!is_array($query)) {
            throw new BadRequestHttpException('No authorization request is waiting for a decision. Start again from the application that asked.');
        }
        $body = $request->getParsedBody();
        $approved = is_array($body) && ($body['action'] ?? null) === 'approve';
        $response = $this->responses->createResponse();
        try {
            $authRequest = $this->validate($request->withQueryParams($query));
            $authRequest->setUser(new ResourceOwner((string) $identity->user->authIdentifier()));
            $authRequest->setAuthorizationApproved($approved);

            return $this->server->completeAuthorizationRequest($authRequest, $response);
        } catch (OAuthServerException $exception) {
            return $exception->generateHttpResponse($response);
        }
    }

    /** The request as the server checked it, with the client's allowed scopes checked too, so that the error goes back to the application. */
    private function validate(ServerRequestInterface $request): AuthorizationRequest
    {
        $authRequest = $this->server->validateAuthorizationRequest($request);
        $client = $authRequest->getClient();
        if ($client instanceof Client) {
            foreach ($authRequest->getScopes() as $scope) {
                $id = (string) $scope->getIdentifier();
                if (!$client->allowsScope($id)) {
                    throw OAuthServerException::invalidScope($id, $authRequest->getRedirectUri() ?? $client->redirectUris[0] ?? null);
                }
            }
        }

        return $authRequest;
    }

    /** @param list<array{id: string, description: string}> $scopes */
    private function render(string $client, string $clientId, array $scopes, Identity $identity, string $action, string $token): string
    {
        if ($this->views !== null && $this->options->view !== null) {
            try {
                return $this->views->render($this->options->view, [
                    'client' => ['id' => $clientId, 'name' => $client],
                    'scopes' => $scopes,
                    'user' => $identity->user,
                    'action' => $action,
                    'csrf_token' => $token,
                ]);
            } catch (ViewNotFoundException $exception) {
                // Without the default view, the built-in page; a view named in oauth.view must exist.
                if ($this->options->view !== OAuthOptions::DEFAULT_VIEW) {
                    throw $exception;
                }
            }
        }

        return self::page($client, $scopes, $action, $token);
    }

    /**
     * The built-in consent page.
     *
     * @param list<array{id: string, description: string}> $scopes
     */
    private static function page(string $client, array $scopes, string $action, string $token): string
    {
        $e = static fn(string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $asks = '<p>It asks for no particular permissions.</p>';
        if ($scopes !== []) {
            $items = '';
            foreach ($scopes as $scope) {
                $items .= '<li>' . $e($scope['description']) . ' <code>' . $e($scope['id']) . '</code></li>';
            }
            $asks = "<p>It asks to:</p><ul>{$items}</ul>";
        }
        $name = $e($client);
        $actionAttribute = $e($action);
        $tokenAttribute = $e($token);
        $field = CsrfMiddleware::FIELD;

        return <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>Authorize {$name}</title>
            <style>
            body { font-family: system-ui, sans-serif; margin: 0; padding: 2rem 1rem; color: #1a1a1a; background: #f4f4f4; }
            main { max-width: 32rem; margin: 0 auto; background: #fff; padding: 2rem; border-radius: 0.5rem; }
            h1 { font-size: 1.4rem; margin-top: 0; }
            code { font-size: 0.9em; color: #555; }
            button { font: inherit; padding: 0.6rem 1.4rem; border-radius: 0.3rem; border: 1px solid #888; background: #fff; cursor: pointer; margin-right: 0.5rem; }
            button[value="approve"] { background: #1a56c4; border-color: #1a56c4; color: #fff; }
            </style>
            </head>
            <body>
            <main>
            <h1>{$name} wants access to your account</h1>
            {$asks}
            <form method="post" action="{$actionAttribute}">
            <input type="hidden" name="{$field}" value="{$tokenAttribute}">
            <button type="submit" name="action" value="approve">Approve</button>
            <button type="submit" name="action" value="deny">Deny</button>
            </form>
            </main>
            </body>
            </html>
            HTML;
    }
}
