<?php
declare(strict_types=1);

use Pratyangi\Db;

/**
 * Safety gate for the scripts that need MySQL. They run only against a throwaway database on this machine:
 *   host 127.0.0.1 or localhost, port NOT 3306, database name ending _test, settings from environment variables ONLY:
 *   PF_TEST_DB_HOST (default 127.0.0.1), PF_TEST_DB_PORT, PF_TEST_DB_NAME, PF_TEST_DB_USER, PF_TEST_DB_PASS.
 * Optional PF_TEST_APPLY_SCHEMA=1 creates the phase 1 tables (CREATE TABLE IF NOT EXISTS) from sql/*.sql.
 * Only host, port and database name are ever printed; never the user, password or connection string.
 */
final class DbGuard
{
    public static function connect(): \PDO
    {
        $host = getenv('PF_TEST_DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('PF_TEST_DB_PORT') ?: 0);
        $name = (string) (getenv('PF_TEST_DB_NAME') ?: '');
        $user = (string) (getenv('PF_TEST_DB_USER') ?: '');
        $pass = (string) (getenv('PF_TEST_DB_PASS') ?: '');
        $refuse = static function (string $why): never {
            fwrite(STDERR, "REFUSED: {$why}\n");
            exit(2);
        };
        if (!in_array($host, ['127.0.0.1', 'localhost'], true)) {
            $refuse('PF_TEST_DB_HOST must be 127.0.0.1 or localhost');
        }
        if ($port <= 0 || $port === 3306) {
            $refuse('PF_TEST_DB_PORT must be set and must not be 3306');
        }
        if (preg_match('/^[A-Za-z0-9_]+_test$/D', $name) !== 1) {
            $refuse('PF_TEST_DB_NAME must end with _test');
        }
        if ($user === '') {
            $refuse('PF_TEST_DB_USER is not set');
        }
        echo "Database: host={$host} port={$port} name={$name}\n";
        $pdo = Db::connectWith($host, $port, $name, $user, $pass);
        if (getenv('PF_TEST_APPLY_SCHEMA') === '1') {
            foreach (['001_phase1_accounts.sql', '002_rate_limits.sql', '004_addresses.sql', '005_orders.sql', '006_google_identities.sql', '007_mail_events.sql', '008_consent_numbers.sql'] as $file) {
                $filePath = dirname(__DIR__, 2) . '/sql/' . $file;
                if (!file_exists($filePath)) {
                    continue;
                }
                $sql = (string) file_get_contents($filePath);
                $sql = (string) preg_replace('/^--.*$/m', '', $sql);
                foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
                    $pdo->exec($statement);
                }
            }
            echo "Schema applied (IF NOT EXISTS).\n";
        }
        if (getenv('PF_TEST_APPLY_OTP') === '1') {
            self::applyOtpMigration($pdo);
        }
        return $pdo;
    }

    /**
     * sql/003_mobile_otp.sql, once: its ALTER cannot be repeated, so it is skipped when shop_customers already has the mobile column.
     * Runs only on the connection DbGuard::connect() has already checked (127.0.0.1, port not 3306, name ending _test).
     */
    private static function applyOtpMigration(\PDO $pdo): void
    {
        $has = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_customers' AND COLUMN_NAME = 'mobile'")->fetchColumn();
        // 003 cannot be repeated; 003b (sql/003b_verification_codes.sql) is safe to run twice
        foreach (['003_mobile_otp.sql' => (int) $has === 0, '003b_verification_codes.sql' => true] as $file => $apply) {
            if (!$apply) {
                echo "{$file} already applied.\n";
                continue;
            }
            $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/sql/' . $file);
            $sql = (string) preg_replace('/^--.*$/m', '', $sql);
            foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
                $pdo->exec($statement);
            }
            echo "{$file} applied.\n";
        }
    }

    /** Empties the phase 1 tables so a run starts clean. */
    public static function clean(\PDO $pdo): void
    {
        $tables = ['shop_mail_events', 'shop_razorpay_orders', 'shop_order_items', 'shop_orders', 'shop_customer_addresses', 'shop_sessions', 'shop_password_resets', 'shop_email_verifications', 'shop_customers', 'shop_rate_limits'];
        foreach (['shop_otp_codes', 'shop_verification_codes'] as $optional) {
            $exists = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
            $exists->execute([$optional]);
            if ((int) $exists->fetchColumn() > 0) {
                $tables[] = $optional;
            }
        }
        foreach ($tables as $table) {
            $exists = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
            $exists->execute([$table]);
            if ((int) $exists->fetchColumn() > 0) {
                $pdo->prepare('DELETE FROM ' . $table)->execute(); // table names are the fixed list above, no input
            }
        }
    }
}
