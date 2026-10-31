<?php
declare(strict_types=1);

// No database, no browser: the catalogue search rule on the mock catalogue (every typed word starts a word of name/fabric/category/colour).
require __DIR__ . '/lib/TestCase.php';
require __DIR__ . '/lib/Helpers.php';

$t = new TestCase('search_relevance_test');
$skus = static function (string $q): array {
    $r = Helpers::app()->handle(Helpers::request('GET', '/api/v1/catalog/products?q=' . rawurlencode($q)));
    return array_map(static fn (array $p): string => $p['sku'], json_decode($r->body(), true)['products'] ?? []);
};
$t->test('cotton returns only the cotton items', function () use ($t, $skus): void {
    $t->same(['MOCK-SAR-002', 'MOCK-KUR-001'], $skus('cotton'));
});
$t->test('every word must match', function () use ($t, $skus): void {
    $t->same(['MOCK-SAR-001'], $skus('silk saree'));
    $t->same([], $skus('silk kurti'));
});
$t->test('word prefixes match, middles of words and nonsense do not', function () use ($t, $skus): void {
    $t->same(['MOCK-SAR-002', 'MOCK-KUR-001'], $skus('cott'));
    $t->same([], $skus('otton'));
    $t->same([], $skus('xyz'));
});
exit($t->finish());
