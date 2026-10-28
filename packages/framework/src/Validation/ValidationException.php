<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Validation;

use Crystoline\Xpsr\Http\Exception\PublicErrorInterface;
use Crystoline\Xpsr\Http\Exception\UnprocessableEntityHttpException;
use InvalidArgumentException;

/**
 * Invalid input: 422, with the messages for each field. Rendered as
 *
 *   {"message": "The given data is invalid.", "errors": {"title": ["This value should not be blank."]}}
 *
 * in every environment. Field names are the names the client sent; nested fields use dots
 * ("address.street") and list items brackets ("items[0].sku").
 */
final class ValidationException extends UnprocessableEntityHttpException implements PublicErrorInterface
{
    public const MESSAGE = 'The given data is invalid.';

    /** @param array<string, list<string>> $errors field => messages */
    public function __construct(private readonly array $errors, private readonly string $publicMessage = self::MESSAGE)
    {
        if ($errors === []) {
            throw new InvalidArgumentException('A validation exception needs at least one error.');
        }
        $first = array_key_first($errors);
        parent::__construct(sprintf('%s %s: %s', $publicMessage, $first, $errors[$first][0] ?? ''));
    }

    /** @return array<string, list<string>> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function publicMessage(): string
    {
        return $this->publicMessage;
    }

    public function details(): array
    {
        return ['errors' => $this->errors];
    }
}
