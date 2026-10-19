<?php

declare(strict_types=1);

namespace SilverShop\AddressLookup\MyParcel\Tests;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use SilverShop\AddressLookup\MyParcel\MyParcelAddressProvider;
use SilverShop\AddressLookup\Provider\AddressLookupProvider;
use SilverShop\AddressLookup\ValueObject\AddressQuery;
use SilverShop\AddressLookup\ValueObject\ResolvedAddress;
use SilverStripe\Core\Environment;
use SilverStripe\Dev\SapphireTest;

/**
 * Drives the real {@link MyParcelAddressProvider} against canned responses with a mocked Guzzle handler (no live
 * calls): the `Authorization: basic base64(key)` header, the NL structured `/addresses` shape + mapping, the BE/free-
 * text `query` shape + boxNumber mapping, suggest, `/validate`, capability flags, and soft-fail on a fault.
 */
class MyParcelAddressProviderTest extends SapphireTest
{
    protected $usesDatabase = false;

    private const TEST_KEY = 'test-key-123';

    private const NL_BODY = '{"results":[{"city":"Hoofddorp","postOfficeBox":false,"street":"Antareslaan",'
        . '"houseNumber":"31","latitude":52.29496015,"longitude":4.71072848,"municipality":"Haarlemmermeer",'
        . '"countryCode":"NL","postalCode":"2132JE"}]}';

    private const BE_BODY = '{"results":[{"city":"Brussel","postOfficeBox":false,"street":"Grote Markt",'
        . '"houseNumber":"1","boxNumber":"B","municipality":"Brussel","countryCode":"BE","postalCode":"1000"}]}';

    /**
     * @var string|false
     */
    private $originalKey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalKey = Environment::getEnv('SS_MYPARCEL_API_KEY');
        Environment::setEnv('SS_MYPARCEL_API_KEY', self::TEST_KEY);
    }

    protected function tearDown(): void
    {
        Environment::setEnv('SS_MYPARCEL_API_KEY', $this->originalKey === false ? '' : $this->originalKey);
        parent::tearDown();
    }

    /**
     * @param array<int, Response> $responses
     * @param array<int, mixed> $transactions
     */
    private function provider(array $responses, array &$transactions = []): MyParcelAddressProvider
    {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($transactions));

        return new MyParcelAddressProvider(['handler' => $stack]);
    }

    public function testAuthorizationHeaderIsBasicBase64(): void
    {
        $transactions = [];
        $provider = $this->provider([new Response(200, [], self::NL_BODY)], $transactions);

        $provider->lookup(new AddressQuery('NL', '2132JE', '31'));

        /** @var RequestInterface $request */
        $request = $transactions[0]['request'];
        $this->assertSame('basic ' . base64_encode(self::TEST_KEY), $request->getHeaderLine('Authorization'));
        $this->assertSame('application/json', $request->getHeaderLine('Accept'));
    }

    public function testNlStructuredLookupMapsResultAndRequestShape(): void
    {
        $transactions = [];
        $provider = $this->provider([new Response(200, [], self::NL_BODY)], $transactions);

        $address = $provider->lookup(new AddressQuery('NL', '2132 JE', '31'));

        $this->assertNotNull($address);
        $this->assertSame('Antareslaan', $address->street);
        $this->assertSame('31', $address->houseNumber);
        $this->assertSame('', $address->suffix);
        $this->assertSame('Hoofddorp', $address->city);
        $this->assertSame('Haarlemmermeer', $address->municipality);
        $this->assertSame('2132JE', $address->postalCode);
        $this->assertSame('NL', $address->countryCode);
        $this->assertSame(52.29496015, $address->lat);
        $this->assertSame(4.71072848, $address->lng);
        $this->assertTrue($address->exact);
        $this->assertSame('myparcel', $address->providerRef);

        /** @var RequestInterface $request */
        $request = $transactions[0]['request'];
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('address.api.myparcel.nl', $request->getUri()->getHost());
        $this->assertSame('/addresses', $request->getUri()->getPath());
        parse_str($request->getUri()->getQuery(), $q);
        $this->assertSame('NL', $q['countryCode'] ?? null);
        $this->assertSame('2132JE', $q['postalCode'] ?? null, 'Postcode whitespace stripped');
        $this->assertSame('31', $q['houseNumber'] ?? null);
        $this->assertArrayHasKey('limit', $q, 'limit is sent for NL');
        $this->assertArrayNotHasKey('query', $q, 'NL uses structured params, not free-text query');
    }

    public function testNlLookupPrefersSuffixMatch(): void
    {
        $body = '{"results":[{"city":"Amsterdam","postOfficeBox":false,"street":"Dam","houseNumber":"1",'
            . '"houseNumberSuffix":"A","countryCode":"NL","postalCode":"1012JS"},'
            . '{"city":"Amsterdam","postOfficeBox":false,"street":"Dam","houseNumber":"1",'
            . '"houseNumberSuffix":"B","countryCode":"NL","postalCode":"1012JS"}]}';
        $transactions = [];
        $provider = $this->provider([new Response(200, [], $body)], $transactions);

        $address = $provider->lookup(new AddressQuery('NL', '1012JS', '1', 'B'));

        $this->assertNotNull($address);
        $this->assertSame('B', $address->suffix, 'Picks the row matching the typed suffix');

        parse_str($transactions[0]['request']->getUri()->getQuery(), $q);
        $this->assertSame('B', $q['houseNumberSuffix'] ?? null);
    }

    public function testBeFreeTextLookupViaQueryMapsBoxNumber(): void
    {
        $transactions = [];
        $provider = $this->provider([new Response(200, [], self::BE_BODY)], $transactions);

        $address = $provider->lookup(new AddressQuery('BE', '', '', '', 'Grote Markt 1 1000 Brussel'));

        $this->assertNotNull($address);
        $this->assertSame('Grote Markt', $address->street);
        $this->assertSame('1', $address->houseNumber);
        $this->assertSame('B', $address->suffix, 'BE boxNumber is mapped onto suffix');
        $this->assertSame('Brussel', $address->city);
        $this->assertSame('1000', $address->postalCode);
        $this->assertSame('BE', $address->countryCode);
        $this->assertTrue($address->exact);
        $this->assertSame('myparcel', $address->providerRef);

        parse_str($transactions[0]['request']->getUri()->getQuery(), $q);
        $this->assertSame('BE', $q['countryCode'] ?? null);
        $this->assertSame('Grote Markt 1 1000 Brussel', $q['query'] ?? null);
        $this->assertArrayNotHasKey('postalCode', $q, 'BE goes through free-text query, not structured params');
    }

    public function testBeComposesQueryFromStructuredPartsWhenNoTerm(): void
    {
        $transactions = [];
        $provider = $this->provider([new Response(200, [], '{"results":[]}')], $transactions);

        // No free-text term: the provider composes one from postcode + house number. BE postcode+number resolves
        // nothing upstream, so this is a graceful soft miss.
        $address = $provider->lookup(new AddressQuery('BE', '1000', '12'));

        $this->assertNull($address);
        parse_str($transactions[0]['request']->getUri()->getQuery(), $q);
        $this->assertSame('1000 12', $q['query'] ?? null, 'Composed free-text query from the structured parts');
    }

    public function testSuggestReturnsMappedCandidates(): void
    {
        $body = '{"results":['
            . '{"city":"New York","postOfficeBox":false,"street":"5th Ave","houseNumber":"767",'
            . '"countryCode":"US","postalCode":"10153"},'
            . '{"city":"Troy","postOfficeBox":false,"street":"5th Ave","houseNumber":"767",'
            . '"countryCode":"US","postalCode":"12182"}]}';
        $transactions = [];
        $provider = $this->provider([new Response(200, [], $body)], $transactions);

        $results = $provider->suggest('767 5th Ave New York', 'US');

        $this->assertCount(2, $results);
        $this->assertInstanceOf(ResolvedAddress::class, $results[0]);
        $this->assertSame('5th Ave', $results[0]->street);
        $this->assertSame('New York', $results[0]->city);
        $this->assertSame('US', $results[0]->countryCode);
        $this->assertSame('myparcel', $results[0]->providerRef);

        parse_str($transactions[0]['request']->getUri()->getQuery(), $q);
        $this->assertSame('US', $q['countryCode'] ?? null);
        $this->assertSame('767 5th Ave New York', $q['query'] ?? null);
        $this->assertArrayNotHasKey('limit', $q, 'limit is NL-only, omitted for other countries');
    }

    public function testSuggestRejectsShortTermWithoutRequest(): void
    {
        $transactions = [];
        $provider = $this->provider([new Response(200, [], '{"results":[]}')], $transactions);

        $this->assertSame([], $provider->suggest('ab', 'US'));
        $this->assertCount(0, $transactions, 'A sub-3-char term makes no HTTP call');
    }

    public function testSuggestUnsupportedCountryMakesNoRequest(): void
    {
        $transactions = [];
        $provider = $this->provider([new Response(200, [], '{"results":[]}')], $transactions);

        $this->assertSame([], $provider->suggest('anything here', 'ZZ'));
        $this->assertCount(0, $transactions);
    }

    public function testValidateMapsValidTrueAndRequestShape(): void
    {
        $transactions = [];
        $provider = $this->provider([new Response(200, [], '{"valid":true}')], $transactions);

        $result = $provider->validate(new ResolvedAddress(
            street: 'Antareslaan',
            houseNumber: '31',
            postalCode: '2132JE',
            city: 'Hoofddorp',
            countryCode: 'NL',
        ));

        $this->assertTrue($result->valid);
        $this->assertSame(1.0, $result->score);
        $this->assertNull($result->canonical);

        /** @var RequestInterface $request */
        $request = $transactions[0]['request'];
        $this->assertSame('/validate', $request->getUri()->getPath());
        parse_str($request->getUri()->getQuery(), $q);
        $this->assertSame('NL', $q['countryCode'] ?? null);
        $this->assertSame('2132JE', $q['postalCode'] ?? null);
        $this->assertSame('Hoofddorp', $q['city'] ?? null);
        $this->assertSame('Antareslaan', $q['street'] ?? null);
        $this->assertSame('31', $q['houseNumber'] ?? null);
    }

    public function testValidateMapsValidFalse(): void
    {
        $provider = $this->provider([new Response(200, [], '{"valid":false}')]);

        $result = $provider->validate(new ResolvedAddress(postalCode: '0000AA', countryCode: 'NL'));

        $this->assertFalse($result->valid);
        $this->assertSame(0.0, $result->score);
    }

    public function testValidateBeUsesBoxNumber(): void
    {
        $transactions = [];
        $provider = $this->provider([new Response(200, [], '{"valid":true}')], $transactions);

        $provider->validate(new ResolvedAddress(
            street: 'Grote Markt',
            houseNumber: '1',
            suffix: 'B',
            postalCode: '1000',
            city: 'Brussel',
            countryCode: 'BE',
        ));

        parse_str($transactions[0]['request']->getUri()->getQuery(), $q);
        $this->assertSame('B', $q['boxNumber'] ?? null, 'BE suffix is sent as boxNumber');
        $this->assertArrayNotHasKey('houseNumberSuffix', $q);
    }

    public function testValidateSoftPassesOnFault(): void
    {
        $provider = $this->provider([new Response(500, [], 'upstream error')]);

        $result = $provider->validate(new ResolvedAddress(postalCode: '2132JE', countryCode: 'NL'));

        $this->assertTrue($result->valid, 'A validate fault is a soft pass so checkout stays usable');
        $this->assertSame(0.0, $result->score);
    }

    public function testValidateUnsupportedCountryMakesNoRequest(): void
    {
        $transactions = [];
        $provider = $this->provider([new Response(200, [], '{"valid":true}')], $transactions);

        $result = $provider->validate(new ResolvedAddress(countryCode: 'ZZ'));

        $this->assertTrue($result->valid);
        $this->assertCount(0, $transactions);
    }

    public function testSupportsCountryAndLookupMode(): void
    {
        $provider = $this->provider([new Response(200, [], '{"results":[]}')]);

        $this->assertTrue($provider->supportsCountry('NL'));
        $this->assertTrue($provider->supportsCountry('BE'));
        $this->assertTrue($provider->supportsCountry('US'));
        $this->assertTrue($provider->supportsCountry('fr'), 'Case-insensitive');
        $this->assertFalse($provider->supportsCountry('ZZ'), 'Not in the API country enum');
        $this->assertFalse($provider->supportsCountry('USA'));
        $this->assertFalse($provider->supportsCountry(''));

        $this->assertSame(AddressLookupProvider::MODE_POSTCODE_NUMBER, $provider->lookupMode('NL'));
        $this->assertSame(AddressLookupProvider::MODE_AUTOCOMPLETE, $provider->lookupMode('BE'));
        $this->assertSame(AddressLookupProvider::MODE_AUTOCOMPLETE, $provider->lookupMode('US'));
        $this->assertSame(AddressLookupProvider::MODE_NONE, $provider->lookupMode('ZZ'));

        $this->assertNull($provider->consentCategory(), 'Functional provider, no consent gate');
    }

    public function testUnsupportedCountryLookupMakesNoRequest(): void
    {
        $transactions = [];
        $provider = $this->provider([new Response(200, [], self::NL_BODY)], $transactions);

        $this->assertNull($provider->lookup(new AddressQuery('ZZ', '1000', '1')));
        $this->assertCount(0, $transactions);
    }

    public function testNlLookupSoftMissOnFault(): void
    {
        $provider = $this->provider([new Response(500, [], 'boom')]);

        $this->assertNull($provider->lookup(new AddressQuery('NL', '2132JE', '31')));
    }

    public function testIsConfiguredReflectsKeyPresence(): void
    {
        $provider = $this->provider([new Response(200, [], '{"results":[]}')]);
        $this->assertTrue($provider->isConfigured());

        Environment::setEnv('SS_MYPARCEL_API_KEY', '');
        $this->assertFalse($provider->isConfigured(), 'No key -> inert');
    }
}
