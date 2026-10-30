<?php
declare(strict_types=1);

namespace Pratyangi\Web;

use Pratyangi\Demo\DemoData;
use Pratyangi\Http\Request;
use Pratyangi\Http\Response;
use Pratyangi\Support\Logger;
use Pratyangi\Views\View;

/**
 * The preview screens (Figma 6-13), only built when DEMO_UI=true (App creates this class only then; otherwise these paths are ordinary 404s).
 * Everything printed comes from app/Demo/DemoData.php; nothing is saved, sent or charged. Every page needs a sign-in, like the real checkout and account.
 */
final class DemoPages
{
    private const COOKIE = 'pf_session';
    private const STYLES = ['1_e1s2q5g68a2.css', '3wh3hbs4o_ca8.css', 'demo.css'];
    private const ROUTES = ['/checkout/address', '/checkout/payment', '/checkout/review', '/checkout/demo-confirmation', '/account/payments', '/account/membership', '/account/reviews', '/account/reviews/edit'];

    /** @param \Closure(string):(array<string,mixed>|null) $sessionLookup */
    public function __construct(private \Closure $sessionLookup, private ?Logger $logger = null, private bool $smsEnabled = false)
    {
    }

    public static function owns(string $path): bool
    {
        return in_array($path, self::ROUTES, true);
    }

    public function handle(Request $request, string $path): ?Response
    {
        if (!self::owns($path)) {
            return null;
        }
        $token = $request->cookie(self::COOKIE);
        $row = null;
        if ($token !== null && $token !== '') {
            try {
                $row = ($this->sessionLookup)($token);
            } catch (\Throwable $e) {
                $this->logger?->warn('demo pages: session lookup failed', ['exception' => get_class($e)]);
            }
        }
        if (!is_array($row)) {
            return Response::html('', 302)->setHeader('Location', '/login')->setHeader('Cache-Control', 'no-store');
        }
        $account = HeaderAccount::fromRow($row);
        $lines = DemoData::lines();
        $sum = DemoData::summary($lines);
        $pay = (string) ($request->query['pay'] ?? 'upi');
        $pay = in_array($pay, ['upi', 'card', 'cod'], true) ? $pay : 'upi';
        $common = ['account' => $account, 'lines' => $lines, 'sum' => $sum, 'smsEnabled' => $this->smsEnabled, 'pay' => $pay];
        switch ($path) {
            case '/checkout/address':
                $body = View::render('demo/checkout', $common + ['step' => 1, 'title' => 'Checkout', 'sub' => 'Complete your order in a few simple steps', 'cta' => ['Proceed to Pay', '/checkout/payment', ''],
                    'main' => View::render('demo/step_address', ['addresses' => DemoData::addresses(), 'options' => DemoData::deliveryOptions()])]);
                return $this->page($request, 'Checkout', $body, ['address-book.js']);
            case '/checkout/payment':
                $body = View::render('demo/checkout', $common + ['step' => 2, 'title' => 'Payment Method', 'sub' => 'Choose a secure payment option', 'cta' => ['Use this payment method', '/checkout/review', ' aria-disabled="true" data-pf="demo-continue"'],
                    'main' => View::render('demo/step_payment', ['options' => DemoData::paymentOptions()])]);
                return $this->page($request, 'Payment Method', $body);
            case '/checkout/review':
                $options = DemoData::paymentOptions();
                $chosen = $options[array_search($pay, array_column($options, 'id'), true) ?: 0];
                if (!$chosen['enabled']) {
                    $chosen = $options[0];
                }
                $body = View::render('demo/checkout', $common + ['step' => 3, 'title' => 'Review & Place Order', 'sub' => 'Please review your order details before placing the order', 'cta' => ['Place Order', '/checkout/demo-confirmation', ''],
                    'main' => View::render('demo/step_review', ['address' => DemoData::addresses()[0], 'lines' => $lines, 'sum' => $sum, 'chosen' => $chosen])]);
                return $this->page($request, 'Review & Place Order', $body);
            case '/checkout/demo-confirmation':
                return $this->page($request, 'Order preview', View::render('demo/confirmation', $common));
            case '/account/payments':
                $inner = View::render('demo/payments', ['options' => DemoData::paymentOptions(), 'saved' => DemoData::savedPayments()]);
                return $this->account($request, $common, 'payments', 'Payment Method', 'Choose a secure payment option', $inner . View::render('demo/card_dialog'));
            case '/account/membership':
                return $this->account($request, $common, 'membership', 'Pratyangi Membership', 'More than shopping, it’s a closer connection to the world of handloom.', View::render('demo/membership', ['m' => DemoData::membership()]));
            case '/account/reviews':
                return $this->account($request, $common, 'reviews', 'My Reviews', 'Your Feedback helps us grow and serve you better.', View::render('demo/reviews', ['reviews' => DemoData::reviews(), 'pending' => DemoData::pendingReviews()]));
            default: // /account/reviews/edit
                $id = (int) ($request->query['id'] ?? 1);
                $review = null;
                foreach (array_merge(DemoData::reviews(), DemoData::pendingReviews()) as $r) {
                    if ($r['id'] === $id) {
                        $review = $r;
                    }
                }
                return $this->page($request, 'Edit Your Review', View::render('demo/review_edit', $common + ['review' => $review ?? DemoData::reviews()[0], 'isNew' => $review !== null && !isset($review['rating'])]), []);
        }
    }

    /** @param array<string,mixed> $common */
    private function account(Request $request, array $common, string $section, string $title, string $sub, string $inner): Response
    {
        $body = View::render('demo/account_page', $common + ['section' => $section, 'title' => $title, 'sub' => $sub, 'inner' => $inner]);
        return $this->page($request, $title, $body, $section === 'reviews' || $section === 'membership' || $section === 'payments' ? [] : []);
    }

    /** @param list<string> $extra */
    private function page(Request $request, string $title, string $body, array $extra = []): Response
    {
        $scripts = array_merge(['site.js', 'bag.js', 'demo.js'], $extra);
        return Response::html(View::render('layout', [
            'title' => $title . ' | Pratyangi Fashions (preview)',
            'description' => 'Preview screen with made-up data.',
            'content' => $body,
            'styles' => self::STYLES,
            'scripts' => $scripts,
            'noindex' => true,
        ]))->setHeader('Cache-Control', 'no-store')->appendHeader('Vary', 'Cookie');
    }
}
