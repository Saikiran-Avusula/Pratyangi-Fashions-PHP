<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/TestCase.php';
require_once __DIR__ . '/lib/Helpers.php';
require_once dirname(__DIR__) . '/app/bootstrap.php';

use Pratyangi\Address\Address;
use Pratyangi\Address\AddressValidator;
use Pratyangi\Http\Router;

$t = new TestCase('address_logic');

$t->test('AddressValidator: valid payload succeeds and normalises fields', function () use ($t): void {
    $body = [
        'type' => 'Home',
        'label' => 'Main House',
        'name' => 'Priya Rao',
        'phone' => '+91 98765 43210',
        'alternatePhone' => '09123456789',
        'house' => '12-3-45',
        'street' => 'Road No. 5, Banjara Hills',
        'city' => 'Hyderabad',
        'state' => 'Telangana',
        'pincode' => '500034',
        'isDefault' => true,
    ];

    $res = AddressValidator::validate($body);
    $t->same([], $res['errors']);
    $t->same(null, $res['firstField']);
    $t->same('Home', $res['data']['type']);
    $t->same('Main House', $res['data']['label']);
    $t->same('Priya Rao', $res['data']['recipient_name']);
    $t->same('9876543210', $res['data']['phone']);
    $t->same('9123456789', $res['data']['alternate_phone']);
    $t->same('12-3-45', $res['data']['house']);
    $t->same('Road No. 5, Banjara Hills', $res['data']['street']);
    $t->same('Hyderabad', $res['data']['city']);
    $t->same('Telangana', $res['data']['state']);
    $t->same('500034', $res['data']['pincode']);
    $t->same(true, $res['data']['isDefault']);
});

$t->test('AddressValidator: phone normalisation handles spaces, dashes, +91, 91, 0', function () use ($t): void {
    $t->same('9876543210', AddressValidator::cleanPhone('+91 98765-43210'));
    $t->same('9876543210', AddressValidator::cleanPhone('919876543210'));
    $t->same('9876543210', AddressValidator::cleanPhone('09876543210'));
    $t->same('9876543210', AddressValidator::cleanPhone('98765 43210'));
    $t->same('9876543210', AddressValidator::cleanPhone('9876543210'));
});

$t->test('AddressValidator: missing and invalid fields produce 422 error map', function () use ($t): void {
    $res = AddressValidator::validate([]);
    $t->ok(isset($res['errors']['name']), 'name required');
    $t->same("Enter the recipient's name.", $res['errors']['name']);
    $t->ok(isset($res['errors']['phone']), 'phone required');
    $t->same('Enter a mobile number.', $res['errors']['phone']);
    $t->ok(isset($res['errors']['house']), 'house required');
    $t->ok(isset($res['errors']['street']), 'street required');
    $t->ok(isset($res['errors']['city']), 'city required');
    $t->ok(isset($res['errors']['state']), 'state required');
    $t->ok(isset($res['errors']['pincode']), 'pincode required');

    // Invalid phone: starting with 5 or < 10 digits
    $res2 = AddressValidator::validate(['name' => 'Al', 'phone' => '5123456789', 'house' => 'H', 'street' => 'S', 'city' => 'Ci', 'state' => 'Telangana', 'pincode' => '500034']);
    $t->same('Enter a valid 10-digit mobile number.', $res2['errors']['phone']);

    // Invalid state
    $res3 = AddressValidator::validate(['name' => 'Priya', 'phone' => '9876543210', 'house' => 'H', 'street' => 'S', 'city' => 'Ci', 'state' => 'Atlantis', 'pincode' => '500034']);
    $t->same('Choose a state or union territory.', $res3['errors']['state']);

    // Invalid pincode: starting with 0 or 5 digits
    $res4 = AddressValidator::validate(['name' => 'Priya', 'phone' => '9876543210', 'house' => 'H', 'street' => 'S', 'city' => 'Ci', 'state' => 'Telangana', 'pincode' => '050034']);
    $t->same('Enter a valid 6-digit PIN code.', $res4['errors']['pincode']);
    $res4b = AddressValidator::validate(['name' => 'Priya', 'phone' => '9876543210', 'house' => 'H', 'street' => 'S', 'city' => 'Ci', 'state' => 'Telangana', 'pincode' => '50003']);
    $t->same('Enter a valid 6-digit PIN code.', $res4b['errors']['pincode']);

    // Invalid type
    $res5 = AddressValidator::validate(['type' => 'Vacation', 'name' => 'Priya', 'phone' => '9876543210', 'house' => 'H', 'street' => 'S', 'city' => 'Ci', 'state' => 'Telangana', 'pincode' => '500034']);
    $t->same('Choose an address type (Home, Office or Other).', $res5['errors']['type']);
});

$t->test('Address DTO formatting matches API_CONTRACT section 2.4', function () use ($t): void {
    $row = [
        'id' => 5,
        'customer_id' => 1,
        'type' => 'Home',
        'label' => '',
        'recipient_name' => 'Priya Rao',
        'phone' => '9876543210',
        'alternate_phone' => '9123456789',
        'house' => '12-3-45',
        'street' => 'Road No. 5, Banjara Hills',
        'city' => 'Hyderabad',
        'state' => 'Telangana',
        'pincode' => '500034',
        'is_default' => 1,
    ];

    $addr = Address::fromRow($row);
    $dto = $addr->toDto();

    $t->same('5', $dto['id']);
    $t->same('Home', $dto['type']);
    $t->same('Home', $dto['label'], 'empty label falls back to type');
    $t->same(true, $dto['isDefault']);
    $t->same('Priya Rao', $dto['name']);
    $t->same('+91 9876543210', $dto['phone']);
    $t->same('+91 9123456789', $dto['alternatePhone']);
    $t->same('12-3-45', $dto['house']);
    $t->same('Road No. 5, Banjara Hills', $dto['street']);
    $t->same('Hyderabad', $dto['city']);
    $t->same('Telangana', $dto['state']);
    $t->same('500034', $dto['pincode']);

    // Alternate phone omitted when empty
    $rowNoAlt = $row;
    $rowNoAlt['alternate_phone'] = null;
    $rowNoAlt['label'] = 'Custom';
    $addrNoAlt = Address::fromRow($rowNoAlt);
    $dtoNoAlt = $addrNoAlt->toDto();
    $t->ok(!array_key_exists('alternatePhone', $dtoNoAlt), 'alternatePhone omitted when empty');
    $t->same('Custom', $dtoNoAlt['label']);
});

$t->test('Router: matches /addresses and parameterised /addresses/:id routes', function () use ($t): void {
    $r = new Router();
    $dummy = static fn (): \Pratyangi\Http\Response => \Pratyangi\Http\Response::empty(200);

    $r->add('GET', '/addresses', $dummy, null, true);
    $r->add('POST', '/addresses', $dummy, null, true);
    $r->add('PUT', '/addresses/:id', $dummy, null, true);
    $r->add('DELETE', '/addresses/:id', $dummy, null, true);
    $r->add('POST', '/addresses/:id/default', $dummy, null, true);

    $mGet = $r->find('GET', '/addresses');
    $t->ok($mGet !== null);
    $t->same([], $mGet['params']);

    $mPost = $r->find('POST', '/addresses');
    $t->ok($mPost !== null);

    $mPut = $r->find('PUT', '/addresses/42');
    $t->ok($mPut !== null);
    $t->same(['42'], $mPut['params']);

    $mDel = $r->find('DELETE', '/addresses/42');
    $t->ok($mDel !== null);
    $t->same(['42'], $mDel['params']);

    $mDef = $r->find('POST', '/addresses/42/default');
    $t->ok($mDef !== null);
    $t->same(['42'], $mDef['params']);

    $mHead = $r->find('HEAD', '/addresses');
    $t->ok($mHead !== null);

    $mNone = $r->find('GET', '/addresses/42/extra');
    $t->same(null, $mNone);
});

$t->test('API: address routes enforce auth (401 without cookie) and CSRF (403 on bad Origin)', function () use ($t): void {
    $app = Helpers::app();

    // 401 without auth cookie
    $getReq = Helpers::request('GET', '/api/v1/addresses');
    $t->same(401, $app->handle($getReq)->status());

    $postReq = Helpers::request('POST', '/api/v1/addresses', ['Origin' => Helpers::ORIGIN], ['name' => 'Test']);
    $t->same(401, $app->handle($postReq)->status());

    // 403 CSRF check comes before 401
    $csrfBadReq = Helpers::request('POST', '/api/v1/addresses', ['Origin' => 'https://evil.invalid'], ['name' => 'Test']);
    $t->same(403, $app->handle($csrfBadReq)->status());

    $csrfMissingReq = Helpers::request('POST', '/api/v1/addresses', [], ['name' => 'Test']);
    $t->same(403, $app->handle($csrfMissingReq)->status());
});

exit($t->finish());
