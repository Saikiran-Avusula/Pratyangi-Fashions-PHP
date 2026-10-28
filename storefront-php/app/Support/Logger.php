<?php
declare(strict_types=1);

namespace Pratyangi\Support;

/**
 * JSON lines. A line holds a time, level, message, request id and the safe fields the caller passes.
 * Never pass cookies, bodies, tokens, codes, keys or passwords. Exceptions are reduced to class and place
 * (and the message, except for database errors whose text can hold customer data).
 */
final class Logger
{
    public const CHANNELS = ['app', 'error', 'auth', 'erp', 'orders', 'payment', 'mail', 'sms', 'cron'];
    public const LEVELS = ['debug' => 10, 'info' => 20, 'warn' => 30, 'error' => 40, 'silent' => 100];
    public const MAX_BYTES = 5 * 1024 * 1024; // 5 MB

    private string $level;
    private ?string $logDir;
    /** @var \Closure(string):void|null */
    private ?\Closure $sink;
    private string $channel;
    private string $requestId = '-';
    private string $appEnv;

    /**
     * @param string $level debug|info|warn|error|silent
     * @param string|\Closure|null $logDirOrSink path to log directory or custom sink closure
     * @param (\Closure(string):void)|null $sink custom sink closure when logDir is a string
     */
    public function __construct(
        string $level = 'info',
        string|\Closure|null $logDirOrSink = null,
        ?\Closure $sink = null,
        string $channel = 'app',
        string $appEnv = 'production',
        private ?Clock $clock = null,
    ) {
        $this->level = $level;
        if ($logDirOrSink instanceof \Closure) {
            $this->logDir = null;
            $this->sink = $logDirOrSink;
        } else {
            $this->logDir = $logDirOrSink;
            $this->sink = $sink;
        }
        $this->channel = in_array($channel, self::CHANNELS, true) ? $channel : 'app';
        $this->appEnv = $appEnv;
    }

    /** Formats a unix timestamp into Indian time with date: "Y-m-d H:i:s IST" using Asia/Kolkata timezone. */
    public static function formatTime(?float $unix = null): string
    {
        $timestamp = $unix ?? microtime(true);
        $dt = (new \DateTimeImmutable('@' . (int) floor($timestamp)))->setTimezone(new \DateTimeZone('Asia/Kolkata'));
        return $dt->format('Y-m-d H:i:s') . ' IST';
    }

    public function setRequestId(string $requestId): void
    {
        $this->requestId = $requestId !== '' ? $requestId : '-';
    }

    public function requestId(): string
    {
        return $this->requestId;
    }

    public function channel(string $channel): self
    {
        $clone = clone $this;
        $clone->channel = in_array($channel, self::CHANNELS, true) ? $channel : 'app';
        return $clone;
    }

    /**
     * @param array<string,mixed> $context
     */
    public function log(string $level, string $message, array $context = [], ?string $channel = null): void
    {
        $level = strtolower($level);
        if ((self::LEVELS[$level] ?? 20) < (self::LEVELS[$this->level] ?? 20)) {
            return;
        }
        $ch = $channel !== null && in_array($channel, self::CHANNELS, true) ? $channel : $this->channel;
        $cleanMessage = $this->sanitizeMessage($message, $ch);
        $cleanContext = $this->sanitizeContext($context, $ch);
        $now = $this->clock !== null ? $this->clock->now() : microtime(true);
        $timeStr = self::formatTime($now);
        $json = $cleanContext === []
            ? '{}'
            : (string) json_encode($cleanContext, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($json === '' || $json === 'false') {
            $json = '{}';
        }
        $line = $timeStr . ' | ' . $level . ' | ' . $ch . ' | ' . $this->requestId . ' | ' . $cleanMessage . ' | ' . $json;
        $this->write($ch, $line);
    }

    /** @param array<string,mixed> $context */
    public function info(string $message, array $context = [], ?string $channel = null): void
    {
        $this->log('info', $message, $context, $channel);
    }

    /** @param array<string,mixed> $context */
    public function warn(string $message, array $context = [], ?string $channel = null): void
    {
        $this->log('warn', $message, $context, $channel);
    }

    /** @param array<string,mixed> $context */
    public function error(string $message, array $context = [], ?string $channel = null): void
    {
        $this->log('error', $message, $context, $channel);
    }

    /** @param array<string,mixed> $context */
    public function debug(string $message, array $context = [], ?string $channel = null): void
    {
        $this->log('debug', $message, $context, $channel);
    }

    public function exception(string $message, \Throwable $e, string $level = 'error', ?string $channel = null): void
    {
        $context = [
            'exception' => get_class($e),
            'where' => basename($e->getFile()) . ':' . $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ];
        if ($e instanceof \PDOException) {
            $context['sqlstate'] = (string) $e->getCode();
        } else {
            $context['error'] = $e->getMessage();
        }
        $ch = $channel ?? ($level === 'error' ? 'error' : $this->channel);
        $this->log($level, $message, $context, $ch);
    }

    private function write(string $channel, string $line): void
    {
        if ($this->sink !== null) {
            ($this->sink)($line);
        }
        if ($this->logDir === null || $this->logDir === '') {
            if ($this->sink === null) {
                error_log($line, 4);
            }
            return;
        }
        if (!is_dir($this->logDir)) {
            @mkdir($this->logDir, 0775, true);
        }
        $file = $this->logDir . '/' . $channel . '.log';
        if (file_exists($file)) {
            clearstatcache(true, $file);
            $size = @filesize($file);
            if ($size !== false && $size >= self::MAX_BYTES) {
                $rotated = $file . '.1';
                if (file_exists($rotated)) {
                    @unlink($rotated);
                }
                @rename($file, $rotated);
            }
        }
        @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
    }

    private function sanitizeMessage(string $message, string $channel): string
    {
        // Sanitise newlines in messages
        $message = preg_replace('/\s+/', ' ', trim($message)) ?? '';

        // Full mobile numbers masked to +91******XXXX
        $message = (string) preg_replace_callback(
            '/(?:\+?91[\s-]?)?([6-9]\d{9})\b/',
            static fn (array $m): string => '+91******' . substr($m[1], -4),
            $message
        );

        // Passwords, tokens, api keys, cookies in message text
        $message = (string) preg_replace('/(password|passwd|pwd)[\s:=]+[^\s,;]+/i', '$1=[REDACTED]', $message);
        $message = (string) preg_replace('/(Bearer\s+)[^\s,;]+/i', '$1[REDACTED]', $message);
        $message = (string) preg_replace('/(api[_-]?key|secret)[\s:=]+[^\s,;]+/i', '$1=[REDACTED]', $message);
        $message = (string) preg_replace('/((?:pf_session|cookie)=)[^;\s]+/i', '$1[REDACTED]', $message);

        // OTPs and email codes only in mail.log and sms.log, and only in development or test
        $allowCode = ($channel === 'mail' || $channel === 'sms') && ($this->appEnv === 'development' || $this->appEnv === 'test');
        if (!$allowCode) {
            $message = (string) preg_replace('/(\b(?:otp|code)\b[\s:=]+)[a-zA-Z0-9]{4,8}\b/i', '$1[REDACTED]', $message);
        }

        return $message;
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function sanitizeContext(array $context, string $channel): array
    {
        $allowCode = ($channel === 'mail' || $channel === 'sms') && ($this->appEnv === 'development' || $this->appEnv === 'test');
        $out = [];
        foreach ($context as $key => $value) {
            $k = (string) $key;
            $lower = strtolower($k);

            // Secret keys
            if (preg_match('/(password|passwd|pwd|token|api_?key|secret|cookie|auth_header)/i', $lower) === 1) {
                $out[$k] = '[REDACTED]';
                continue;
            }

            // OTPs and email codes
            if (preg_match('/^(otp|code|email_code|sms_code|reset_code|signup_code|verification_code)$/i', $lower) === 1) {
                if (!$allowCode) {
                    $out[$k] = '[REDACTED]';
                    continue;
                }
            }

            if (is_array($value)) {
                $out[$k] = $this->sanitizeContext($value, $channel);
            } elseif (is_string($value)) {
                // Mask full mobile numbers
                $val = (string) preg_replace_callback(
                    '/(?:\+?91[\s-]?)?([6-9]\d{9})\b/',
                    static fn (array $m): string => '+91******' . substr($m[1], -4),
                    $value
                );
                // Scrub bearer tokens, cookies, passwords, keys
                $val = (string) preg_replace('/(Bearer\s+)[^\s,;]+/i', '$1[REDACTED]', $val);
                $val = (string) preg_replace('/((?:pf_session|cookie)=)[^;\s]+/i', '$1[REDACTED]', $val);
                $val = (string) preg_replace('/(password|passwd|pwd)[\s:=]+[^\s,;]+/i', '$1=[REDACTED]', $val);
                if (!$allowCode) {
                    $val = (string) preg_replace('/(\b(?:otp|code)\b[\s:=]+)[a-zA-Z0-9]{4,8}\b/i', '$1[REDACTED]', $val);
                }
                $out[$k] = $val;
            } else {
                $out[$k] = $value;
            }
        }
        return $out;
    }
}
