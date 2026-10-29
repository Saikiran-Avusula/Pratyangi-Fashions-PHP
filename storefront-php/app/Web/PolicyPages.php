<?php
declare(strict_types=1);

namespace Pratyangi\Web;

use Pratyangi\Http\Request;
use Pratyangi\Http\Response;
use Pratyangi\Support\Logger;
use Pratyangi\Views\ShopInfo;
use Pratyangi\Views\View;

/**
 * GET /privacy, /terms, /refund-policy, /shipping-policy, /contact. Every text is a DRAFT placeholder for the shop owner (and the shop's adviser) to
 * replace; the shop's name, address and email come from the SHOP_* settings, never from this file. Page text only: no forms, no scripts of its own.
 */
final class PolicyPages
{
    private const COOKIE = 'pf_session';

    /** @param (\Closure(string):(array<string,mixed>|null))|null $sessionLookup */
    public function __construct(private ?\Closure $sessionLookup = null, private ?Logger $logger = null)
    {
    }

    /** @return array<string,string> path => page key */
    public static function paths(): array
    {
        return ['/privacy' => 'privacy', '/terms' => 'terms', '/refund-policy' => 'refund', '/shipping-policy' => 'shipping', '/contact' => 'contact', '/cookie-policy' => 'cookies'];
    }

    public function show(Request $request, string $key): Response
    {
        [$title, $intro, $sections] = $this->content($key);
        $account = $this->account($request->cookie(self::COOKIE));
        $body = View::render('shop/policy', ['account' => $account, 'pageTitle' => $title, 'intro' => $intro, 'sections' => $sections, 'contact' => $key === 'contact']);
        $response = Response::html(View::render('layout', [
            'title' => $title . ' | ' . ShopInfo::brandName(),
            'description' => $title . ' of ' . ShopInfo::legalName() . '.',
            'content' => $body,
            'scripts' => ['site.js', 'bag.js'],
        ]));
        if ($request->cookie(self::COOKIE) !== null) {
            $response->setHeader('Cache-Control', 'private, no-store')->appendHeader('Vary', 'Cookie'); // the header differs per visitor
        }
        return $response;
    }

    private function account(?string $token): ?HeaderAccount
    {
        if ($token === null || $token === '' || $this->sessionLookup === null) {
            return null;
        }
        try {
            $row = ($this->sessionLookup)($token);
            return is_array($row) ? HeaderAccount::fromRow($row) : null;
        } catch (\Throwable $e) {
            $this->logger?->warn('policy pages: session lookup failed', ['exception' => get_class($e)]);
            return null;
        }
    }

    /** @return array{0:string,1:string,2:list<array{0:string,1:list<string>}>} title, intro, sections of [heading, paragraphs] (plain text; the view escapes it) */
    private function content(string $key): array
    {
        $name = ShopInfo::legalName();
        $brand = ShopInfo::brandName();
        $seller = $brand !== $name ? ShopInfo::brandLine() . " is the seller of every order." : "{$name} is the seller of every order.";
        $email = ShopInfo::email() !== '' ? ShopInfo::email() : 'the support email address shown on the Contact page';
        return match ($key) {
            'privacy' => ['Privacy Policy', "How {$name} handles your personal information.", [
                ['Who we are', [$seller]],
                ['What we collect', ['Your name, email address and/or mobile number, the delivery addresses you save, and the details of the orders you place. When you pay online, the payment is taken by our payment provider; your card or UPI details never reach this website.']],
                ['Why we use it', ['To run your account, take and deliver your orders, send you the codes you ask for, and answer your questions.']],
                ['Who else sees it', ['Our payment provider (to take your payment), our delivery partners (your name, address and phone, to deliver) and our stock and order system. We do not sell your information.']],
                ['Cookies', ['We use two essential cookies: one keeps you signed in and holds no personal details, the other remembers your cookie choice. See the Cookie Policy for the full list.']],
                ['Your choices', ["To see, correct or delete your information, write to {$email}."]],
                ['How long we keep it', [self::tbd()]],
            ]],
            'terms' => ['Terms & Conditions', "The terms on which {$name} sells to you.", [
                ['Who sells to you', [$seller]],
                ['Orders and prices', ['Prices are in Indian rupees. Tax is added at checkout. Delivery charges are confirmed by the shop and are not part of the total shown at checkout. An order is accepted when we confirm it.']],
                ['Payment', ['Online payments are processed by our payment provider. If an item turns out to be unavailable after you have paid, we will refund the payment.']],
                ['Your account', ['Keep your sign-in details to yourself. Tell us at once if you think someone else has used your account.']],
                ['Products', ['Handloom and silk sarees can differ slightly from their photos in colour and finish. This is the nature of the cloth, not a defect.']],
                ['Changes', ['We may change these terms. The terms in force when you order apply to that order.']],
                ['Governing law and disputes', [self::tbd()]],
            ]],
            'refund' => ['Refund Policy', "When and how {$name} refunds or exchanges an order.", [
                ['Returns and exchanges', [self::tbd()]],
                ['Refunds', ['Refunds go back to the original payment method.', self::tbd()]],
                ['If we cannot supply your order', ['If an item you paid for is no longer available, we refund the full amount you paid.']],
                ['How to ask', ["Write to {$email} with your order number."]],
            ]],
            'shipping' => ['Shipping Policy', "How {$name} delivers your order.", [
                ['Where we deliver', [self::tbd()]],
                ['Delivery charges', ['Delivery charges are confirmed by the shop and are not included in the total shown at checkout.']],
                ['Delivery time', [self::tbd()]],
                ['Tracking', ['When your order is dispatched we will share the courier and tracking number.', self::tbd()]],
            ]],
            'cookies' => ['Cookie Policy', "The cookies and similar storage {$name} uses on this site.", [
                ['Essential only', ['Today this site sets only what it needs to work. There are no analytics or marketing cookies. You can change your choice at any time with "Cookie settings" in the footer.']],
                ['pf_session (cookie, essential)', ['Keeps you signed in. Set when you sign in or create an account; it holds a random sign-in token and no personal details. It lasts up to 30 days or until you sign out.']],
                ['pf_consent (cookie, essential)', ['Remembers your cookie choice so we do not ask again. It lasts 12 months.']],
                ['Security checks (no cookie)', ['We protect forms and sign-in against cross-site requests by checking where a request comes from. This sets no cookie.']],
                ['Your bag and wishlist (browser storage, essential)', ['Your bag and wishlist are kept in your browser (local storage) so they are still there when you come back. The address you pick at checkout is kept in session storage until you close the tab. None of this is sent to advertisers.']],
                ['Google sign-in (third party, sign-in and sign-up pages only)', ['When Google sign-in is switched on, the sign-in and sign-up pages load Google\'s button from Google. Google may set its own cookies there, under Google\'s policy. No other page loads it.']],
                ['Analytics and marketing', ['None are used. If we add any later, they will stay off until you say yes.']],
            ]],
            default => ['Contact us', 'How to reach us.', []],
        };
    }

    /** The one neutral line for every detail the shop has not decided yet. */
    private static function tbd(): string
    {
        return 'Details will be confirmed at order time.' . (ShopInfo::email() === '' ? '' : ' Contact us at ' . ShopInfo::email() . '.');
    }
}
