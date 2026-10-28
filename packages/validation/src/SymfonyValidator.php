<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Validation\Symfony;

use Crystoline\Xpsr\Validation\InputMapper;
use Crystoline\Xpsr\Validation\ValidationException;
use Crystoline\Xpsr\Validation\ValidatorInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface as SymfonyValidatorInterface;

/**
 * Validates with Symfony Validator. Input classes declare rules as attributes from
 * Symfony\Component\Validator\Constraints, such as #[Assert\NotBlank]; the framework's
 * InputMapper first maps and converts the request data, so type problems and rule violations
 * are reported together in one ValidationException, under the client's field names.
 */
final class SymfonyValidator implements ValidatorInterface
{
    public function __construct(
        private readonly SymfonyValidatorInterface $validator,
        private readonly InputMapper $mapper,
    ) {}

    public function validate(ServerRequestInterface $request, string $class, string $from = 'body'): object
    {
        $input = $this->mapper->map(InputMapper::data($request, $from), $class);
        $errors = $this->errors($this->validator->validate($input), fn(string $path): string => $this->mapper->fieldPath($class, $path));
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $input;
    }

    public function validateArray(array $data, array $rules): array
    {
        $errors = [];
        $valid = [];
        foreach ($rules as $field => $constraints) {
            $value = $data[$field] ?? null;
            $list = is_array($constraints) ? array_values($constraints) : [$constraints];
            foreach ($list as $constraint) {
                if (!$constraint instanceof Constraint) {
                    throw new \InvalidArgumentException("The rules for \"{$field}\" must be Symfony Validator constraints, such as new Assert\\NotBlank().");
                }
            }
            /** @var list<Constraint> $list */
            $errors += $this->errors($this->validator->validate($value, $list), static fn(string $path): string => $field . ($path === '' ? '' : (str_starts_with($path, '[') ? '' : '.') . $path));
            if (array_key_exists($field, $data)) {
                $valid[$field] = $data[$field];
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $valid;
    }

    /**
     * @param callable(string): string $field turns a property path into a field name
     * @return array<string, list<string>>
     */
    private function errors(ConstraintViolationListInterface $violations, callable $field): array
    {
        $errors = [];
        foreach ($violations as $violation) {
            $errors[$field($violation->getPropertyPath())][] = (string) $violation->getMessage();
        }

        return $errors;
    }
}
