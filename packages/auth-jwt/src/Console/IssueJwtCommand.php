<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Auth\Jwt\Console;

use Crystoline\Xpsr\Auth\AuthManager;
use Crystoline\Xpsr\Auth\Jwt\JwtIssuer;
use InvalidArgumentException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Issues a JWT for a user, found by login, for trying an API by hand.
 *
 * @internal Not covered by the backward-compatibility promise; see docs/VERSIONING.md.
 */
final class IssueJwtCommand extends Command
{
    public function __construct(private readonly AuthManager $auth, private readonly JwtIssuer $issuer)
    {
        parent::__construct('auth:jwt');
    }

    protected function configure(): void
    {
        $this->setDescription('Issue a JWT for a user.')
            ->addArgument('login', InputArgument::REQUIRED, 'The user\'s login, such as an email address.')
            ->addOption('scope', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'A scope the token allows; repeat for several. Default: * (everything).')
            ->addOption('ttl', null, InputOption::VALUE_REQUIRED, 'How long the token is valid, such as "15 minutes". Default: auth.jwt.ttl.')
            ->addOption('guard', null, InputOption::VALUE_REQUIRED, 'The jwt guard the token is for; its provider has the user. Default: the first jwt guard.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errors = (new SymfonyStyle($input, $output))->getErrorStyle();
        $guard = $input->getOption('guard');
        $guard = is_string($guard) ? $guard : ($this->auth->options()->guardsWithDriver('jwt')[0] ?? null);
        try {
            $users = $this->auth->users($this->auth->options()->provider((string) $guard));
        } catch (InvalidArgumentException $exception) {
            $errors->error($guard === null ? 'No guard in auth.guards uses the jwt driver.' : $exception->getMessage());

            return self::INVALID;
        }
        $user = $users->findByLogin((string) $input->getArgument('login'));
        if ($user === null) {
            $errors->error('There is no user with that login.');

            return self::FAILURE;
        }
        $scopes = $input->getOption('scope');
        $ttl = $input->getOption('ttl');
        try {
            $token = $this->issuer->issue($user, is_array($scopes) && $scopes !== [] ? array_values($scopes) : ['*'], is_string($ttl) ? $ttl : null, $guard);
        } catch (InvalidArgumentException $exception) {
            $errors->error($exception->getMessage());

            return self::INVALID;
        }
        $output->writeln($token->token);
        $errors->writeln('Valid until ' . $token->expiresAt->format('Y-m-d H:i T') . '. Send it as: Authorization: Bearer <token>');

        return self::SUCCESS;
    }
}
