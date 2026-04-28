<?php

declare(strict_types=1);

namespace TimeFrontiers\Sms;

use TimeFrontiers\Helper\DatabaseObject;
use TimeFrontiers\Helper\Pagination;
use TimeFrontiers\Helper\HasErrors;
use TimeFrontiers\SQLDatabase;
use TimeFrontiers\Data\Random;
use TimeFrontiers\Validator\Validator;
use TimeFrontiers\Phone;
use TimeFrontiers\Sms\Driver\SmsDriverInterface;
use TimeFrontiers\Sms\Driver\AfricasTalkingDriver;
use TimeFrontiers\Sms\Driver\TwilioDriver;

/**
 * Core SMS entity for timefrontiers/php-sms.
 *
 * ── Bootstrap (once, in your app boot file) ─────────────────────────────────
 *
 *   Sms::configure([
 *       'db_name'         => 'messaging',
 *       'default_driver'  => 'twilio',
 *       'default_sender'  => 'MyApp',
 *       'region_strategy' => 'auto',
 *       'continent_mapping' => [
 *           'Africa' => 'africastalking',
 *       ],
 *       'drivers' => [
 *           'twilio' => [
 *               'sid'          => 'AC...',
 *               'token'        => '...',
 *               'sender_id'    => 'MyApp',
 *               'sender_phone' => '+1234567890',
 *           ],
 *           'africastalking' => [
 *               'app_id'   => '...',
 *               'api_key'  => '...',
 *               'sender_id'=> 'MyApp',
 *           ],
 *       ],
 *   ]);
 *
 * ── Send ────────────────────────────────────────────────────────────────────
 *
 *   $sms = Sms::send([
 *       'receiver' => '+2348024296777',
 *       'message'  => 'Your OTP is 123456',
 *   ]);
 *
 *   if ($sms) {
 *       echo $sms->code();
 *   } else {
 *       $ie = new \TimeFrontiers\InstanceError($sms, true);
 *       echo $ie->first();
 *   }
 *
 * ── Delivery webhook ────────────────────────────────────────────────────────
 *
 *   $updated = Sms::processDeliveryReport('twilio', $_POST);
 *
 * ── Lookups ─────────────────────────────────────────────────────────────────
 *
 *   $sms = Sms::findByCode('828123456789012');
 *   $sms = Sms::findByReference('SM...');
 *
 * ── Query / Pagination (via DatabaseObject + Pagination traits) ─────────────
 *
 *   $pending = Sms::query()
 *       ->where('status', 'pending')
 *       ->orderBy('_created')
 *       ->limit(50)
 *       ->get();
 */
class Sms
{
  use HasErrors;
  use Pagination;
  use DatabaseObject {
    DatabaseObject::_create as _traitCreate;
  }

  // ---------------------------------------------------------------------------
  // DatabaseObject config
  // ---------------------------------------------------------------------------

  protected static string $_db_name;
  protected static string $_table_name  = 'sms';
  protected static string $_primary_key = 'id';

  protected static array $_db_fields = [
    'id', 'code', 'status', 'message_id', 'direction',
    'user', 'batch', 'sender', 'receiver', 'message',
    '_message_pages', 'fees_currency', 'fees', 'reference', 'meta',
    '_created', '_updated'
  ];

  // ---------------------------------------------------------------------------
  // Status / direction constants
  // ---------------------------------------------------------------------------

  const STATUS_PENDING   = 'pending';
  const STATUS_QUEUED    = 'queued';
  const STATUS_SENT      = 'sent';
  const STATUS_FAILED    = 'failed';
  const STATUS_DELIVERED = 'delivered';

  const DIRECTION_OUTBOUND = 'outbound';
  const DIRECTION_INBOUND  = 'inbound';

  // ---------------------------------------------------------------------------
  // Code generation
  // ---------------------------------------------------------------------------

  /** Prefix for `sms.code`. */
  const CODE_PREFIX = '828';

  /** Total length of the code (prefix + random digits). */
  const CODE_LENGTH = 15;

  // ---------------------------------------------------------------------------
  // Properties (match DB columns)
  // ---------------------------------------------------------------------------

  protected ?int    $id              = null;
  protected ?string $code            = null;
  protected string  $status          = self::STATUS_PENDING;
  protected ?int    $message_id      = null;
  protected string  $direction       = self::DIRECTION_OUTBOUND;
  protected string  $user            = 'SYSTEM';
  protected ?string $batch           = null;
  protected string  $sender          = '';
  protected string  $receiver        = '';
  protected string  $message         = '';
  protected int     $_message_pages  = 1;
  protected ?string $fees_currency   = null;
  protected float   $fees            = 0.0;
  protected ?string $reference       = null;
  protected ?string $meta            = null;
  protected string  $_created        = '';
  protected string  $_updated        = '';

  // ---------------------------------------------------------------------------
  // Static configuration
  // ---------------------------------------------------------------------------

  /**
   * @var array|null Configuration set by `configure()`.
   */
  private static ?array $config = null;

  // ---------------------------------------------------------------------------
  // Bootstrap (static — call once in app boot)
  // ---------------------------------------------------------------------------

  /**
   * Configure the SMS package.
   *
   * @param array{
   *   db_name: string,
   *   default_sender?: string|null,
   *   default_driver?: string,
   *   region_strategy?: string,
   *   continent_mapping?: array<string,string>,
   *   drivers?: array<string,array>
   * } $options
   */
  public static function configure(array $options): void
  {
    $defaults = [
      'db_name'          => null,
      'default_sender'   => null,
      'default_driver'   => 'twilio',
      'region_strategy'  => 'auto',
      'continent_mapping' => [
        'Africa' => 'africastalking',
      ],
      'drivers' => [],
    ];

    self::$config = array_merge($defaults, $options);

    if (!empty(self::$config['db_name'])) {
      static::$_db_name = self::$config['db_name'];
    }
  }

  // ---------------------------------------------------------------------------
  // Send
  // ---------------------------------------------------------------------------

  /**
   * Send an SMS message.
   *
   * Creates a pending record, resolves the driver, sends, and updates the record.
   *
   * @param array{
   *   receiver: string,
   *   message: string,
   *   sender?: string|null,
   *   driver?: string|null,
   *   user?: string,
   *   batch?: string|null,
   *   message_id?: int|null,
   *   direction?: string
   * } $data
   * @return static|false
   */
  public static function send(array $data): static|false
  {
    $sms = new static();

    // Populate fields
    $sms->receiver   = $data['receiver'] ?? null;
    $sms->message    = $data['message']  ?? null;
    $sms->sender     = $data['sender']   ?? (self::$config['default_sender'] ?? null);
    $sms->user       = $data['user']     ?? 'SYSTEM';
    $sms->batch      = $data['batch']    ?? null;
    $sms->message_id = $data['message_id'] ?? null;
    $sms->direction  = $data['direction']  ?? self::DIRECTION_OUTBOUND;
    $sms->status     = self::STATUS_PENDING;

    // Validate
    $validator = Validator::make([
      'receiver' => $sms->receiver,
      'message'  => $sms->message,
    ], [
      'receiver' => 'required|phone',
      'message'  => 'required|max:250',
    ]);

    if ($validator->fails()) {
      $sms->_mergeErrors($validator, 'validation', 'validation');
      return false;
    }

    // Determine driver
    $driverName = $data['driver'] ?? self::resolveDriverFromConfig($sms->receiver);
    if (!$driverName) {
      $sms->_userError('send', 'No SMS driver configured or resolved.');
      return false;
    }

    try {
      $driver = self::getDriver($driverName);
    } catch (\RuntimeException $e) {
      $sms->_systemError('send', $e->getMessage());
      return false;
    }

    // Calculate message parts
    $sms->_message_pages = self::countMessagePages($sms->message);

    // Save pending record (generates code)
    if (!$sms->save()) {
      return false;
    }

    // Send via driver
    try {
      [$cost, $currency, $reference, $senderUsed] = $driver->send($sms);
    } catch (\Throwable $e) {
      $sms->status = self::STATUS_FAILED;
      $sms->meta   = json_encode(['error' => $e->getMessage()]);
      $sms->save();
      $sms->_userError('send', 'SMS delivery failed: ' . $e->getMessage());
      return false;
    }

    // Success — update record
    $sms->status        = self::STATUS_SENT;
    $sms->fees          = $cost;
    $sms->fees_currency = $currency;
    $sms->reference     = $reference;
    $sms->sender        = $senderUsed;
    $sms->save();

    return $sms;
  }

  /**
   * Alias for send() — provides semantic parity for “send and wait” flows.
   */
  public static function sendAndWait(array $data): static|false
  {
    return self::send($data);
  }

  // ---------------------------------------------------------------------------
  // Delivery reports (webhooks)
  // ---------------------------------------------------------------------------

  /**
   * Process a delivery report webhook from the given driver.
   *
   * @param string $driverName e.g. 'twilio'
   * @param array  $payload    Raw webhook data (e.g. $_POST)
   * @return static|null Updated Sms instance, or null if verification fails / message not found.
   */
  public static function processDeliveryReport(string $driverName, array $payload): ?static
  {
    try {
      $driver = self::getDriver($driverName);
    } catch (\RuntimeException) {
      return null;
    }

    if (!$driver->verifyDeliveryReport($payload)) {
      return null;
    }

    $parsed    = $driver->parseDeliveryReport($payload);
    $reference = $parsed['reference'] ?? null;
    $status    = $parsed['status']    ?? null;
    $meta      = $parsed['meta']      ?? [];

    if (!$reference || !$status) {
      return null;
    }

    $sms = static::findByReference($reference);
    if (!$sms) {
      return null;
    }

    // Map normalised status
    $sms->status = match ($status) {
      'delivered' => self::STATUS_DELIVERED,
      'failed'    => self::STATUS_FAILED,
      default     => $sms->status,
    };

    // Merge metadata
    $existingMeta = $sms->meta ? json_decode($sms->meta, true) : [];
    $sms->meta    = json_encode(array_merge((array)$existingMeta, $meta));
    $sms->save();

    return $sms;
  }

  // ---------------------------------------------------------------------------
  // Lookups
  // ---------------------------------------------------------------------------

  /**
   * Find a message by its 15‑char unique code.
   */
  public static function findByCode(string $code): ?static
  {
    return static::query()->where('code', $code)->first() ?: null;
  }

  /**
   * Find a message by its provider reference (e.g. Twilio SID).
   */
  public static function findByReference(string $reference): ?static
  {
    return static::query()->where('reference', $reference)->first() ?: null;
  }

  // ---------------------------------------------------------------------------
  // Migration helper
  // ---------------------------------------------------------------------------

  /**
   * Populate the `code` column for existing rows.
   * Called once after running `sql/migrate.sql`.
   */
  public static function populateMissingCodes(SQLDatabase $conn): void
  {
    $table = static::$_table_name;
    $rows  = $conn->fetchAll("SELECT `id` FROM `{$table}` WHERE `code` IS NULL");

    foreach ($rows as $row) {
      $code = self::generateUniqueCode($conn);
      $conn->execute("UPDATE `{$table}` SET `code` = ? WHERE `id` = ?", [$code, $row['id']]);
    }
  }

  // ---------------------------------------------------------------------------
  // DatabaseObject hooks
  // ---------------------------------------------------------------------------

  protected function _create(): bool
  {
    if (empty($this->code)) {
      $this->code = self::generateUniqueCode($this->conn());
    }

    return $this->_traitCreate();
  }

  // ---------------------------------------------------------------------------
  // Accessors
  // ---------------------------------------------------------------------------

  public function id(): ?int               { return $this->id; }
  public function code(): ?string          { return $this->code; }
  public function status(): string         { return $this->status; }
  public function messageId(): ?int        { return $this->message_id; }
  public function direction(): string      { return $this->direction; }
  public function user(): string           { return $this->user; }
  public function batch(): ?string         { return $this->batch; }
  public function sender(): string         { return $this->sender; }
  public function receiver(): string       { return $this->receiver; }
  public function message(): string        { return $this->message; }
  public function messagePages(): int      { return $this->_message_pages; }
  public function fees(): float            { return (float)($this->fees ?? 0); }
  public function feesCurrency(): ?string  { return $this->fees_currency; }
  public function reference(): ?string     { return $this->reference; }

  /**
   * Decoded JSON metadata (driver‑specific).
   */
  public function meta(): ?array
  {
    return $this->meta ? json_decode($this->meta, true) : null;
  }

  // ---------------------------------------------------------------------------
  // Internals
  // ---------------------------------------------------------------------------

  /**
   * Resolve which driver to use based on configuration and receiver number.
   */
  private static function resolveDriverFromConfig(string $phoneNumber): ?string
  {
    if (self::$config === null) {
      return null;
    }

    if (self::$config['region_strategy'] === 'auto') {
      try {
        $continent = Phone::continent($phoneNumber);
        $mapping   = self::$config['continent_mapping'] ?? [];
        if ($continent && isset($mapping[$continent])) {
          return $mapping[$continent];
        }
      } catch (\Throwable) {
        // ignore — fall back to default
      }
    }

    return self::$config['default_driver'] ?? null;
  }

  /**
   * Instantiate a driver by name.
   *
   * @throws \RuntimeException if config missing or driver unknown.
   */
  private static function getDriver(string $name): SmsDriverInterface
  {
    if (self::$config === null) {
      throw new \RuntimeException('SMS configuration not set. Call Sms::configure() first.');
    }

    $driverConfig = self::$config['drivers'][$name] ?? null;
    if (!$driverConfig) {
      throw new \RuntimeException("Driver configuration for '{$name}' not found.");
    }

    return match ($name) {
      'twilio'          => new TwilioDriver($driverConfig),
      'africastalking'  => new AfricasTalkingDriver($driverConfig),
      default           => throw new \RuntimeException("Unknown SMS driver: '{$name}'"),
    };
  }

  /**
   * Calculate the number of message parts (pages) according to GSM‑7 vs. Unicode rules.
   */
  private static function countMessagePages(string $message): int
  {
    // Normalise newlines
    $message = str_replace(["\r\n", "\r"], "\n", $message);

    $gsm7Pattern = '/^[\x0A\x0D\x20-\x7E€£¥èéùìòÇØøÅåΔΦΓΛΩΠΨΣΘΞÆæßÉ!"#\$%&\'()*+,\-.\/0-9:;<=>?@A-Z\[\\\]^_`a-z{|}~]*$/u';
    $isGsm7 = preg_match($gsm7Pattern, $message);
    $length = mb_strlen($message, 'UTF-8');

    if ($isGsm7) {
      return $length <= 160 ? 1 : (int)ceil($length / 153);
    }

    return $length <= 70 ? 1 : (int)ceil($length / 67);
  }

  /**
   * Generate a unique 15‑char code: `828` + 12 random digits, checked against the table.
   */
  private static function generateUniqueCode(SQLDatabase $conn): string
  {
    $db    = static::$_db_name;
    $table = static::$_table_name;

    do {
      $code = self::CODE_PREFIX . Random::numeric(12);
    } while ($conn->fetchOne(
      "SELECT 1 FROM `{$db}`.`{$table}` WHERE `code` = ?",
      [$code]
    ));

    return $code;
  }
}