<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Translation\Tests;

use Crystoline\Xpsr\Translation\Tests\Fixtures\Project;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class TranslatorTest extends TestCase
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

    public function testKeysNameTheirDomainOrUseTheDefaultOne(): void
    {
        $translator = $this->project->translator();
        self::assertSame('en', $translator->locale());
        self::assertSame('Welcome, Ada!', $translator->trans('messages.welcome', ['name' => 'Ada']));
        self::assertSame('Welcome, Ada!', $translator->trans('welcome', ['name' => 'Ada']), 'The default domain.');
        self::assertSame('Welcome, Ada!', $translator->trans('welcome', ['%name%' => 'Ada']), 'Already wrapped.');
        self::assertSame('Home', $translator->trans('messages.nav.home'));
        self::assertSame('Home', $translator->trans('nav.home'));
        self::assertSame('Your receipt', $translator->trans('emails.receipt.subject'));
        self::assertSame('Thank you, Ada.', $translator->trans('receipt.body', ['name' => 'Ada'], domain: 'emails'));
        self::assertSame('messages.nope', $translator->trans('messages.nope'), 'A missing key comes back as given.');
        self::assertSame('nope.at.all', $translator->trans('nope.at.all'));
        self::assertTrue($translator->has('messages.welcome'));
        self::assertTrue($translator->has('emails.receipt.subject'));
        self::assertFalse($translator->has('messages.nope'));
        self::assertEqualsCanonicalizing(['validators', 'messages', 'emails'], $translator->domains());
        self::assertSame(['welcome' => 'Welcome, %name%!', 'nav.home' => 'Home', 'nav.about' => 'About', 'only_en' => 'English only', 'locale' => 'en'], $translator->messages('messages'));
        self::assertSame($translator->symfony(), $this->project->application->get(TranslatorInterface::class));
    }

    public function testOtherLocalesFallBackToTheDefaultOne(): void
    {
        $translator = $this->project->translator();
        self::assertSame('Bienvenue, Ada !', $translator->trans('messages.welcome', ['name' => 'Ada'], 'fr'));
        self::assertSame('About', $translator->trans('messages.nav.about', [], 'fr'), 'Missing in fr: the fallback locale.');
        self::assertSame('Your receipt', $translator->trans('emails.receipt.subject', [], 'fr'), 'A whole domain missing in fr.');
        self::assertSame('Welcome, Ada!', $translator->trans('messages.welcome', ['name' => 'Ada'], 'pt_BR'), 'No files at all.');
        self::assertTrue($translator->has('messages.nav.about', 'fr'), 'Through the fallback.');

        $translator->setLocale('fr');
        self::assertSame('fr', $translator->locale());
        self::assertSame('fr', $this->project->locale()->get());
        self::assertSame('Accueil', $translator->trans('nav.home'));
        self::assertSame('fr', $translator->symfony()->getLocale(), 'Symfony\'s translator follows.');
        $this->project->locale()->reset();
        self::assertSame('en', $translator->locale());
        self::assertSame('en', $translator->symfony()->getLocale());
    }

    public function testTheIcuFormatHasPluralsWhenIntlIsLoaded(): void
    {
        if (!extension_loaded('intl')) {
            self::markTestSkipped('ext-intl is not loaded.');
        }
        $this->project->remove();
        $this->project = new Project(['path' => 'lang-icu', 'format' => 'icu', 'locales' => ['en']]);
        $this->project->boot();
        $translator = $this->project->translator();
        self::assertSame('Welcome, Ada!', $translator->trans('messages.welcome', ['name' => 'Ada']));
        self::assertSame('No items', $translator->trans('messages.items', ['count' => 0]));
        self::assertSame('1 item', $translator->trans('messages.items', ['count' => 1]));
        self::assertSame('3 items', $translator->trans('messages.items', ['count' => 3]));
        self::assertTrue($translator->has('messages.items'));
    }

    public function testTheSimpleFormatIsTheDefaultWithoutIntl(): void
    {
        $this->project->remove();
        $this->project = new Project(['format' => null]);
        $this->project->boot();
        self::assertSame(extension_loaded('intl') ? 'icu' : 'simple', $this->project->application->get(\Crystoline\Xpsr\Translation\TranslationOptions::class)->format);
    }
}
