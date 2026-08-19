<?php

declare(strict_types=1);

namespace TimeFrontiers\Sms\Tests\Integration;

use PHPUnit\Framework\TestCase;
use TimeFrontiers\Sms\Result\SendResult;

final class ConcurrencyIntegrationTest extends TestCase
{
  public function testConcurrentIdenticalRequestsProduceOneProviderAttempt(): void
  {
    $database = getenv('SMS_TEST_DATABASE');
    if (!is_string($database) || !str_ends_with($database, '_test')) {
      self::markTestSkipped('Set SMS_TEST_DATABASE to a disposable database ending in _test to run concurrency tests.');
    }

    $host = getenv('SMS_TEST_HOST') ?: '127.0.0.1';
    $port = (int) (getenv('SMS_TEST_PORT') ?: 3306);
    $user = getenv('SMS_TEST_USER') ?: '';
    $password = getenv('SMS_TEST_PASSWORD') ?: '';
    $mysqli = new \mysqli($host, $user, $password, $database, $port);
    $installSql = rtrim(trim((string) file_get_contents(__DIR__ . '/../../sql/install.sql')), ';');
    $mysqli->multi_query('DROP TABLE IF EXISTS `sms_delivery_event`; DROP TABLE IF EXISTS `sms_test_provider_attempt`; DROP TABLE IF EXISTS `sms`; ' . $installSql . '; CREATE TABLE `sms_test_provider_attempt` (`id` TINYINT PRIMARY KEY, `attempts` INT NOT NULL, `workers_ready` INT NOT NULL); INSERT INTO `sms_test_provider_attempt` VALUES (1, 0, 0)');
    do {
      if ($result = $mysqli->store_result()) $result->free();
    } while ($mysqli->more_results() && $mysqli->next_result());
    if ($mysqli->errno !== 0) self::fail($mysqli->error);

    $worker = __DIR__ . '/Fixtures/concurrent_send_worker.php';
    $commands = [];
    for ($i = 0; $i < 2; ++$i) {
      $workerPipes = [];
      $commands[] = proc_open([PHP_BINARY, $worker], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $workerPipes);
      $pipes[] = $workerPipes;
    }

    $outcomes = [];
    foreach ($commands as $index => $process) {
      self::assertIsResource($process);
      $stdout = stream_get_contents($pipes[$index][1]);
      $stderr = stream_get_contents($pipes[$index][2]);
      self::assertSame(0, proc_close($process), $stdout . $stderr);
      $outcomes[] = trim($stdout);
    }

    $attemptResult = $mysqli->query('SELECT `attempts` FROM `sms_test_provider_attempt` WHERE `id` = 1');
    $messageResult = $mysqli->query('SELECT COUNT(*) AS `count` FROM `sms`');
    if (!$attemptResult instanceof \mysqli_result || !$messageResult instanceof \mysqli_result) {
      self::fail($mysqli->error);
    }
    $attempts = $attemptResult->fetch_assoc();
    $messages = $messageResult->fetch_assoc();
    if (!is_array($attempts) || !is_array($messages)) self::fail('Integration result rows are missing.');
    self::assertSame('1', $attempts['attempts']);
    self::assertSame('1', $messages['count']);
    sort($outcomes);
    self::assertSame([SendResult::ACCEPTED, SendResult::IN_FLIGHT], $outcomes);
    $mysqli->close();
  }
}
