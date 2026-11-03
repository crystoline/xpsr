<?php

declare(strict_types=1);

namespace Crystoline\Xpsr\Health\Tests;

use Crystoline\Xpsr\Health\Console\HealthCommand;
use Crystoline\Xpsr\Health\Tests\Fixtures\FailingJob;
use Crystoline\Xpsr\Health\Tests\Fixtures\Project;
use Crystoline\Xpsr\Queue\Failed\FailedJobStore;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class EndpointsAndCommandTest extends TestCase
{
    private Project $project;

    protected function setUp(): void
    {
        $this->project = new Project(['secret' => 's3cret', 'cache' => 0]);
        $this->project->boot();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testLiveAnswersAsSoonAsTheApplicationBoots(): void
    {
        $response = $this->project->request('GET', '/health');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['status' => 'ok'], $this->json($response));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
    }

    public function testReadyAnswers503WhileAChecksFailsAndShowsDetailsOnlyToThoseAllowed(): void
    {
        $public = $this->project->request('GET', '/health/ready');
        self::assertSame(503, $public->getStatusCode());
        self::assertSame('no-store', $public->getHeaderLine('Cache-Control'));
        $body = $this->json($public);
        self::assertSame('failing', $body['status']);
        self::assertSame(['status' => 'failing'], $body['checks']['workers'], 'The public sees statuses alone.');
        self::assertSame('ok', $body['checks']['database']['status']);

        $wrong = $this->json($this->project->request('GET', '/health/ready', ['X-Health-Secret' => 'guess']));
        self::assertArrayNotHasKey('message', $wrong['checks']['workers']);

        $secret = $this->json($this->project->request('GET', '/health/ready', ['X-Health-Secret' => 's3cret']));
        self::assertSame('No worker has reported on "db"', $secret['checks']['workers']['message']);
        self::assertSame(['db' => ['last_seen' => null]], $secret['checks']['workers']['details']);
        self::assertArrayHasKey('milliseconds', $secret['checks']['database']);

        $query = $this->json($this->project->request('GET', '/health/ready?secret=s3cret'));
        self::assertArrayHasKey('message', $query['checks']['workers']);

        $private = $this->json($this->project->request('GET', '/health/ready', [], '192.168.1.20'));
        self::assertArrayHasKey('message', $private['checks']['workers'], 'A private address sees the details.');

        $proxied = $this->json($this->project->request('GET', '/health/ready', ['X-Forwarded-For' => '203.0.113.7'], '10.0.0.1'));
        self::assertArrayNotHasKey('message', $proxied['checks']['workers'], 'Behind the trusted proxy, the client is public.');

        $this->project->heartbeats();
        $ready = $this->project->request('GET', '/health/ready');
        self::assertSame(200, $ready->getStatusCode());
        self::assertSame('ok', $this->json($ready)['status']);
    }

    public function testTheCommandPrintsTheChecksAndExitsOnFailure(): void
    {
        $tester = new CommandTester($this->project->application->get(HealthCommand::class));
        self::assertSame(Command::FAILURE, $tester->execute([]));
        $display = $tester->getDisplay();
        self::assertStringContainsString('database', $display);
        self::assertStringContainsString('No worker has reported on "db"', $display);
        self::assertStringContainsString('Status: failing', $display);

        self::assertSame(Command::FAILURE, $tester->execute(['--json' => true]));
        $json = json_decode($tester->getDisplay(), true);
        self::assertIsArray($json);
        self::assertSame('failing', $json['status']);
        self::assertSame('schedule:run has not reported yet', $json['checks']['scheduler']['message']);

        $this->project->heartbeats();
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Status: ok', $tester->getDisplay());

        $this->project->application->get(FailedJobStore::class)->add('db', new FailingJob(), new RuntimeException('Broken.'));
        self::assertSame(Command::SUCCESS, $tester->execute([]), 'A warning is not a failure.');
        self::assertStringContainsString('Status: warning', $tester->getDisplay());
        self::assertSame(Command::FAILURE, $tester->execute(['--strict' => true]));
    }

    /** @return array<string, mixed> */
    private function json(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
