<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Testing;

use Crystoline\Xpsr\View\ViewFunctionsInterface;
use Crystoline\Xpsr\View\ViewHandlerInterface;
use Crystoline\Xpsr\View\ViewNotFoundException;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The behavior every view engine must provide, so applications and the view provider's template
 * functions (csrf_field(), auth_user(), can(), ...) work the same on each.
 *
 * An engine package extends this class in its own tests: createHandler() builds its handler,
 * extension() names its template files, and templates() spells four small templates in its
 * syntax. Results are compared without surrounding whitespace.
 */
abstract class ViewHandlerContractTestCase extends TestCase
{
    private string $directory;
    private string $cache;

    /** A handler for the templates in $directory, keeping compiled templates in $cache. */
    abstract protected function createHandler(string $directory, string $cache): ViewHandlerInterface&ViewFunctionsInterface;

    /** The template file extension without the leading dot, such as "twig" or "blade.php". */
    abstract protected function extension(): string;

    /**
     * Four templates in the engine's syntax:
     *
     *   greeting   "Hello, ", the variable $name escaped, then "!"
     *   html       the result of badge(), a function added with $html true
     *   text       the result of shout($text), a function added without $html, escaped
     *   arguments  the result of add(2, 3)
     *
     * @return array{greeting: string, html: string, text: string, arguments: string}
     */
    abstract protected function templates(): array;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/xpsr view ' . bin2hex(random_bytes(6));
        $this->directory = $base . '/views';
        $this->cache = $base . '/cache';
        mkdir($this->directory . '/emails', 0777, true);
        mkdir($this->cache, 0777, true);
        $extension = $this->extension();
        foreach ($this->templates() as $name => $source) {
            file_put_contents("{$this->directory}/{$name}.{$extension}", $source);
        }
        file_put_contents("{$this->directory}/emails/welcome.{$extension}", $this->templates()['greeting']);
    }

    protected function tearDown(): void
    {
        $base = dirname($this->directory);
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($base);
    }

    public function testVariablesAreEscaped(): void
    {
        self::assertSame('Hello, &lt;b&gt;Ada &amp; Bob&lt;/b&gt;!', $this->render($this->handler(), 'greeting', ['name' => '<b>Ada & Bob</b>']));
    }

    public function testDotsInViewNamesAreDirectories(): void
    {
        self::assertSame('Hello, Ada!', $this->render($this->handler(), 'emails.welcome', ['name' => 'Ada']));
    }

    public function testEachRenderHasOnlyItsOwnData(): void
    {
        $handler = $this->handler();
        self::assertSame('Hello, Ada!', $this->render($handler, 'greeting', ['name' => 'Ada']));
        self::assertSame('Hello, Grace!', $this->render($handler, 'greeting', ['name' => 'Grace']));
    }

    public function testHtmlFunctionsArePrintedAsTheyAre(): void
    {
        $handler = $this->handler();
        $handler->addFunction('badge', static fn(): string => '<strong>new</strong>', html: true);
        self::assertSame('<strong>new</strong>', $this->render($handler, 'html'));
    }

    public function testOtherFunctionsAreEscaped(): void
    {
        $handler = $this->handler();
        $handler->addFunction('shout', static fn(string $text): string => strtoupper($text));
        self::assertSame('&lt;I&gt;HI&lt;/I&gt;', $this->render($handler, 'text', ['text' => '<i>hi</i>']));
    }

    public function testFunctionsTakeArguments(): void
    {
        $handler = $this->handler();
        $handler->addFunction('add', static fn(int $a, int $b): int => $a + $b);
        self::assertSame('5', $this->render($handler, 'arguments'));
    }

    public function testACacheDirectoryThatDoesNotExistYetIsCreated(): void
    {
        // New projects name var/cache/views, and var/cache may not exist either.
        $handler = $this->createHandler($this->directory, $this->cache . '/var/cache/views');
        self::assertSame('Hello, Ada!', $this->render($handler, 'greeting', ['name' => 'Ada']));
    }

    public function testAMissingViewThrowsViewNotFound(): void
    {
        try {
            $this->handler()->render('missing.page');
            self::fail('A missing view rendered.');
        } catch (ViewNotFoundException $exception) {
            self::assertSame('missing.page', $exception->view);
            self::assertStringContainsString('The view "missing.page" has no template', $exception->getMessage());
        }
    }

    private function handler(): ViewHandlerInterface&ViewFunctionsInterface
    {
        return $this->createHandler($this->directory, $this->cache);
    }

    /** @param array<string, mixed> $data */
    private function render(ViewHandlerInterface $handler, string $view, array $data = []): string
    {
        return trim($handler->render($view, $data));
    }
}
