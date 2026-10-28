<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Validation;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Validates request input. The framework defines the contract and maps request data onto input
 * classes (InputMapper); a package such as crystoline/xpsr-validation provides the rules.
 */
interface ValidatorInterface
{
    /**
     * Map the request's data onto an input class, then check its rules.
     *
     * @template T of object
     * @param class-string<T> $class an input class whose constructor parameters are the fields
     * @param 'body'|'query'|'route' $from the parsed body, the query string, or the route parameters
     * @return T
     * @throws ValidationException listing every invalid field
     */
    public function validate(ServerRequestInterface $request, string $class, string $from = 'body'): object;

    /**
     * Check an array against rules, without an input class.
     *
     * @param array<array-key, mixed> $data
     * @param array<string, mixed> $rules field => rules, in the implementation's format
     * @return array<string, mixed> the fields named in $rules that are present in $data
     * @throws ValidationException listing every invalid field
     */
    public function validateArray(array $data, array $rules): array;
}
