<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Http;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;

/**
 * A cookie to send with a response:
 *
 *     $response = (new Cookie('theme', 'dark', maxAge: 31_536_000))->addTo($response);
 *     $response = Cookie::forget('theme')->addTo($response);
 *
 * The defaults are the safe ones: HttpOnly (scripts cannot read it), Secure (HTTPS only;
 * browsers also accept it on http://localhost), and SameSite=Lax (not sent with cross-site form
 * posts). Read cookies with $request->getCookieParams().
 */
final class Cookie
{
    private const NAME = '/\A[!#$%&\'*+.^_`|~0-9A-Za-z-]+\z/';
    private const ATTRIBUTE = '/\A[\x21-\x3A\x3C-\x7E]*\z/';

    /**
     * @param int|null $maxAge seconds until the browser deletes the cookie; null keeps it until the browser closes
     * @param string $sameSite Lax, Strict, or None (None needs $secure)
     */
    public function __construct(
        public readonly string $name,
        public readonly string $value = '',
        public readonly ?int $maxAge = null,
        public readonly string $path = '/',
        public readonly ?string $domain = null,
        public readonly bool $secure = true,
        public readonly bool $httpOnly = true,
        public readonly string $sameSite = 'Lax',
    ) {
        if (!preg_match(self::NAME, $name)) {
            throw new InvalidArgumentException("A cookie name must be a token of letters, digits, and !#$%&'*+.^_`|~- characters, not \"{$name}\".");
        }
        if ($maxAge !== null && $maxAge < 0) {
            throw new InvalidArgumentException('A cookie max age must be 0 or more seconds.');
        }
        if ($path === '' || !preg_match(self::ATTRIBUTE, $path) || ($domain !== null && ($domain === '' || !preg_match(self::ATTRIBUTE, $domain)))) {
            throw new InvalidArgumentException('A cookie path or domain must not be empty or contain spaces, semicolons, or control characters.');
        }
        if (!in_array($sameSite, ['Lax', 'Strict', 'None'], true)) {
            throw new InvalidArgumentException("A cookie's SameSite must be Lax, Strict, or None, not \"{$sameSite}\".");
        }
        if ($sameSite === 'None' && !$secure) {
            throw new InvalidArgumentException('Browsers reject SameSite=None cookies that are not Secure.');
        }
    }

    /** A cookie that deletes the browser's cookie of that name (and path and domain). */
    public static function forget(string $name, string $path = '/', ?string $domain = null): self
    {
        return new self($name, '', 0, $path, $domain);
    }

    /** The value of a Set-Cookie header. */
    public function headerValue(): string
    {
        // PHP decodes cookie values with urldecode(), and rawurlencode() never writes "+", so values round-trip.
        $header = $this->name . '=' . rawurlencode($this->value);
        if ($this->maxAge !== null) {
            $header .= '; Max-Age=' . $this->maxAge;
        }
        $header .= '; Path=' . $this->path;
        if ($this->domain !== null) {
            $header .= '; Domain=' . $this->domain;
        }
        if ($this->secure) {
            $header .= '; Secure';
        }
        if ($this->httpOnly) {
            $header .= '; HttpOnly';
        }

        return $header . '; SameSite=' . $this->sameSite;
    }

    /** The response with this cookie added; other cookies on it are kept. */
    public function addTo(ResponseInterface $response): ResponseInterface
    {
        return $response->withAddedHeader('Set-Cookie', $this->headerValue());
    }
}
