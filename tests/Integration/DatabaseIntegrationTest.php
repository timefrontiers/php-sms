<?php

declare(strict_types=1);

namespace TimeFrontiers\Sms\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TimeFrontiers\SQLDatabase;
use TimeFrontiers\Sms\Config\SmsConfig;
use TimeFrontiers\Sms\Result\SendResult;
use TimeFrontiers\Sms\Service\SmsService;
use TimeFrontiers\Sms\Tests\Support\FakeDriver;

final class DatabaseIntegrationTest extends TestCase
{
  #[DataProvider('drivers')]
  public function testMySqliAndPdoPersistTheSameSafeSendState(string $databaseDriver): void
  {
    $settings = $this->settings();
    $this->installSchema($settings);
    $connection = $databaseDriver === 'mysqli'
      ? new SQLDatabase($settings['host'], $settings['user'], $settings['password'], $settings['database'], true, (string) $settings['port'])
      : SQLDatabase::pdo('mysql', $settings['host'], $settings['port'], $settings['database'], $settings['user'], $settings['password']);
    $driver = new FakeDriver();
    $service = new SmsService($connection, SmsConfig::fromArray([
      'db_name' => $settings['database'], 'default_driver' => 'fake', 'default_sender' => 'TEST',
      'drivers' => ['fake' => ['driver' => $driver, 'default_sender' => 'TEST']],
    ]));

    $result = $service->send([
      'receiver' => '+2348024296777', 'message' => 'integration',
      'idempotency_key' => $databaseDriver . '-integration',
    ]);

    self::assertSame(SendResult::ACCEPTED, $result->outcome);
    self::assertSame('0.12500000', $result->sms?->feesExact());
  }

  public function testCallerTransactionSurvivesRejectedReplay(): void
  {
    $settings = $this->settings();
    $this->installSchema($settings);
    $connection = $this->connection($settings);
    $service = $this->service($connection, $settings['database']);
    $request = [
      'receiver' => '+2348024296777', 'message' => 'transaction boundary',
      'idempotency_key' => 'transaction-replay',
    ];
    self::assertSame(SendResult::ACCEPTED, $service->send($request)->outcome);

    self::assertNotFalse($connection->execute('CREATE TABLE `sms_test_application_row` (`id` INT PRIMARY KEY) ENGINE=InnoDB'));
    self::assertTrue($connection->beginTransaction());
    self::assertNotFalse($connection->execute('INSERT INTO `sms_test_application_row` (`id`) VALUES (1)'));
    self::assertSame(SendResult::REJECTED, $service->send($request)->outcome);
    self::assertTrue($connection->commit());
    $row = $connection->fetchOne('SELECT COUNT(*) AS `count` FROM `sms_test_application_row`');
    self::assertIsArray($row);
    self::assertSame('1', (string) $row['count']);
  }

  public function testRecoveryUsesDatabaseClockWhenSessionTimezoneDiffersFromPhp(): void
  {
    $settings = $this->settings();
    $this->installSchema($settings);
    $connection = $this->connection($settings);
    $service = $this->service($connection, $settings['database'], 3600);
    self::assertNotFalse($connection->execute("SET SESSION time_zone = '-04:00'"));
    self::assertNotFalse($connection->execute(
      "INSERT INTO `sms` (`code`, `status`, `provider`, `idempotency_scope`, `idempotency_key_hash`, `sender`, `receiver`, `message`, `dispatch_started_at`) VALUES ('828000000000001', 'dispatching', 'fake', 'test', UNHEX(SHA2('clock-skew', 256)), 'TEST', '+2348024296777', 'clock skew', CURRENT_TIMESTAMP(6))"
    ));

    $previousTimezone = date_default_timezone_get();
    date_default_timezone_set('Africa/Lagos');
    try {
      self::assertSame([], $service->recoverStale()->items);
    } finally {
      date_default_timezone_set($previousTimezone);
    }
  }

  /** @return iterable<string, array{string}> */
  public static function drivers(): iterable
  {
    yield 'MySQLi' => ['mysqli'];
    yield 'PDO MySQL' => ['pdo'];
  }

  /** @return array{host: string, port: int, database: string, user: string, password: string} */
  private function settings(): array
  {
    $database = getenv('SMS_TEST_DATABASE');
    if (!is_string($database) || !str_ends_with($database, '_test')) {
      self::markTestSkipped('Set SMS_TEST_DATABASE to a disposable database ending in _test to run SQL integration tests.');
    }
    return [
      'host' => getenv('SMS_TEST_HOST') ?: '127.0.0.1',
      'port' => (int) (getenv('SMS_TEST_PORT') ?: 3306),
      'database' => $database,
      'user' => getenv('SMS_TEST_USER') ?: '',
      'password' => getenv('SMS_TEST_PASSWORD') ?: '',
    ];
  }

  /** @param array{host: string, port: int, database: string, user: string, password: string} $settings */
  private function installSchema(array $settings): void
  {
    $mysqli = new \mysqli($settings['host'], $settings['user'], $settings['password'], $settings['database'], $settings['port']);
    $installSql = rtrim(trim((string) file_get_contents(__DIR__ . '/../../sql/install.sql')), ';');
    $mysqli->multi_query('DROP TABLE IF EXISTS `sms_delivery_event`; DROP TABLE IF EXISTS `sms_test_application_row`; DROP TABLE IF EXISTS `sms`; ' . $installSql);
    do {
      if ($result = $mysqli->store_result()) $result->free();
    } while ($mysqli->more_results() && $mysqli->next_result());
    if ($mysqli->errno !== 0) self::fail($mysqli->error);
    $mysqli->close();
  }

  /** @param array{host: string, port: int, database: string, user: string, password: string} $settings */
  private function connection(array $settings): SQLDatabase
  {
    return new SQLDatabase($settings['host'], $settings['user'], $settings['password'], $settings['database'], true, (string) $settings['port']);
  }

  private function service(SQLDatabase $connection, string $database, int $staleSeconds = 300): SmsService
  {
    return new SmsService($connection, SmsConfig::fromArray([
      'db_name' => $database, 'default_driver' => 'fake', 'default_sender' => 'TEST',
      'stale_dispatch_seconds' => $staleSeconds,
      'drivers' => ['fake' => ['driver' => new FakeDriver(), 'default_sender' => 'TEST']],
    ]));
  }
}
