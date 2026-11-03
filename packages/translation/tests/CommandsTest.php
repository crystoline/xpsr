<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Translation\Tests;

use Crystoline\Xpsr\Translation\Console\MakeLangCommand;
use Crystoline\Xpsr\Translation\Console\MissingCommand;
use Crystoline\Xpsr\Translation\Tests\Fixtures\Project;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class CommandsTest extends TestCase
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

    public function testMissingListsTheKeysOtherLocalesLack(): void
    {
        $tester = new CommandTester($this->project->application->get(MissingCommand::class));
        self::assertSame(Command::FAILURE, $tester->execute([]));
        $display = $tester->getDisplay();
        self::assertStringContainsString("fr: messages.nav.about\n", $display);
        self::assertStringContainsString("fr: messages.only_en\n", $display);
        self::assertStringContainsString("fr: emails.receipt.subject\n", $display);
        self::assertStringContainsString("pt_BR: messages.welcome\n", $display);
        self::assertStringNotContainsString('validators', $display, 'Symfony\'s validation messages are not the application\'s to translate.');
        self::assertStringContainsString('11 keys missing.', $display);

        self::assertSame(Command::FAILURE, $tester->execute(['locale' => 'fr']));
        self::assertStringContainsString('4 keys missing.', $tester->getDisplay());
        self::assertStringNotContainsString('pt_BR', $tester->getDisplay());
        self::assertSame(Command::SUCCESS, $tester->execute(['locale' => 'en']), 'app.locale against itself: nothing to compare.');
    }

    public function testMakeLangWritesAThingToTranslateFromTheDefaultLocale(): void
    {
        $tester = new CommandTester($this->project->application->get(MakeLangCommand::class));
        self::assertSame(Command::SUCCESS, $tester->execute(['locale' => 'de']));
        $display = $tester->getDisplay();
        self::assertStringContainsString("Created {$this->project->root}/lang/de/messages.php.", $display);
        self::assertStringContainsString("Created {$this->project->root}/lang/de/emails.php.", $display);
        self::assertStringContainsString("Add 'de' to translation.locales", $display);
        $messages = require $this->project->root . '/lang/de/messages.php';
        self::assertSame(['welcome' => 'Welcome, %name%!', 'nav' => ['home' => 'Home', 'about' => 'About'], 'only_en' => 'English only', 'locale' => 'en'], $messages);
        self::assertSame(['receipt' => ['subject' => 'Your receipt', 'body' => 'Thank you, %name%.']], require $this->project->root . '/lang/de/emails.php');
        self::assertStringContainsString("declare(strict_types=1);", (string) file_get_contents($this->project->root . '/lang/de/messages.php'));

        self::assertSame(Command::SUCCESS, $tester->execute(['locale' => 'fr']));
        self::assertStringContainsString("Kept {$this->project->root}/lang/fr/messages.php.", $tester->getDisplay(), 'Existing files are left alone.');
        self::assertStringContainsString("Created {$this->project->root}/lang/fr/emails.php.", $tester->getDisplay());
        self::assertStringNotContainsString("Add 'fr'", $tester->getDisplay());

        self::assertSame(Command::INVALID, $tester->execute(['locale' => 'en']));
        self::assertStringContainsString('en is app.locale', $tester->getDisplay());
        self::assertSame(Command::INVALID, $tester->execute(['locale' => 'not a locale']));
    }
}
