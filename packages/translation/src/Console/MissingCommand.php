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

/** Lists the keys app.locale has that other locales lack; exits 1 when any is missing. */
final class MissingCommand extends Command
{
    public function __construct(private readonly Translator $translator, private readonly TranslationOptions $options)
    {
        parent::__construct('translation:missing');
    }

    protected function configure(): void
    {
        $this->setDescription('List the keys of app.locale that other locales do not translate.')
            ->addArgument('locale', InputArgument::OPTIONAL, 'One locale to compare; all of translation.locales by default.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $argument = $input->getArgument('locale');
        $locales = is_string($argument) && $argument !== '' ? [$argument] : array_values(array_diff($this->options->locales, [$this->options->locale]));
        if ($locales === []) {
            $io->writeln(sprintf('translation.locales has only %s: nothing to compare.', $this->options->locale));

            return self::SUCCESS;
        }
        $missing = 0;
        foreach ($locales as $locale) {
            if ($locale === $this->options->locale) {
                continue;
            }
            foreach ($this->translator->domains($this->options->locale) as $domain) {
                if ($domain === 'validators') {
                    continue;
                }
                $keys = array_diff_key($this->translator->messages($domain, $this->options->locale), $this->translator->messages($domain, $locale));
                foreach (array_keys($keys) as $key) {
                    $io->writeln(sprintf('%s: %s.%s', $locale, $domain, $key));
                    ++$missing;
                }
            }
        }
        $io->writeln($missing === 0 ? 'Every key is translated.' : sprintf('%d key%s missing.', $missing, $missing === 1 ? '' : 's'));

        return $missing === 0 ? self::SUCCESS : self::FAILURE;
    }
}
