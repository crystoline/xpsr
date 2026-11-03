<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Translation\Tests\Fixtures;

use Crystoline\Xpsr\Http\Response\ResponseBuilder;
use Crystoline\Xpsr\Validation\ValidatorInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Validator\Constraints as Assert;

/** The handlers of the fixture project's routes, using the global helpers as an application would. */
final class Controllers
{
    public function __construct(private readonly ResponseBuilder $responses, private readonly ValidatorInterface $validator) {}

    public function hello(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responses->json([
            'message' => trans('messages.welcome', ['name' => 'Ada']),
            'locale' => locale(),
            'attribute' => $request->getAttribute('locale'),
        ]);
    }

    public function view(ServerRequestInterface $request): ResponseInterface
    {
        return view('hello', ['name' => 'Ada']);
    }

    public function validate(ServerRequestInterface $request): ResponseInterface
    {
        $data = json_decode((string) $request->getBody(), true);
        $valid = $this->validator->validateArray(is_array($data) ? $data : [], ['title' => new Assert\NotBlank(), 'age' => new Assert\Range(min: 18)]);

        return $this->responses->json($valid);
    }
}
