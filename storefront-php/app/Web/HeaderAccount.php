<?php
declare(strict_types=1);

namespace Pratyangi\Web;

use Pratyangi\Views\View;

/**
 * What the header shows for a signed-in person. Same rules as the web's accountLabel():
 * an email-like name shows only the part before the @, the first word is the greeting name,
 * the avatar is its first letter in capitals; a blank name (an account made with a mobile code has none) shows just "Hello" with a person icon.
 */
final class HeaderAccount
{
    /**
     * @param string $first greeting name ("" when the name is blank)
     * @param string $initial one visible character for the avatar ("" when the name is blank)
     * @param string $full the name without any email domain
     */
    public function __construct(
        public readonly string $first,
        public readonly string $initial,
        public readonly string $full,
        public readonly string $email,
        public readonly ?string $picture = null,
    ) {
    }

    /** The Google picture inside the initial's circle (the initial stays underneath; site.js removes a picture that fails to load). */
    public function avatarImg(): string
    {
        return $this->picture === null ? '' : '<img data-pf="avatar-img" src="' . View::e($this->picture) . '" alt="' . View::e($this->first) . '" referrerpolicy="no-referrer" width="34" height="34"/>';
    }

    /** @param array<string,mixed> $row a customer row (name, email) */
    public static function fromRow(array $row): self
    {
        $raw = trim((string) ($row['name'] ?? ''));
        $source = str_contains($raw, '@') ? trim(explode('@', $raw, 2)[0]) : $raw;
        $parts = preg_split('/\s+/u', $source, 2);
        $first = $parts !== false ? ($parts[0] ?? '') : '';
        if (class_exists(\Normalizer::class)) {
            $first = \Normalizer::normalize($first, \Normalizer::FORM_C) ?: $first;
        }
        $initial = '';
        if ($first !== '' && preg_match('/^./us', $first, $m) === 1) {
            $initial = function_exists('mb_strtoupper') ? mb_strtoupper($m[0], 'UTF-8') : strtoupper($m[0]);
        }
        return new self($first, $initial, $source, (string) ($row['email'] ?? ''), \Pratyangi\Auth\PublicCustomer::safePicture($row['picture_url'] ?? null));
    }
}
