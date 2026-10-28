<?php
declare(strict_types=1);

namespace Pratyangi\Security;

/** Password hashing. Argon2id when PHP has it, otherwise bcrypt. Plain passwords are never stored or logged. */
final class Passwords
{
    /**
     * Precomputed hashes of a random throwaway string (not a secret), made with the same algorithm and cost as hash().
     * If the cost in hash() changes, regenerate these (passwords_test checks that they still match).
     */
    public const DECOY_ARGON2ID = '$argon2id$v=19$m=19456,t=2,p=1$bE9aZ1N6S1djc005dk9iSg$EEZmmypNVUUkAzY3+/pO/HbPA0Nj8j64P3/e2A7Lc2U';
    public const DECOY_BCRYPT = '$2y$12$YWbb1h0y9DpdrUX6qHn0zO2OyTpuweiQfePtUU2NGLjXWnIV9Xrde';

    public static function hash(string $password): string
    {
        if (defined('PASSWORD_ARGON2ID')) {
            // memory-hard, salted per password (OWASP minimum settings: 19 MiB, 2 passes, 1 lane)
            return password_hash($password, PASSWORD_ARGON2ID, ['memory_cost' => 19456, 'time_cost' => 2, 'threads' => 1]);
        }
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    public static function verify(string $hash, string $password): bool
    {
        return $hash !== '' && password_verify($password, $hash);
    }

    /**
     * When an email is unknown we still verify against a real hash, so "no such account" and "wrong password"
     * take about the same time and the response time does not reveal which emails exist.
     */
    public static function verifyAgainstDecoy(string $password): void
    {
        password_verify($password, self::decoy());
    }

    /** The constant decoy hash for the algorithm hash() uses. No hashing is computed here. */
    public static function decoy(): string
    {
        return defined('PASSWORD_ARGON2ID') ? self::DECOY_ARGON2ID : self::DECOY_BCRYPT;
    }
}
