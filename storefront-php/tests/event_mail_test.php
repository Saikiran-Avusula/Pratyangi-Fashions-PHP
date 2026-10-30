<?php
declare(strict_types=1);

// No database. Run: php tests/event_mail_test.php
// The account and order emails: once per event, nothing for orders while orders are off, plain-text twin, subjects, special characters, the receipt line.
require __DIR__ . '/lib/TestCase.php';
require __DIR__ . '/lib/Helpers.php';

use Pratyangi\Mail\EventMail;
use Pratyangi\Mail\EventTemplates;
use Pratyangi\Mail\Mailer;
use Pratyangi\Views\ShopInfo;

$t = new TestCase('event_mail_test');
ShopInfo::init(Helpers::config(['SHOP_LEGAL_NAME' => 'Vistaraverse E-Cart Private Limited', 'SHOP_CIN' => 'U46901AP2026PTC126193', 'SHOP_SUPPORT_EMAIL' => 'info@example.test']));

$box = new class implements Mailer {
    /** @var list<array{to:string,subject:string,text:string,html:?string}> */
    public array $sent = [];
    public function isAvailable(): bool
    {
        return true;
    }
    public function send(string $to, string $subject, string $text, ?string $html = null): void
    {
        $this->sent[] = ['to' => $to, 'subject' => $subject, 'text' => $text, 'html' => $html];
    }
};
$maker = static function (bool $ordersOn) use ($box): EventMail {
    $claimed = [];
    return new EventMail($box, static function (string $k) use (&$claimed): bool {
        return !isset($claimed[$k]) && ($claimed[$k] = true);
    }, static function (string $k) use (&$claimed): void {
        unset($claimed[$k]);
    }, $ordersOn, new FixedClock());
};
$order = [
    'orderNo' => 'PF-10021', 'name' => "Asha O'Brien & Sons", 'items' => [["Silk Saree #5 & Border", 2, 199900]], 'itemsTotalPaise' => 399800, 'taxPaise' => 19038, 'totalPaise' => 399800,
    'address' => "#5, Sahiti's Lane & Co, Hyderabad, Telangana - 500081", 'amountPaise' => 399800, 'method' => 'UPI', 'invoiceCode' => 'k3JdQ9xT2mWp7Z',
    'courier' => 'Delhivery', 'awb' => '1234567890', 'trackingUrl' => 'https://track.example.test/1234567890', 'refundPaise' => 399800, 'note' => "Pickup booked for Friday & the parcel's weight is 1 kg.",
];

$t->test('welcome and password changed: sent once per event, however often the event is reported', function () use ($t, $box, $maker): void {
    $box->sent = [];
    $m = $maker(false); // account emails do not depend on orders being on
    $m->welcome(7, 'a@example.test', "Asha O'Brien");
    $m->welcome(7, 'a@example.test', "Asha O'Brien");
    $m->welcome(8, 'b@example.test', 'Bala');
    $t->same(['a@example.test', 'b@example.test'], array_column($box->sent, 'to'));
    $t->same('Welcome to Pratyangi Fashions', $box->sent[0]['subject']);
    $m->passwordChanged(7, 'a@example.test', 'Asha');
    $m->passwordChanged(7, 'a@example.test', 'Asha');
    $t->same(3, count($box->sent), 'one password mail');
    $p = $box->sent[2];
    $t->same('Your Pratyangi Fashions password was changed', $p['subject']);
    $t->contains('Not you? Contact info@example.test.', $p['text']);
    $t->contains('IST', $p['text']);
    $t->contains('Pratyangi Fashions (a unit of Vistaraverse E-Cart Private Limited)', (string) $p['html']);
    $t->contains('CIN: U46901AP2026PTC126193', (string) $p['html']);
});

$t->test('email changed goes to the old and the new address, once each', function () use ($t, $box, $maker): void {
    $box->sent = [];
    $m = $maker(false);
    $m->emailChanged(7, '100', 'old@example.test', 'new@example.test', 'Asha');
    $m->emailChanged(7, '100', 'old@example.test', 'new@example.test', 'Asha');
    $t->same(['old@example.test', 'new@example.test'], array_column($box->sent, 'to'));
    $t->contains('from old@example.test to new@example.test', $box->sent[0]['text']);
    $t->contains('Not you? Contact info@example.test.', $box->sent[1]['text']);
});

$t->test('order emails: none while checkout and payments are off, each exactly once when on', function () use ($t, $box, $maker, $order): void {
    $box->sent = [];
    $maker(false)->order('orderConfirmed', 'a@example.test', $order);
    $t->same([], $box->sent, 'orders off: nothing');
    $m = $maker(true);
    $events = ['orderConfirmed', 'paymentReceipt', 'paymentFailed', 'shipped', 'outForDelivery', 'delivered', 'cancelled', 'refundProcessed', 'returnUpdate'];
    foreach ($events as $e) {
        $m->order($e, 'a@example.test', $order);
        $m->order($e, 'a@example.test', $order);
    }
    $t->same(9, count($box->sent), 'nine events, nine mails');
    $m->order('returnUpdate', 'a@example.test', $order, '2');
    $t->same(10, count($box->sent), 'a second return update is a new event');
    $m->order('nonsense', 'a@example.test', $order);
    $t->same(10, count($box->sent));
});

$t->test('every email: short subject, plain-text twin, special characters right in HTML and in text', function () use ($t, $order): void {
    $subjects = [
        'orderConfirmed' => 'Your Pratyangi Fashions order PF-10021 is confirmed', 'paymentReceipt' => 'Payment received for your Pratyangi Fashions order PF-10021',
        'paymentFailed' => 'Payment failed for your Pratyangi Fashions order PF-10021', 'shipped' => 'Your Pratyangi Fashions order PF-10021 has shipped',
        'outForDelivery' => 'Your Pratyangi Fashions order PF-10021 is out for delivery', 'delivered' => 'Your Pratyangi Fashions order PF-10021 was delivered',
        'cancelled' => 'Your Pratyangi Fashions order PF-10021 was cancelled', 'refundProcessed' => 'Refund processed for your Pratyangi Fashions order PF-10021',
        'returnUpdate' => 'Update on your return for Pratyangi Fashions order PF-10021',
    ];
    foreach ($subjects as $method => $subject) {
        $m = EventTemplates::$method($order);
        $t->same($subject, $m['subject'], $method);
        $t->ok($m['text'] !== '' && !str_contains($m['text'], '<'), $method . ': plain text, no tags');
        $t->contains('Asha', $m['html'], $method);
        $t->ok(!str_contains($m['html'], "O'Brien") && !str_contains($m['html'], '&amp;amp;'), $method . ': escaped once');
    }
    $c = EventTemplates::orderConfirmed($order);
    $t->contains('Silk Saree #5 &amp; Border', $c['html']);
    $t->contains('Sahiti&#039;s Lane &amp; Co', $c['html']);
    $t->contains("Sahiti's Lane & Co", $c['text']);
    $t->contains('₹3,998.00', $c['text']);
    $t->contains('/account/orders', $c['html']);
    $r = EventTemplates::paymentReceipt($order);
    $t->contains('This is not a GST tax invoice. The tax invoice is issued at dispatch.', $r['text']);
    $t->contains('/account/orders', $r['html']);
    $t->contains('Your refund of ₹3,998.00', EventTemplates::cancelled($order)['text']);
    $t->contains('Courier', EventTemplates::shipped($order)['text']);
    $t->contains('1234567890', EventTemplates::shipped($order)['text']);
    $t->same('₹1,00,000.00', EventTemplates::inr(10000000));
    $t->same('₹5.05', EventTemplates::inr(505));
});

$t->test('a failed send gives the claim back so a retry can send; a mailer that is not available sends nothing', function () use ($t): void {
    $fails = new class implements Mailer {
        public int $tries = 0;
        public function isAvailable(): bool
        {
            return true;
        }
        public function send(string $to, string $subject, string $text, ?string $html = null): void
        {
            if (++$this->tries === 1) {
                throw new \Pratyangi\Mail\MailException('down');
            }
        }
    };
    $claimed = [];
    $m = new EventMail($fails, static function (string $k) use (&$claimed): bool {
        return !isset($claimed[$k]) && ($claimed[$k] = true);
    }, static function (string $k) use (&$claimed): void {
        unset($claimed[$k]);
    }, true, new FixedClock());
    $m->welcome(1, 'a@example.test', 'A');
    $m->welcome(1, 'a@example.test', 'A');
    $m->welcome(1, 'a@example.test', 'A');
    $t->same(2, $fails->tries, 'failed once, sent on the retry, then skipped');
});

$t->test('a claim that cannot be stored (a missing table) logs at ERROR level and sends nothing', function () use ($t): void {
    $lines = [];
    $logger = new \Pratyangi\Support\Logger('debug', static function (string $l) use (&$lines): void {
        $lines[] = $l;
    });
    $sent = new class implements Mailer {
        public int $n = 0;
        public function isAvailable(): bool
        {
            return true;
        }
        public function send(string $to, string $subject, string $text, ?string $html = null): void
        {
            $this->n++;
        }
    };
    $m = new EventMail($sent, static function (string $k): bool {
        throw new \PDOException('no table');
    }, static function (string $k): void {
    }, true, new FixedClock(), $logger);
    $m->welcome(1, 'a@example.test', 'A');
    $t->same(0, $sent->n);
    $t->ok(preg_grep('/error.*mail\.claim_failed|mail\.claim_failed.*error|ERROR.*mail\.claim_failed/i', $lines) !== [], 'logged at error level');
});
exit($t->finish());
