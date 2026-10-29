<?php
declare(strict_types=1);

namespace Pratyangi\Mail;

use Pratyangi\Support\Clock;
use Pratyangi\Support\Logger;

/**
 * Sends the account and order emails, each at most once per event. An event has a key ("welcome:12", "confirmed:PF-10021"); the key is claimed
 * in shop_mail_events before sending, so a repeat of the same event (a retry, a double click, a second worker) finds it taken and sends nothing.
 * A failed send gives the claim back so a retry can send it. Order emails are not sent while both CHECKOUT_ENABLED and PAYMENTS_ENABLED are off.
 * Nothing here ever throws into the request and the log holds the event name and key only: no address, no name.
 */
final class EventMail
{
    /** @param \Closure(string):bool $claim true when this call got the key @param \Closure(string):void $release */
    public function __construct(
        private Mailer $mailer,
        private \Closure $claim,
        private \Closure $release,
        private bool $ordersOn,
        private Clock $clock,
        private ?Logger $logger = null,
    ) {
    }

    public static function claimInDb(\PDO $pdo): \Closure
    {
        return static function (string $key) use ($pdo): bool {
            $stmt = $pdo->prepare('INSERT IGNORE INTO shop_mail_events (event_key) VALUES (?)');
            $stmt->execute([$key]);
            return $stmt->rowCount() === 1;
        };
    }

    public static function releaseInDb(\PDO $pdo): \Closure
    {
        return static function (string $key) use ($pdo): void {
            $pdo->prepare('DELETE FROM shop_mail_events WHERE event_key = ?')->execute([$key]);
        };
    }

    /** Once per account, whether it was created by email or by Google. */
    public function welcome(int $customerId, string $email, string $name): void
    {
        $this->once('welcome:' . $customerId, 'welcome', $email, fn (): array => EventTemplates::welcome($name));
    }

    /** One per change (the key carries the second it happened). Date and time are shown in India time. */
    public function passwordChanged(int $customerId, string $email, string $name): void
    {
        $now = (int) $this->clock->now();
        $when = (new \DateTimeImmutable('@' . $now))->setTimezone(new \DateTimeZone('Asia/Kolkata'))->format('j M Y, g:i A') . ' IST';
        $this->once('password_changed:' . $customerId . ':' . $now, 'password_changed', $email, fn (): array => EventTemplates::passwordChanged($name, $when));
    }

    /** To the old address and to the new one. $changeId tells one change from the next (for example the time it was made). */
    public function emailChanged(int $customerId, string $changeId, string $oldEmail, string $newEmail, string $name): void
    {
        foreach (['old' => $oldEmail, 'new' => $newEmail] as $role => $to) {
            $this->once("email_changed_{$role}:{$customerId}:{$changeId}", 'email_changed_' . $role, $to, fn (): array => EventTemplates::emailChanged($name, $role, $oldEmail, $newEmail));
        }
    }

    /**
     * One order email. $event is a method name of EventTemplates (orderConfirmed, paymentReceipt, paymentFailed, shipped, outForDelivery, delivered,
     * cancelled, refundProcessed, returnUpdate); $suffix tells repeated events of one order apart (for example a second return update).
     * @param array<string,mixed> $order see EventTemplates
     */
    public function order(string $event, string $to, array $order, string $suffix = ''): void
    {
        if (!$this->ordersOn || !in_array($event, ['orderConfirmed', 'paymentReceipt', 'paymentFailed', 'shipped', 'outForDelivery', 'delivered', 'cancelled', 'refundProcessed', 'returnUpdate'], true)) {
            return;
        }
        $this->once($event . ':' . $order['orderNo'] . ($suffix === '' ? '' : ':' . $suffix), $event, $to, fn (): array => EventTemplates::$event($order));
    }

    /** @param \Closure():array{subject:string,text:string,html:string} $build */
    private function once(string $key, string $event, string $to, \Closure $build): void
    {
        if ($to === '' || !$this->mailer->isAvailable()) {
            return;
        }
        try {
            if (!($this->claim)($key)) {
                return;
            }
        } catch (\Throwable) {
            $this->logger?->error('mail.claim_failed', ['event' => $event]);
            return;
        }
        try {
            $m = $build();
            $this->mailer->send($to, str_replace(["\r", "\n"], ' ', $m['subject']), $m['text'], $m['html']);
            $this->logger?->info('mail.sent', ['event' => $event, 'key' => $key]);
        } catch (\Throwable) {
            try {
                ($this->release)($key);
            } catch (\Throwable) {
            }
            $this->logger?->warn('mail.send_failed', ['event' => $event, 'key' => $key]);
        }
    }
}
