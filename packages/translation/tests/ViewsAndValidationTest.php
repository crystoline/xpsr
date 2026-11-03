<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Translation\Tests;

use Crystoline\Xpsr\Translation\Tests\Fixtures\Project;
use PHPUnit\Framework\TestCase;

final class ViewsAndValidationTest extends TestCase
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

    public function testTemplatesTranslateInTheRequestsLocale(): void
    {
        $english = $this->project->request('GET', '/view');
        self::assertSame(200, $english->getStatusCode(), (string) $english->getBody());
        self::assertSame("Welcome, Ada! (en)\n", (string) $english->getBody());
        $french = $this->project->request('GET', '/view', ['Accept-Language' => 'fr']);
        self::assertSame("Bienvenue, Ada ! (fr)\n", (string) $french->getBody());
    }

    public function testValidationMessagesComeInTheRequestsLocale(): void
    {
        $english = $this->project->request('POST', '/validate', [], [], ['title' => '', 'age' => 12]);
        self::assertSame(422, $english->getStatusCode(), (string) $english->getBody());
        $errors = json_decode((string) $english->getBody(), true)['errors'] ?? null;
        self::assertSame(['title' => ['This value should not be blank.'], 'age' => ['This value should be 18 or more.']], $errors);

        $french = $this->project->request('POST', '/validate', ['Accept-Language' => 'fr'], [], ['title' => '', 'age' => 12]);
        self::assertSame(422, $french->getStatusCode());
        $errors = json_decode((string) $french->getBody(), true)['errors'] ?? null;
        self::assertSame(['title' => ['Cette valeur ne doit pas être vide.'], 'age' => ['Cette valeur doit être supérieure ou égale à 18.']], $errors);

        $portuguese = $this->project->request('POST', '/validate', ['Accept-Language' => 'pt-BR'], [], ['title' => '', 'age' => 30]);
        self::assertSame(422, $portuguese->getStatusCode());
        self::assertSame(['title' => ['Este valor não deve ser vazio.']], json_decode((string) $portuguese->getBody(), true)['errors'] ?? null, 'Symfony\'s pt_BR translation.');

        $valid = $this->project->request('POST', '/validate', ['Accept-Language' => 'fr'], [], ['title' => 'Ok', 'age' => 30]);
        self::assertSame(200, $valid->getStatusCode());
    }
}
