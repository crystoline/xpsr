<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Translation;

use Symfony\Component\Translation\MessageCatalogue;
use Symfony\Component\Translation\Translator as SymfonyTranslator;

/**
 * Translates keys from the message files:
 *
 *     $translator->trans('messages.welcome', ['name' => 'Ada']);   // lang/<locale>/messages.php, key "welcome"
 *     $translator->trans('welcome', ['name' => 'Ada']);            // the same: the default domain
 *     $translator->trans('emails.receipt.subject');                // lang/<locale>/emails.php, key "receipt.subject"
 *
 * A key's first segment names the domain (the file) when such a file exists; otherwise the
 * key is looked up in translation.domain. A key without a translation comes back as given,
 * after the fallback locale was tried. Parameters fill {name} placeholders in the ICU format
 * and %name% ones in the simple format.
 */
final class Translator
{
    public function __construct(
        private readonly SymfonyTranslator $translator,
        private readonly CurrentLocale $current,
        private readonly string $defaultDomain,
        private readonly bool $icu,
    ) {}

    /** @param array<string, mixed> $parameters */
    public function trans(string $key, array $parameters = [], ?string $locale = null, ?string $domain = null): string
    {
        [$domain, $id] = $this->resolve($key, $domain, $locale);
        if (!$this->known($id, $domain, $locale)) {
            return $key;
        }
        if (!$this->icu) {
            $wrapped = [];
            foreach ($parameters as $name => $value) {
                $wrapped[str_starts_with($name, '%') ? $name : "%{$name}%"] = $value;
            }
            $parameters = $wrapped;
        }

        return $this->translator->trans($id, $parameters, $domain, $locale ?? $this->current->get());
    }

    /** Whether the key has a translation in the locale or its fallbacks. */
    public function has(string $key, ?string $locale = null, ?string $domain = null): bool
    {
        [$domain, $id] = $this->resolve($key, $domain, $locale);

        return $this->known($id, $domain, $locale);
    }

    /** The current locale, as CurrentLocale has it. */
    public function locale(): string
    {
        return $this->current->get();
    }

    public function setLocale(string $locale): void
    {
        $this->current->set($locale);
    }

    /** The domains with messages in a locale or its fallbacks, such as ["messages", "emails", "validators"]. @return list<string> */
    public function domains(?string $locale = null): array
    {
        $domains = [];
        for ($catalogue = $this->translator->getCatalogue($locale ?? $this->current->get()); $catalogue !== null; $catalogue = $catalogue->getFallbackCatalogue()) {
            $domains = [...$domains, ...$catalogue->getDomains()];
        }

        return array_values(array_unique($domains));
    }

    /** The messages of a domain in a locale, flattened keys to texts. @return array<string, string> */
    public function messages(string $domain, ?string $locale = null): array
    {
        return $this->translator->getCatalogue($locale ?? $this->current->get())->all($domain);
    }

    /** Symfony's translator underneath, for its own API. */
    public function symfony(): SymfonyTranslator
    {
        return $this->translator;
    }

    private function known(string $id, string $domain, ?string $locale): bool
    {
        $catalogue = $this->translator->getCatalogue($locale ?? $this->current->get());

        return $catalogue->has($id, $domain . MessageCatalogue::INTL_DOMAIN_SUFFIX) || $catalogue->has($id, $domain);
    }

    /** @return array{string, string} the domain and the message ID */
    private function resolve(string $key, ?string $domain, ?string $locale): array
    {
        if ($domain !== null) {
            return [$domain, $key];
        }
        $dot = strpos($key, '.');
        if ($dot !== false && $dot > 0) {
            $first = substr($key, 0, $dot);
            if (in_array($first, $this->domains($locale), true)) {
                return [$first, substr($key, $dot + 1)];
            }
        }

        return [$this->defaultDomain, $key];
    }
}
