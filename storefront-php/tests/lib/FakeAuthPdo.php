<?php
declare(strict_types=1);

// A PDO stand-in for the account tests (no database). It answers the customer e-mail lookup the way utf8mb4_unicode_ci
// would (case AND accent insensitive) and records every other statement, so tests can see what AuthService asked for.

final class FakeAuthStmt extends PDOStatement
{
    /** @var list<array<string,mixed>> */
    private array $result = [];

    public function __construct(private FakeAuthPdo $db, private string $sql)
    {
    }

    public function execute(?array $params = null): bool
    {
        $this->db->log[] = ['sql' => $this->sql, 'params' => $params ?? []];
        $this->result = [];
        if (str_starts_with($this->sql, 'SELECT * FROM shop_customers WHERE email = ?')) {
            $wanted = FakeAuthPdo::fold((string) ($params[0] ?? ''));
            foreach ($this->db->customers as $row) {
                if (FakeAuthPdo::fold((string) $row['email']) === $wanted) {
                    $this->result[] = $row;
                }
            }
        }
        return true;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return $this->result;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return $this->result[0] ?? false;
    }
}

final class FakeAuthPdo extends PDO
{
    /** @var list<array<string,mixed>> */
    public array $customers = [];
    /** @var list<array{sql:string,params:array<mixed>}> */
    public array $log = [];
    private bool $inTx = false;

    public function __construct()
    {
        // no parent constructor: there is no connection
    }

    /** What the column collation compares: lower case, accents removed. */
    public static function fold(string $s): string
    {
        return strtr(mb_strtolower($s, 'UTF-8'), ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'á' => 'a', 'à' => 'a', 'ï' => 'i', 'í' => 'i', 'ó' => 'o', 'ö' => 'o', 'ü' => 'u', 'ú' => 'u', 'ñ' => 'n']);
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new FakeAuthStmt($this, $query);
    }

    public function beginTransaction(): bool
    {
        $this->inTx = true;
        return true;
    }

    public function commit(): bool
    {
        $this->inTx = false;
        return true;
    }

    public function rollBack(): bool
    {
        $this->inTx = false;
        return true;
    }

    public function inTransaction(): bool
    {
        return $this->inTx;
    }

    /** Statements whose text starts with $prefix. @return list<array{sql:string,params:array<mixed>}> */
    public function statements(string $prefix): array
    {
        return array_values(array_filter($this->log, static fn (array $l): bool => str_starts_with($l['sql'], $prefix)));
    }
}
