<?php

declare(strict_types=1);

use TimeFrontiers\SQLDatabase;
use TimeFrontiers\Sms\Config\SmsConfig;
use TimeFrontiers\Sms\Driver\SmsDriverInterface;
use TimeFrontiers\Sms\Dto\ParsedDeliveryReport;
use TimeFrontiers\Sms\Dto\ProviderSendResult;
use TimeFrontiers\Sms\Dto\WebhookRequest;
use TimeFrontiers\Sms\Service\SmsService;
use TimeFrontiers\Sms\Sms;

require __DIR__ . '/../../../vendor/autoload.php';

$database = getenv('SMS_TEST_DATABASE');
if (!is_string($database) || !str_ends_with($database, '_test')) throw new InvalidArgumentException('A disposable test database is required.');
$settings = [
  'host' => getenv('SMS_TEST_HOST') ?: '127.0.0.1',
  'port' => (int) (getenv('SMS_TEST_PORT') ?: 3306),
  'database' => $database,
  'user' => getenv('SMS_TEST_USER') ?: '',
  'password' => getenv('SMS_TEST_PASSWORD') ?: '',
];
$connection = new SQLDatabase($settings['host'], $settings['user'], $settings['password'], $settings['database'], true, (string) $settings['port']);
if ($connection->execute('UPDATE `sms_test_provider_attempt` SET `workers_ready` = `workers_ready` + 1 WHERE `id` = 1') === false) {
  throw new RuntimeException('Could not enter the concurrency barrier.');
}
$deadline = microtime(true) + 10.0;
do {
  $barrier = $connection->fetchOne('SELECT `workers_ready` FROM `sms_test_provider_attempt` WHERE `id` = 1');
  if (is_array($barrier) && (int) $barrier['workers_ready'] === 2) break;
  usleep(10000);
} while (microtime(true) < $deadline);
if (!is_array($barrier) || (int) $barrier['workers_ready'] !== 2) {
  throw new RuntimeException('Concurrency barrier timed out.');
}
$driver = new class($connection) implements SmsDriverInterface {
  public function __construct(private SQLDatabase $connection) {}
  public function send(Sms $sms): ProviderSendResult
  {
    if ($this->connection->execute('UPDATE `sms_test_provider_attempt` SET `attempts` = `attempts` + 1 WHERE `id` = 1') === false) {
      throw new RuntimeException('Could not record provider attempt.');
    }
    usleep(250000);
    return new ProviderSendResult('fake', 'REF-CONCURRENT', 'TEST', '0.10000000', 'USD');
  }
  public function verifyDeliveryReport(WebhookRequest $request): bool { return false; }
  public function parseDeliveryReport(WebhookRequest $request): ParsedDeliveryReport { throw new LogicException(); }
  public function getProviderName(): string { return 'fake'; }
};
$service = new SmsService($connection, SmsConfig::fromArray([
  'db_name' => $settings['database'], 'default_driver' => 'fake', 'default_sender' => 'TEST',
  'drivers' => ['fake' => ['driver' => $driver, 'default_sender' => 'TEST']],
]));
$result = $service->send(['receiver' => '+2348024296777', 'message' => 'concurrent', 'idempotency_key' => 'same-key']);
fwrite(STDOUT, $result->outcome . PHP_EOL);
exit(in_array($result->outcome, ['accepted', 'in_flight'], true) ? 0 : 1);
