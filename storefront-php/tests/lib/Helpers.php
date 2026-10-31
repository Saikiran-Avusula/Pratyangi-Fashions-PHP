<?php
declare(strict_types=1);

// Shared helpers for the test scripts: a settable clock, a ready-made Config and App built from fakes.

// The autoloader must exist before FixedClock extends Clock below (TestCase only loads it in its constructor).
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once __DIR__ . '/Tmp.php';

use Pratyangi\App;
use Pratyangi\Config;
use Pratyangi\Erp\ErpPort;
use Pratyangi\Erp\MockErp;
use Pratyangi\Http\MemoryRateLimitStore;
use Pratyangi\Http\RateLimitStore;
use Pratyangi\Http\Request;
use Pratyangi\Mail\Mailer;
use Pratyangi\Mail\NullMailer;
use Pratyangi\Support\Clock;
use Pratyangi\Support\Logger;

/** A clock the test moves by hand. */
final class FixedClock extends Clock
{
    public function __construct(private float $time = 1798000000.0)
    {
    }

    public function now(): float
    {
        return $this->time;
    }

    public function advance(float $seconds): void
    {
        $this->time += $seconds;
    }

    public function set(float $time): void
    {
        $this->time = $time;
    }
}

final class Helpers
{
    public const ORIGIN = 'http://localhost:3000';

    /** A fresh empty folder under the system temp folder (removed with rmTree). */
    public static function tempDir(string $prefix = 'pf_test_'): string
    {
        return Tmp::dir($prefix);
    }

    public static function rmTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            is_dir($path) ? self::rmTree($path) : unlink($path);
        }
        rmdir($dir);
    }

    /** @param array<string,mixed> $override */
    public static function config(array $override = []): Config
    {
        return new Config($override + [
            'APP_ENV' => 'test',
            'DEMO_UI' => getenv('PF_TEST_DEMO_UI') ?: 'false', // the suite can be run with the preview screens on: PF_TEST_DEMO_UI=true
            'DB_NAME' => 'pf_unit_test',
            'DB_USER' => 'unit',
            'DB_PASS' => '',
            'WEB_ORIGIN' => self::ORIGIN . ',https://shop.example.com/',
            'SMS_ENABLED' => 'true', // the tests drive the mobile flows; the SMS_ENABLED=false cases pass their own
            'ERP_MODE' => 'mock',
            'MAIL_MODE' => 'log',
            'MAIL_LOG_PATH' => Tmp::base() . '/pf_unit_mail.log',
            'CATALOG_CACHE_DIR' => self::tempDir('pf_cache_'),
            'LOG_LEVEL' => 'silent',
        ]);
    }

    /**
     * An App with in-memory fakes. $pdoFactory defaults to one that fails, so a test that wrongly reaches the database is loud.
     * @param array<string,int> $limits
     */
    public static function app(?Config $config = null, ?Clock $clock = null, ?RateLimitStore $store = null, ?ErpPort $erp = null, ?Mailer $mailer = null, ?\Closure $pdoFactory = null, array $limits = [], ?\Pratyangi\Sms\SmsGateway $sms = null, ?\Pratyangi\Auth\GoogleVerifier $google = null): App
    {
        $config ??= self::config();
        return new App(
            $config,
            new Logger('silent'),
            $clock ?? new FixedClock(),
            $pdoFactory ?? static function (): \PDO {
                throw new RuntimeException('this test must not touch the database');
            },
            $mailer ?? new NullMailer(),
            $erp ?? new MockErp($config->erpMockFile, $config->erpPriceRule, $config->erpTaxMode),
            $store ?? new MemoryRateLimitStore(),
            $limits,
            null,
            $sms,
            null,
            $google,
        );
    }

    /**
     * @param array<string,string> $headers
     * @param array<mixed>|string|null $body array = sent as JSON
     */
    public static function request(string $method, string $uri, array $headers = [], array|string|null $body = null, string $ip = '203.0.113.9'): Request
    {
        $raw = '';
        if (is_array($body)) {
            $raw = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $headers += ['Content-Type' => 'application/json'];
        } elseif (is_string($body)) {
            $raw = $body;
        }
        return new Request($method, $uri, $headers, $raw, $ip, strlen($raw) > Request::MAX_BODY_BYTES);
    }
}
