<?php
declare(strict_types=1);

// Renders every email with sample data to .claude/snapshots/e2/ (name.html, name.txt) and, when Google Chrome is found, a screenshot at 600 and 390 px wide
// (name-600.png, name-390.png). Sends nothing and touches no database. Run:  php tools/preview_emails.php
require dirname(__DIR__) . '/tests/lib/Helpers.php';

use Pratyangi\Mail\EventTemplates;
use Pratyangi\Mail\MailTemplates;
use Pratyangi\Views\ShopInfo;

ShopInfo::init(Helpers::config([
    'WEB_ORIGIN' => 'https://staging.example.test', 'SHOP_LEGAL_NAME' => 'Vistaraverse E-Cart Private Limited', 'SHOP_CIN' => 'U46901AP2026PTC126193',
    'SHOP_SUPPORT_EMAIL' => 'info@pratyangi.com', 'SHOP_SOCIAL_INSTAGRAM' => 'https://instagram.example.test/shop', 'SHOP_SOCIAL_WHATSAPP' => 'https://wa.me/919989233328',
]));
$out = dirname(__DIR__) . '/.claude/snapshots/e2';
if (!is_dir($out) && !mkdir($out, 0775, true)) {
    exit(1);
}
$order = [
    'orderNo' => 'PF-10021', 'name' => "Asha O'Brien & Sons",
    'items' => [["Kanchipuram Silk Saree #5 - Maroon & Gold Border", 1, 1299900], ['Cotton Blouse Piece', 2, 49950]],
    'itemsTotalPaise' => 1399800, 'taxPaise' => 66657, 'totalPaise' => 1399800,
    'address' => "Asha O'Brien, #5, Sahiti's Lane & Co, Madhapur, Hyderabad, Telangana - 500081",
    'amountPaise' => 1399800, 'method' => 'UPI', 'invoiceCode' => 'k3JdQ9xT2mWp7Z',
    'courier' => 'Delhivery', 'awb' => '1234567890123', 'trackingUrl' => 'https://track.example.test/1234567890123',
    'refundPaise' => 1399800, 'note' => "Your return pickup is booked for Friday. The parcel's weight is 1 kg & it will be checked on arrival.",
];
$mails = [
    'welcome' => EventTemplates::welcome("Asha O'Brien"),
    'password_changed' => EventTemplates::passwordChanged("Asha O'Brien", '29 Oct 2026, 2:05 PM IST'),
    'email_changed_old' => EventTemplates::emailChanged("Asha O'Brien", 'old', 'asha.old@example.com', 'asha.new@example.com'),
    'email_changed_new' => EventTemplates::emailChanged("Asha O'Brien", 'new', 'asha.old@example.com', 'asha.new@example.com'),
    'order_confirmed' => EventTemplates::orderConfirmed($order),
    'payment_receipt' => EventTemplates::paymentReceipt($order),
    'payment_failed' => EventTemplates::paymentFailed($order),
    'shipped' => EventTemplates::shipped($order),
    'out_for_delivery' => EventTemplates::outForDelivery($order),
    'delivered' => EventTemplates::delivered($order),
    'cancelled' => EventTemplates::cancelled($order),
    'refund_processed' => EventTemplates::refundProcessed($order),
    'return_update' => EventTemplates::returnUpdate($order),
    'code_email_verify' => MailTemplates::code('email_verify', '482913', 10, "Asha O'Brien"),
];
$chrome = null;
foreach (['C:\Program Files\Google\Chrome\Application\chrome.exe', 'C:\Program Files (x86)\Google\Chrome\Application\chrome.exe', '/usr/bin/google-chrome', '/usr/bin/chromium'] as $c) {
    if (is_file($c)) {
        $chrome = $c;
        break;
    }
}
$profile = Tmp::profile();
$publicUrl = 'file:///' . str_replace('\\', '/', dirname(__DIR__)) . '/public';
foreach ($mails as $name => $m) {
    // the pictures come from public/ on this machine (the sample address above does not exist)
    file_put_contents("{$out}/{$name}.html", str_replace('https://staging.example.test', $publicUrl, $m['html']));
    file_put_contents("{$out}/{$name}.txt", "Subject: {$m['subject']}\n\n{$m['text']}\n");
    echo str_pad($name, 20) . $m['subject'] . "\n";
    if ($chrome === null) {
        continue;
    }
    foreach ([600, 390] as $width) {
        // Chrome will not make a window narrower than about 500 px, so the mail is shown in a frame of the exact width
        $frame = "{$out}/{$name}-{$width}-frame.html";
        file_put_contents($frame, '<!doctype html><body style="margin:0;background:#888"><iframe src="' . $name . '.html" width="' . $width . '" height="1400" style="border:0;display:block"></iframe>');
        $cmd = [$chrome, '--headless=new', '--disable-gpu', '--hide-scrollbars', '--allow-file-access-from-files', '--user-data-dir=' . $profile, '--window-size=700,1500', '--screenshot=' . "{$out}/{$name}-{$width}.png", 'file:///' . str_replace('\\', '/', $frame)];
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (is_resource($p)) {
            stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            Tmp::kill($p);
        }
        unlink($frame);
    }
}
echo $chrome === null ? "Chrome not found: HTML and text only.\n" : "Screenshots written (600 and 390 px).\n";
