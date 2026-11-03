<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Translation\Console;

use Crystoline\Xpsr\Translation\TranslationOptions;
use Crystoline\Xpsr\Translation\Translator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Writes the message files of a new locale from app.locale's: the same keys, with app.locale's
 * texts as the values to translate. Existing files are left alone.
 */
final class MakeLangCommand extends Command
{
    public function __construct(private readonly Translator $translator, private readonly TranslationOptions $options)
    {
        parent::__construct('make:lang');
    }

    protected function configure(): void
    {
        $this->setDescription('Create the message files of a locale from app.locale\'s.')
            ->addArgument('locale', InputArgument::REQUIRED, 'The locale, such as fr or pt_BR.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $locale = str_replace('-', '_', (string) $input->getArgument('locale'));
        if (preg_match('/\A[a-z]{2,3}(_[A-Za-z0-9]{2,8})*\z/i', $locale) !== 1) {
            $io->getErrorStyle()->error("\"{$locale}\" is not a locale such as fr or pt_BR.");

            return self::INVALID;
        }
        if ($locale === $this->options->locale) {
            $io->getErrorStyle()->error("{$locale} is app.locale: its files are the source.");

            return self::INVALID;
        }
        $directory = $this->options->path . '/' . $locale;
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            $io->getErrorStyle()->error("Cannot create {$directory}.");

            return self::FAILURE;
        }
        $written = 0;
        foreach ($this->translator->domains($this->options->locale) as $domain) {
            if ($domain === 'validators') {
                continue;
            }
            $file = "{$directory}/{$domain}.php";
            if (file_exists($file)) {
                $io->writeln("Kept {$file}.");
                continue;
            }
            file_put_contents($file, self::render(self::nest($this->translator->messages($domain, $this->options->locale))));
            $io->writeln("Created {$file}.");
            ++$written;
        }
        if ($written === 0 && $this->translator->domains($this->options->locale) === []) {
            $io->writeln(sprintf('%s has no message files in %s: nothing to copy.', $this->options->locale, $this->options->path));
        }
        if (!in_array($locale, $this->options->locales, true)) {
            $io->writeln("Add '{$locale}' to translation.locales in config/translation.php.");
        }

        return self::SUCCESS;
    }

    /**
     * Keys "a.b.c" back into nested arrays.
     *
     * @param array<string, string> $messages
     * @return array<string, mixed>
     */
    private static function nest(array $messages): array
    {
        $nested = [];
        foreach ($messages as $key => $text) {
            $nested = self::insert($nested, explode('.', $key), $text);
        }

        return $nested;
    }

    /**
     * @param array<string, mixed> $into
     * @param non-empty-list<string> $path
     * @return array<string, mixed>
     */
    private static function insert(array $into, array $path, string $text): array
    {
        $head = $path[0];
        $rest = array_slice($path, 1);
        if ($rest === []) {
            $into[$head] = $text;

            return $into;
        }
        $child = $into[$head] ?? [];
        $into[$head] = self::insert(is_array($child) ? $child : [], $rest, $text);

        return $into;
    }

    /** @param array<string, mixed> $messages */
    private static function render(array $messages, int $depth = 1): string
    {
        $pad = str_repeat('    ', $depth);
        $lines = $depth === 1 ? ["<?php\n", "declare(strict_types=1);\n", "return ["] : [];
        foreach ($messages as $key => $value) {
            $lines[] = is_array($value)
                ? sprintf("%s%s => [\n%s%s],", $pad, var_export((string) $key, true), self::render($value, $depth + 1), $pad)
                : sprintf('%s%s => %s,', $pad, var_export((string) $key, true), var_export((string) $value, true));
        }
        if ($depth === 1) {
            $lines[] = "];\n";

            return implode("\n", $lines);
        }

        return implode("\n", $lines) . "\n";
    }
}
