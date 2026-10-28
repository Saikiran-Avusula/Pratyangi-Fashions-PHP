<?php
declare(strict_types=1);

/**
 * Starts tests/fake_erp/router.php with PHP's built-in server on 127.0.0.1 and stops it again. Local only; no real ERP.
 * Used by erp_client_test.php. The state folder holds the flag files the router reads (see the router's header).
 */
final class FakeErp
{
    /** The catalogue key (api/catalog_*.php) and the order key (api/order_create.php): two keys, each opens only its own endpoints. */
    public const KEY = 'fake-erp-key-0123456789abcdef0123456789';
    public const ORDER_KEY = 'fake-erp-order-key-0123456789abcdef01';

    public string $dir;
    public int $port;
    private mixed $process = null;

    public function start(): void
    {
        $this->dir = Helpers::tempDir('pf_fakeerp_');
        $this->port = self::freePort();
        $router = dirname(__DIR__) . '/fake_erp/router.php';
        $command = [PHP_BINARY, '-S', '127.0.0.1:' . $this->port, $router];
        $env = ['PF_FAKE_ERP_DIR' => $this->dir] + (getenv() ?: []);
        $this->process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $this->dir . '/server.out', 'w'], 2 => ['file', $this->dir . '/server.err', 'w']], $pipes, dirname($router), $env);
        if (!is_resource($this->process)) {
            throw new RuntimeException('could not start the fake ERP');
        }
        for ($i = 0; $i < 100; $i++) {
            $socket = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 0.1);
            if ($socket !== false) {
                fclose($socket);
                return;
            }
            usleep(50000);
        }
        throw new RuntimeException('the fake ERP did not start');
    }

    public function baseUrl(): string
    {
        return 'http://127.0.0.1:' . $this->port . '/';
    }

    public function flag(string $name, bool $on = true): void
    {
        $file = $this->dir . '/' . $name . '.flag';
        if ($on) {
            file_put_contents($file, '1');
        } elseif (is_file($file)) {
            unlink($file);
        }
    }

    /** @return list<array<string,mixed>> */
    public function requests(): array
    {
        $file = $this->dir . '/requests.log';
        $out = [];
        foreach (is_file($file) ? (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [] as $line) {
            $out[] = json_decode($line, true);
        }
        return $out;
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        Helpers::rmTree($this->dir);
    }

    private static function freePort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($server === false) {
            throw new RuntimeException('no free port');
        }
        $name = (string) stream_socket_get_name($server, false);
        fclose($server);
        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }
}
