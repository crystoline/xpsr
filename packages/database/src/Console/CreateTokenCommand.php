<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Database\Console;

use Crystoline\Xpsr\Auth\AuthManager;
use Crystoline\Xpsr\Database\Auth\TokenRepository;
use DateInterval;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Creates an API token for a user, found by login, and prints it once.
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class CreateTokenCommand extends Command
{
    public function __construct(
        private readonly AuthManager $auth,
        private readonly TokenRepository $tokens,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct('auth:token');
    }

    protected function configure(): void
    {
        $this->setDescription('Create an API token for a user.')
            ->addArgument('login', InputArgument::REQUIRED, 'The user\'s login, such as an email address.')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'What the token is for.', 'console')
            ->addOption('scope', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'A scope the token allows; repeat for several. Default: * (everything).')
            ->addOption('expires', null, InputOption::VALUE_REQUIRED, 'How long the token lasts, such as "90 days". Default: until revoked.')
            ->addOption('guard', null, InputOption::VALUE_REQUIRED, 'The token guard it is for; its provider has the user. Default: the first token guard.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errors = (new SymfonyStyle($input, $output))->getErrorStyle();
        $guard = $input->getOption('guard');
        $guard = is_string($guard) ? $guard : ($this->auth->options()->guardsWithDriver('token')[0] ?? null);
        if ($guard === null || ($this->auth->options()->guards[$guard]['driver'] ?? null) !== 'token') {
            $errors->error($guard === null ? 'No guard in auth.guards uses the token driver.' : "The guard \"{$guard}\" does not use the token driver.");

            return self::INVALID;
        }
        $user = $this->auth->users($this->auth->options()->provider($guard))->findByLogin((string) $input->getArgument('login'));
        if ($user === null) {
            $errors->error('There is no user with that login.');

            return self::FAILURE;
        }
        $expires = $input->getOption('expires');
        $expiresAt = null;
        if (is_string($expires)) {
            try {
                $expiresAt = $this->clock->now()->add(DateInterval::createFromDateString($expires));
            } catch (\Exception) {
                $errors->error('--expires must be a period such as "90 days".');

                return self::INVALID;
            }
            if ($expiresAt <= $this->clock->now()) {
                $errors->error('--expires must be a period such as "90 days".');

                return self::INVALID;
            }
        }
        $scopes = $input->getOption('scope');
        try {
            $new = $this->tokens->create($user, (string) $input->getOption('name'), is_array($scopes) && $scopes !== [] ? array_values($scopes) : ['*'], $expiresAt, $guard);
        } catch (InvalidArgumentException $exception) {
            $errors->error($exception->getMessage());

            return self::INVALID;
        }
        $output->writeln(OutputFormatter::escape(sprintf(
            'Token %d for user %s on %s, scopes %s, %s:',
            $new->token->id,
            $new->token->userId,
            $new->token->guard,
            implode(' ', $new->token->scopes),
            $expiresAt === null ? 'until revoked' : 'expires ' . $expiresAt->format('Y-m-d H:i T'),
        )));
        $output->writeln($new->plainText);
        $errors->writeln('It is shown only now. Send it as: Authorization: Bearer <token>');

        return self::SUCCESS;
    }
}
