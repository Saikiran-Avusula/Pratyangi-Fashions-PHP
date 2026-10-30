<?php
declare(strict_types=1);

namespace Pratyangi\Demo;

use Pratyangi\Catalog\ProductImage;

/**
 * ALL the made-up data of the preview screens (DEMO_UI=true). Nothing here is read from or written to the database, the ERP, Razorpay or any mailbox.
 * Field names follow what the real code will return (money in integer paise, tax_rate as the ERP gives it), so replacing a method with a real
 * source changes the data source only, not the pages. docs/figma/DEMO_MAP.md lists every method and what replaces it.
 */
final class DemoData
{
    public const COUPON_CODE = 'WELCOME10';
    public const COUPON_PERCENT = 10;

    /** "₹12,499" (Indian grouping; paise only when there are some). */
    public static function inr(int $paise): string
    {
        $neg = $paise < 0;
        $paise = abs($paise);
        $whole = (string) intdiv($paise, 100);
        if (strlen($whole) > 3) {
            $whole = preg_replace('/\B(?=(\d{2})+$)/', ',', substr($whole, 0, -3)) . ',' . substr($whole, -3);
        }
        return ($neg ? '- ' : '') . '₹' . $whole . ($paise % 100 !== 0 ? '.' . str_pad((string) ($paise % 100), 2, '0', STR_PAD_LEFT) : '');
    }

    /** Replaced by: the saved bag priced by the catalogue (sku, name, mrp, retail/offer price, tax_rate from the ERP). @return list<array<string,mixed>> */
    public static function lines(): array
    {
        $row = static fn (string $sku, string $name, string $variant, int $mrp, int $price): array => ['sku' => $sku, 'name' => $name, 'variant' => $variant, 'image' => ProductImage::PLACEHOLDER,
            'mrpPaise' => $mrp, 'pricePaise' => $price, 'qty' => 1, 'taxRate' => '5.00'];
        return [
            $row('DEMO-001', 'Kanchipuram Silk Saree', 'Maroon with Gold Zari', 1599900, 1249900),
            $row('DEMO-002', 'Banarasi Georgette Saree', 'Rose Pink with Silver Zari', 1199900, 899900),
            $row('DEMO-003', 'Chanderi Cotton Silk Saree', 'Emerald Green with Gold Border', 799900, 649900),
        ];
    }

    /**
     * The price rule of every order screen: Subtotal, Discount, GST (added on top, per line at the ERP tax_rate), Shipping, Total. Never "inclusive of all taxes".
     * Replaced by: the order pricing of the checkout API.
     * @param list<array<string,mixed>> $lines
     * @return array<string,int|string>
     */
    public static function summary(array $lines): array
    {
        $subtotal = $discount = $gst = $items = 0;
        $rates = [];
        foreach ($lines as $l) {
            $line = $l['pricePaise'] * $l['qty'];
            $off = intdiv($line * self::COUPON_PERCENT, 100);
            $subtotal += $line;
            $discount += $off;
            $gst += intdiv(($line - $off) * (int) round((float) $l['taxRate'] * 100) + 5000, 10000);
            $items += $l['qty'];
            $rates[(string) (float) $l['taxRate']] = true;
        }
        $shipping = 0;
        return ['items' => $items, 'subtotalPaise' => $subtotal, 'discountPaise' => $discount, 'gstPaise' => $gst, 'gstRate' => implode('/', array_keys($rates)), 'shippingPaise' => $shipping,
            'totalPaise' => $subtotal - $discount + $gst + $shipping, 'savingsPaise' => $discount];
    }

    /** Replaced by: GET /addresses (the customer's saved addresses). @return list<array<string,mixed>> */
    public static function addresses(): array
    {
        return [
            ['id' => 1, 'type' => 'Home', 'isDefault' => true, 'name' => 'Gaurav Kumar', 'phone' => '9876543210', 'line' => '50-1-16 Seethammadara Eenadu Main Road, near Sivalayam Visakhapatnam - 530013'],
            ['id' => 2, 'type' => 'Other', 'isDefault' => false, 'name' => 'Gaurav Kumar', 'phone' => '9876543210', 'line' => 'MG Road, Vijayawada Andhra Pradesh - 520010'],
            ['id' => 3, 'type' => 'Office', 'isDefault' => false, 'name' => 'Surya', 'phone' => '9876543210', 'line' => 'Level 1, Unit 2, Salarpuria Sattva Knowledge City, Sy. No. 83/1, Plot No. 2 Inorbit Mall Road, Raidurg Village, HITEC City, Hyderabad, Telangana 500081, India'],
        ];
    }

    /** Replaced by: the shipping rules of the shop. @return list<array<string,mixed>> */
    public static function deliveryOptions(): array
    {
        return [
            ['id' => 'standard', 'name' => 'Standard Delivery', 'price' => 'FREE', 'days' => '4 - 6 business days', 'note' => 'Reliable and safe delivery'],
            ['id' => 'express', 'name' => 'Express Delivery', 'price' => '₹99', 'days' => '2 - 3 business days', 'note' => 'Faster delivery'],
        ];
    }

    /** True once cash on delivery really exists (a later setting); until then the option shows "Coming soon" and cannot be chosen. */
    public static function codEnabled(): bool
    {
        return false;
    }

    /** Replaced by: the payment methods Razorpay offers for the order. @return list<array<string,mixed>> */
    public static function paymentOptions(): array
    {
        return [
            ['id' => 'upi', 'title' => 'UPI (Recommended)', 'sub' => 'Pay using any UPI app', 'chips' => ['GPay', 'PhonePe', 'Paytm', 'BHIM'], 'enabled' => true],
            ['id' => 'card', 'title' => 'Credit / Debit Card', 'sub' => 'Visa, Mastercard, RuPay & more', 'chips' => ['Visa', 'Mastercard', 'RuPay'], 'enabled' => true],
            ['id' => 'cod', 'title' => 'Cash on Delivery / Pay on Delivery', 'sub' => '', 'chips' => [], 'enabled' => self::codEnabled()],
        ];
    }

    /** Replaced by: saved Razorpay tokens (masked by Razorpay; the shop never holds a card number). @return array{upi:list<array<string,string>>,cards:list<array<string,string>>} */
    public static function savedPayments(): array
    {
        return [
            'upi' => [['id' => 'u1', 'label' => 'gaurav@okhdfcbank'], ['id' => 'u2', 'label' => 'gaurav.kumar@ybl']],
            'cards' => [['id' => 'c1', 'label' => 'Visa •••• 4242', 'nick' => 'Gaurav Kumar', 'expiry' => '08/28'], ['id' => 'c2', 'label' => 'Mastercard •••• 5100', 'nick' => 'Office card', 'expiry' => '11/27']],
        ];
    }

    /** Replaced by: the customer's membership record (a later feature). @return array<string,mixed> */
    public static function membership(): array
    {
        return [
            'current' => 'Silver',
            'tiers' => [
                ['id' => 'silver', 'name' => 'Silver', 'badge' => 'AVAILABLE TO ALL', 'text' => 'Try all our beautiful silver sarees today.', 'cta' => 'Continue with Silver'],
                ['id' => 'platinum', 'name' => 'Platinum', 'badge' => 'UNLOCKABLE', 'text' => 'Purchase a Silver saree to unlock Platinum Try-On.', 'cta' => 'Unlock with Silver Purchase'],
                ['id' => 'gold', 'name' => 'Gold', 'badge' => 'NOT AVAILABLE FOR TRY-ON', 'text' => 'Available for direct purchase only.', 'cta' => 'Get Gold Membership'],
            ],
            'benefits' => [
                ['Try-On Access', 'Silver Collection', 'Silver + Platinum Collection', 'Not Available'],
                ['Discount on Orders', '20% on first order', 'Extra offers & early access', '15% on bridal collections (above ₹15,000)'],
                ['Free Blouse Piece', '—', 'With every saree', 'With selected collections'],
                ['Early Access to New Arrivals', '—', '✓', '✓'],
                ['Exclusive Festival Offers', '✓', '✓', '✓'],
                ['Personal Styling Support', '—', '✓', '✓'],
            ],
            'why' => [['More Savings', 'Enjoy member-only discounts and offers'], ['Premium Collections', 'Get access to exclusive saree collections'], ['Early Access', 'Be the first to shop new arrivals'], ['Personalised Experience', 'Styling support and recommendations']],
            'faq' => [['How do I unlock Platinum Try-On access?', 'Purchase any Silver saree and Platinum Try-On unlocks on your account.'], ['Can I use multiple offers together?', 'One offer or coupon applies to an order, whichever saves you more.'], ['Is Try-On available for all collections?', 'Try-On is available for Silver and Platinum collections only.']],
        ];
    }

    /** Replaced by: the customer's reviews (a later feature). @return list<array<string,mixed>> */
    public static function reviews(): array
    {
        $lorem = 'Lorem ipsum dolor sit amet consectetur. Quis amet nunc orci auctor lectus in maecenas arcu augue. Egestas neque turpis etiam vel commodo nibh sem parturient tincidunt. Risus sed libero etiam maecenas sed lacus duis.';
        $row = static fn (int $id, string $text, int $photos): array => ['id' => $id, 'sku' => 'DEMO-001', 'name' => 'Kanchipuram Silk Saree', 'variant' => 'Maroon with Gold Zari', 'image' => ProductImage::PLACEHOLDER,
            'rating' => 4.5, 'text' => $text, 'photos' => $photos, 'date' => '5 Oct 2027'];
        return [$row(1, $lorem, 2), $row(2, 'Lorem ipsum dolor sit amet consectetur.', 5), $row(3, substr($lorem, 0, 135) . ' etiam.', 1), $row(4, 'Lovely drape and colour.', 0)];
    }

    /** Replaced by: GET /orders (the customer's orders; a later phase). Three states: delivered, shipped (can be tracked), placed. @return list<array<string,mixed>> */
    public static function orders(): array
    {
        $row = static fn (string $no, string $sku, string $name, string $variant, string $placed, string $status, string $note, bool $track): array => ['orderNo' => $no, 'sku' => $sku, 'name' => $name, 'variant' => $variant,
            'image' => ProductImage::PLACEHOLDER, 'placed' => $placed, 'status' => $status, 'note' => $note, 'canTrack' => $track];
        return [
            $row('PF12345', 'DEMO-001', 'Kanchipuram Silk Saree', 'Maroon with Gold Zari', '2 Oct 2026', 'Delivered', 'on 5 Oct 2026', false),
            $row('PF12346', 'DEMO-002', 'Banarasi Georgette Saree', 'Rose Pink with Silver Zari', '22 Sep 2026', 'Shipped', 'Expected by 8 Oct 2026', true),
            $row('PF12347', 'DEMO-003', 'Chanderi Cotton Silk Saree', 'Emerald Green with Gold Border', '29 Oct 2026', 'Placed', 'We are preparing your order', false),
        ];
    }

    /** Replaced by: delivered order lines with no review yet.
 @return list<array<string,mixed>> */
    public static function pendingReviews(): array
    {
        return [['id' => 5, 'sku' => 'DEMO-002', 'name' => 'Banarasi Georgette Saree', 'variant' => 'Rose Pink with Silver Zari', 'image' => ProductImage::PLACEHOLDER, 'date' => 'Delivered 2 Oct 2027']];
    }
}
