<?php
declare(strict_types=1);

// NEEDS MySQL: only the throwaway database on this machine, port 3399, name ending _test.
// Run via test helper scripts or: PF_TEST_DB_PORT=3399 PF_TEST_DB_NAME=pratyangi_php_test ... php tests/db_address_test.php

require __DIR__ . '/lib/TestCase.php';
require __DIR__ . '/lib/Helpers.php';
require __DIR__ . '/lib/DbGuard.php';

use Pratyangi\Address\AddressLimitException;
use Pratyangi\Address\AddressRepo;
use Pratyangi\Address\AddressValidator;
use Pratyangi\App;
use Pratyangi\Erp\MockErp;
use Pratyangi\Http\DbRateLimitStore;
use Pratyangi\Mail\NullMailer;
use Pratyangi\Security\Passwords;
use Pratyangi\Security\Tokens;
use Pratyangi\Support\Logger;

if ((int) (getenv('PF_TEST_DB_PORT') ?: 0) !== 3399) {
    fwrite(STDERR, "REFUSED: address database tests run only on port 3399\n");
    exit(2);
}

$pdo = DbGuard::connect();
DbGuard::clean($pdo);

$t = new TestCase('db_address_test');
$origin = ['Origin' => Helpers::ORIGIN];
$clock = new FixedClock((float) (intdiv(time(), 86400) * 86400 + 43200));
$config = Helpers::config(['OTP_PEPPER' => str_repeat('A', 48)]);

$app = new App(
    $config,
    new Logger('warn'),
    $clock,
    static fn (): \PDO => $pdo,
    new NullMailer(),
    new MockErp(dirname(__DIR__) . '/tests/fixtures/mock_erp.json', 'offer-else-retail', 'exclusive'),
    new DbRateLimitStore(static fn (): \PDO => $pdo),
    [],
    null,
    null
);

// Create test customers A and B
$insertCust = $pdo->prepare('INSERT INTO shop_customers (name, email, password_hash, email_verified_at, created_at, updated_at) VALUES (?, ?, ?, NOW(3), NOW(3), NOW(3))');
$hash = Passwords::hash('TestPass123');

$insertCust->execute(['Customer A', 'customer.a@example.com', $hash]);
$custA = (int) $pdo->lastInsertId();

$insertCust->execute(['Customer B', 'customer.b@example.com', $hash]);
$custB = (int) $pdo->lastInsertId();

use Pratyangi\Auth\SessionRepo;

$sessionRepo = new SessionRepo($pdo);
$makeSession = function (int $customerId) use ($sessionRepo): array {
    $token = Tokens::newToken();
    $sessionRepo->create($customerId, Tokens::sha256($token), (float) (time() + 86400 * 30), 'TestAgent');
    return ['Cookie' => 'pf_session=' . $token];
};

$cookieA = $makeSession($custA);
$cookieB = $makeSession($custB);

$sampleAddr = function (string $name = 'Priya Rao', string $house = '12-3-45'): array {
    return [
        'type' => 'Home',
        'label' => '',
        'name' => $name,
        'phone' => '9876543210',
        'alternatePhone' => '',
        'house' => $house,
        'street' => 'Road No. 5, Banjara Hills',
        'city' => 'Hyderabad',
        'state' => 'Telangana',
        'pincode' => '500034',
        'isDefault' => false,
    ];
};

$t->test('Default rule: first address is always default, even if requested false', function () use ($t, $app, $origin, $cookieA, $sampleAddr): void {
    $req = Helpers::request('POST', '/api/v1/addresses', $origin + $cookieA, $sampleAddr('First Addr'));
    $res = $app->handle($req);

    $t->same(201, $res->status());
    $body = json_decode($res->body(), true);
    $t->same(true, $body['address']['isDefault'], 'first address becomes default');
    $t->same('First Addr', $body['address']['name']);
    $t->same('Home', $body['address']['label'], 'empty label falls back to type');
    $t->same('+91 9876543210', $body['address']['phone']);
});

$t->test('Second address with isDefault false stays non-default; address 1 remains default', function () use ($t, $app, $origin, $cookieA, $sampleAddr): void {
    $req = Helpers::request('POST', '/api/v1/addresses', $origin + $cookieA, $sampleAddr('Second Addr', 'Flat 101'));
    $res = $app->handle($req);

    $t->same(201, $res->status());
    $body = json_decode($res->body(), true);
    $t->same(false, $body['address']['isDefault'], 'second address is non-default');

    // GET /addresses lists default first, then id ascending
    $listReq = Helpers::request('GET', '/api/v1/addresses', $cookieA);
    $listRes = $app->handle($listReq);
    $t->same(200, $listRes->status());
    $list = json_decode($listRes->body(), true)['addresses'];
    $t->same(2, count($list));
    $t->same(true, $list[0]['isDefault'], 'default address is first');
    $t->same('First Addr', $list[0]['name']);
    $t->same(false, $list[1]['isDefault']);
    $t->same('Second Addr', $list[1]['name']);
});

$t->test('Setting default moves default and unsets previous; PUT isDefault false does not clear default', function () use ($t, $app, $origin, $cookieA, $sampleAddr): void {
    $listReq = Helpers::request('GET', '/api/v1/addresses', $cookieA);
    $list = json_decode($app->handle($listReq)->body(), true)['addresses'];
    $addr1Id = $list[0]['id'];
    $addr2Id = $list[1]['id'];

    // POST /addresses/:id/default on addr 2
    $defReq = Helpers::request('POST', '/api/v1/addresses/' . $addr2Id . '/default', $origin + $cookieA, []);
    $defRes = $app->handle($defReq);
    $t->same(200, $defRes->status());
    $defList = json_decode($defRes->body(), true)['addresses'];

    $t->same(2, count($defList));
    $t->same($addr2Id, $defList[0]['id'], 'addr 2 is now first and default');
    $t->same(true, $defList[0]['isDefault']);
    $t->same($addr1Id, $defList[1]['id'], 'addr 1 is now non-default');
    $t->same(false, $defList[1]['isDefault']);

    // PUT on addr 2 with isDefault: false should NOT clear default
    $updateData = $sampleAddr('Second Addr Updated', 'Flat 102');
    $updateData['isDefault'] = false;
    $putReq = Helpers::request('PUT', '/api/v1/addresses/' . $addr2Id, $origin + $cookieA, $updateData);
    $putRes = $app->handle($putReq);
    $t->same(200, $putRes->status());
    $updated = json_decode($putRes->body(), true)['address'];
    $t->same(true, $updated['isDefault'], 'isDefault: false on current default does not clear it');
});

$t->test('Ownership isolation: Customer B can never read, update, delete or setDefault Customer A address', function () use ($t, $app, $origin, $cookieA, $cookieB, $sampleAddr): void {
    // Customer A addresses
    $listReqA = Helpers::request('GET', '/api/v1/addresses', $cookieA);
    $listA = json_decode($app->handle($listReqA)->body(), true)['addresses'];
    $targetId = $listA[0]['id'];

    // Customer B GET /addresses: empty
    $listReqB = Helpers::request('GET', '/api/v1/addresses', $cookieB);
    $listB = json_decode($app->handle($listReqB)->body(), true)['addresses'];
    $t->same([], $listB, 'Customer B starts with no addresses');

    // Customer B attempts PUT on Customer A address -> 404
    $putB = Helpers::request('PUT', '/api/v1/addresses/' . $targetId, $origin + $cookieB, $sampleAddr('Hacked Addr'));
    $resPutB = $app->handle($putB);
    $t->same(404, $resPutB->status());
    $t->same('We could not find that address. It may have been deleted.', json_decode($resPutB->body(), true)['message']);

    // Customer B attempts POST /default on Customer A address -> 404
    $defB = Helpers::request('POST', '/api/v1/addresses/' . $targetId . '/default', $origin + $cookieB, []);
    $resDefB = $app->handle($defB);
    $t->same(404, $resDefB->status());
    $t->same('We could not find that address. It may have been deleted.', json_decode($resDefB->body(), true)['message']);

    // Customer B attempts DELETE on Customer A address -> 404
    $delB = Helpers::request('DELETE', '/api/v1/addresses/' . $targetId, $origin + $cookieB);
    $resDelB = $app->handle($delB);
    $t->same(404, $resDelB->status());
    $t->same('We could not find that address. It may have been deleted.', json_decode($resDelB->body(), true)['message']);

    // Non-numeric ID returns 404
    $badIdReq = Helpers::request('DELETE', '/api/v1/addresses/not-a-number', $origin + $cookieA);
    $t->same(404, $app->handle($badIdReq)->status());

    // Customer A address remains untouched
    $checkA = json_decode($app->handle($listReqA)->body(), true)['addresses'];
    $t->same(2, count($checkA));
    $t->same($targetId, $checkA[0]['id']);
});

$t->test('Deleting default address promotes newest remaining address to default', function () use ($t, $app, $origin, $cookieA, $sampleAddr): void {
    // Customer A adds a 3rd address
    $add3 = Helpers::request('POST', '/api/v1/addresses', $origin + $cookieA, $sampleAddr('Third Addr', 'Villa 3'));
    $res3 = $app->handle($add3);
    $t->same(201, $res3->status());
    $addr3Id = json_decode($res3->body(), true)['address']['id'];

    // Get current addresses
    $list = json_decode($app->handle(Helpers::request('GET', '/api/v1/addresses', $cookieA))->body(), true)['addresses'];
    $t->same(3, count($list));
    $defaultAddr = $list[0];
    $t->same(true, $defaultAddr['isDefault']);
    $delId = $defaultAddr['id'];

    // Delete the default address
    $delReq = Helpers::request('DELETE', '/api/v1/addresses/' . $delId, $origin + $cookieA);
    $delRes = $app->handle($delReq);
    $t->same(204, $delRes->status());

    // Re-check list: newest remaining address (addr 3) should now be default
    $afterList = json_decode($app->handle(Helpers::request('GET', '/api/v1/addresses', $cookieA))->body(), true)['addresses'];
    $t->same(2, count($afterList));
    $t->same($addr3Id, $afterList[0]['id'], 'newest remaining address is now default');
    $t->same(true, $afterList[0]['isDefault']);
});

$t->test('10-address limit enforced; 11th address fails with 422; deleting one allows adding', function () use ($t, $app, $origin, $cookieA, $sampleAddr): void {
    // Currently Customer A has 2 addresses
    $current = json_decode($app->handle(Helpers::request('GET', '/api/v1/addresses', $cookieA))->body(), true)['addresses'];
    $count = count($current);

    // Add up to 10
    for ($i = $count + 1; $i <= 10; $i++) {
        $r = $app->handle(Helpers::request('POST', '/api/v1/addresses', $origin + $cookieA, $sampleAddr("Address $i", "House $i")));
        $t->same(201, $r->status());
    }

    $tenList = json_decode($app->handle(Helpers::request('GET', '/api/v1/addresses', $cookieA))->body(), true)['addresses'];
    $t->same(10, count($tenList));

    // 11th address must fail with 422
    $failReq = Helpers::request('POST', '/api/v1/addresses', $origin + $cookieA, $sampleAddr('Address 11', 'House 11'));
    $failRes = $app->handle($failReq);
    $t->same(422, $failRes->status());
    $failBody = json_decode($failRes->body(), true);
    $t->same('You can save up to 10 addresses. Please delete one to add another.', $failBody['message']);

    // Delete one address
    $toDelete = $tenList[9]['id'];
    $delRes = $app->handle(Helpers::request('DELETE', '/api/v1/addresses/' . $toDelete, $origin + $cookieA));
    $t->same(204, $delRes->status());

    // Now adding 10th again succeeds
    $addAgain = $app->handle(Helpers::request('POST', '/api/v1/addresses', $origin + $cookieA, $sampleAddr('New 10th Addr', 'House New')));
    $t->same(201, $addAgain->status());
});

exit($t->finish());
