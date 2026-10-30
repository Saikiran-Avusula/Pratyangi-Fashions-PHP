<?php
declare(strict_types=1);

/**
 * A tiny test runner (no framework). Usage in a test script:
 *   require __DIR__ . '/lib/TestCase.php';
 *   $t = new TestCase('name');
 *   $t->test('something', function () use ($t) { $t->same(1, 1); });
 *   exit($t->finish());      // 0 = all passed
 */
final class TestCase
{
    private int $passed = 0;
    /** @var list<string> */
    private array $failures = [];
    private string $current = '';

    public function __construct(private string $suite)
    {
        require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
        // tests see every problem
        ini_set('display_errors', '1');
    }

    public function test(string $name, \Closure $body): void
    {
        $this->current = $name;
        try {
            $body();
            echo "  ok   {$name}\n";
        } catch (\Throwable $e) {
            $this->failures[] = $name . ': ' . get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
            echo "  FAIL {$name}\n       " . get_class($e) . ': ' . $e->getMessage() . "\n";
        }
    }

    public function same(mixed $expected, mixed $actual, string $note = ''): void
    {
        if ($expected !== $actual) {
            throw new \RuntimeException(($note !== '' ? $note . ': ' : '') . 'expected ' . self::show($expected) . ' got ' . self::show($actual));
        }
        $this->passed++;
    }

    public function ok(bool $condition, string $note = ''): void
    {
        if (!$condition) {
            throw new \RuntimeException('expected true' . ($note !== '' ? ': ' . $note : ''));
        }
        $this->passed++;
    }

    public function contains(string $needle, string $haystack, string $note = ''): void
    {
        $haystack = preg_replace("/\.(js|css)\?v=[0-9a-f]{10}/", ".$1", $haystack); // asset URLs carry ?v=<hash> (View::asset); the tests name the files
        if (!str_contains($haystack, $needle)) {
            throw new \RuntimeException('expected text to contain ' . self::show($needle) . ($note !== '' ? ' (' . $note . ')' : ''));
        }
        $this->passed++;
    }

    /** Runs $body and expects it to throw $class; returns the exception. */
    public function throws(string $class, \Closure $body, string $note = ''): \Throwable
    {
        try {
            $body();
        } catch (\Throwable $e) {
            if (!($e instanceof $class)) {
                throw new \RuntimeException('expected ' . $class . ' got ' . get_class($e) . ': ' . $e->getMessage());
            }
            $this->passed++;
            return $e;
        }
        throw new \RuntimeException('expected ' . $class . ' to be thrown' . ($note !== '' ? ': ' . $note : ''));
    }

    public function skip(string $reason): void
    {
        echo "  SKIP {$reason}\n";
    }

    /** Prints the summary and returns the exit code. */
    public function finish(): int
    {
        $failed = count($this->failures);
        echo "\n{$this->suite}: {$this->passed} checks passed, {$failed} test(s) failed\n";
        foreach ($this->failures as $f) {
            echo "  - {$f}\n";
        }
        return $failed === 0 ? 0 : 1;
    }

    private static function show(mixed $v): string
    {
        $text = json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_PARTIAL_OUTPUT_ON_ERROR);
        return $text === false ? get_debug_type($v) : $text;
    }
}
