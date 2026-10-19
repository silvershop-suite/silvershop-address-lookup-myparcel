<?php

declare(strict_types=1);

namespace SilverShop\AddressLookup\MyParcel;

use GuzzleHttp\Client;
use SilverShop\AddressLookup\Provider\AbstractHttpProvider;
use SilverShop\AddressLookup\ValueObject\AddressQuery;
use SilverShop\AddressLookup\ValueObject\AddressValidationResult;
use SilverShop\AddressLookup\ValueObject\ResolvedAddress;
use SilverStripe\Core\Environment;

/**
 * MyParcel Address API provider — the paid satellite for {@see \SilverShop\AddressLookup\Provider\AddressLookupProvider}.
 * Host https://address.api.myparcel.nl, authenticated with `Authorization: basic base64(key)` using the SAME key
 * silvershop/shipping-myparcel already reads from the environment as `SS_MYPARCEL_API_KEY` (configurable via
 * {@see self::$api_key_env}); this module does NOT depend on shipping-myparcel's code, only on the shared key.
 *
 * Capabilities, exactly as the API honours them (verified against the OpenAPI spec and the live API):
 *  - **NL** → {@see self::MODE_POSTCODE_NUMBER}. `GET /addresses?countryCode=NL&postalCode=&houseNumber=(&houseNumberSuffix=)`
 *    resolves street + city + municipality + lat/lng from a postcode + house number. The structured query params are
 *    NL-only; the API ignores them for every other country.
 *  - **BE + every other supported country** → {@see self::MODE_AUTOCOMPLETE}. `GET /addresses?countryCode=&query=` takes
 *    a free-text term (3–200 chars) and returns candidate addresses. A BE postcode + house number alone resolves
 *    nothing upstream (the API needs a street in the free-text query), so BE is honestly AUTOCOMPLETE, not structured —
 *    its result rows still carry a Belgian `boxNumber`, which this provider maps onto {@see ResolvedAddress::$suffix}.
 *  - **{@see self::validate()}** → `GET /validate?countryCode=…` returns `{valid: bool}` for any supported country.
 *
 * Supported countries are exactly the API's ISO-3166-1 alpha-2 enum (see {@see self::SUPPORTED_COUNTRIES}); the module
 * never claims coverage the API does not advertise. Functional/own-logistics processor — {@see self::consentCategory()}
 * stays null (no consent gate), as inherited.
 *
 * @see https://address.api.myparcel.nl/openapi.yaml
 */
class MyParcelAddressProvider extends AbstractHttpProvider
{
    /**
     * API base URI. Overridable in config (e.g. to pin a mirror or a sandbox host).
     */
    private static string $endpoint = 'https://address.api.myparcel.nl';

    /**
     * Environment variable holding the MyParcel API key. Defaults to the key shipping-myparcel already uses, so a shop
     * that already ships with MyParcel needs no extra configuration.
     */
    private static string $api_key_env = 'SS_MYPARCEL_API_KEY';

    /**
     * Max rows requested. Honoured by the API for NL only (ignored for other countries, which default to 5); also caps
     * how many {@see self::suggest()} candidates come back.
     */
    private static int $result_limit = 10;

    /**
     * Optional `validationType` sent to `/validate`: 'FORMAT' validates only the shape; null (default) validates the
     * address' existence too.
     */
    private static ?string $validation_type = null;

    /**
     * Countries MyParcel resolves from a STRUCTURED postcode + house number (frontend {@see self::MODE_POSTCODE_NUMBER}).
     * Only NL — the API's postalCode/houseNumber params are explicitly NL-only.
     *
     * @var array<int, string>
     */
    private const STRUCTURED_COUNTRIES = ['NL'];

    /**
     * The API's ISO-3166-1 alpha-2 country enum (the honest coverage list). Space-separated; see the spec's
     * `Alpha2CountryCode` schema. Everything here is reachable via free-text `query`; NL additionally structurally.
     */
    private const SUPPORTED_COUNTRIES =
        'AD AE AF AG AI AL AM AO AQ AR AS AT AU AW AX AZ '
        . 'BA BB BD BE BF BG BH BI BJ BL BM BN BO BQ BR BS '
        . 'BT BV BW BY BZ CA CC CD CF CG CH CI CK CL CM CN '
        . 'CO CR CU CV CW CX CY CZ DE DJ DK DM DO DZ EC EE '
        . 'EG EH ER ES ET FI FJ FK FM FO FR GA GB GD GE GF '
        . 'GG GH GI GL GM GN GP GQ GR GS GT GU GW GY HK HM '
        . 'HN HR HT HU ID IE IL IM IN IO IQ IR IS IT JE JM '
        . 'JO JP KE KG KH KI KM KN KP KR KW KY KZ LA LB LC '
        . 'LI LK LR LS LT LU LV LY MA MC MD ME MF MG MH MK '
        . 'ML MM MN MO MP MQ MR MS MT MU MV MW MX MY MZ NA '
        . 'NC NE NF NG NI NL NO NP NR NU NZ OM PA PE PF PG '
        . 'PH PK PL PM PN PR PS PT PW PY QA RE RO RS RU RW '
        . 'SA SB SC SD SE SG SH SI SJ SK SL SM SN SO SR SS '
        . 'ST SV SX SY SZ TC TD TF TG TH TJ TK TL TM TN TO '
        . 'TR TT TV TW TZ UA UG UM US UY UZ VA VC VE VG VI '
        . 'VN VU WF WS XK YE YT ZA ZM ZW';

    /**
     * A private copy of the constructor's Guzzle options (the MockHandler test seam), because the base keeps its own
     * copy private and {@see self::httpClient()} is overridden here to add the auth header.
     *
     * @var array<string, mixed>
     */
    private array $myHttpOptions;

    private ?Client $myClient = null;

    /**
     * @param array<string, mixed> $httpOptions extra Guzzle config merged last (the MockHandler test seam)
     */
    public function __construct(array $httpOptions = [])
    {
        parent::__construct($httpOptions);
        $this->myHttpOptions = $httpOptions;
    }

    public function getKey(): string
    {
        return 'myparcel';
    }

    public function getName(): string
    {
        return 'MyParcel Address API';
    }

    /**
     * Configured once the API key is present in the environment. No key → inert (the registry skips it, the form stays
     * manual), so the module is safe to install before credentials are supplied.
     */
    public function isConfigured(): bool
    {
        return $this->apiKey() !== '';
    }

    public function supportsCountry(string $countryCode): bool
    {
        return in_array($this->normaliseCountry($countryCode), $this->supportedCountries(), true);
    }

    public function lookupMode(string $countryCode): string
    {
        $code = $this->normaliseCountry($countryCode);
        if (in_array($code, self::STRUCTURED_COUNTRIES, true)) {
            return self::MODE_POSTCODE_NUMBER;
        }

        return $this->supportsCountry($code) ? self::MODE_AUTOCOMPLETE : self::MODE_NONE;
    }

    public function lookup(AddressQuery $query): ?ResolvedAddress
    {
        $code = $this->normaliseCountry($query->countryCode);
        if (!$this->supportsCountry($code)) {
            return null;
        }

        // NL: the one country MyParcel resolves structurally (postcode + house number -> street + city).
        if ($code === 'NL' && $query->hasPostcodeNumber()) {
            return $this->structuredNl($query);
        }

        // Everyone else is free-text: use an explicit term, else best-effort compose from the structured parts (a BE
        // postcode + number alone resolves nothing upstream, so this degrades to a soft miss until a street is known).
        $term = trim($query->term);
        if ($term === '') {
            $parts = $query->postalCode . ' ' . $query->houseNumber . ' ' . $query->suffix;
            $term = trim((string) preg_replace('/\s+/', ' ', $parts));
        }
        if (strlen($term) < 3) {
            return null;
        }

        return $this->freeText($code, $term, 1)[0] ?? null;
    }

    public function suggest(string $term, string $countryCode): array
    {
        $code = $this->normaliseCountry($countryCode);
        $term = trim($term);
        if (!$this->supportsCountry($code) || strlen($term) < 3) {
            return [];
        }

        return $this->freeText($code, mb_substr($term, 0, 200), (int) static::config()->get('result_limit'));
    }

    public function validate(ResolvedAddress $address): AddressValidationResult
    {
        $code = $this->normaliseCountry($address->countryCode);
        if (!$this->supportsCountry($code)) {
            return AddressValidationResult::unknown();
        }

        $params = array_filter([
            'countryCode' => $code,
            'postalCode' => $address->postalCode,
            'city' => $address->city,
            'street' => $address->street,
            'houseNumber' => $address->houseNumber,
            'region' => $address->state,
        ], static fn (string $v): bool => $v !== '');

        if ($address->suffix !== '') {
            // BE carries the bus/box number (sent as `boxNumber`); every other country a house-number suffix.
            $params[$code === 'BE' ? 'boxNumber' : 'houseNumberSuffix'] = $address->suffix;
        }

        $type = static::config()->get('validation_type');
        if ($type !== null && (string) $type !== '') {
            $params['validationType'] = (string) $type;
        }

        $data = $this->getJson($this->base() . '/validate', $params);
        if (!array_key_exists('valid', $data)) {
            // Fault or unexpected shape -> a soft pass, so a flaky validate endpoint never blocks checkout.
            return AddressValidationResult::unknown();
        }

        $valid = (bool) $data['valid'];

        return new AddressValidationResult($valid, $valid ? 1.0 : 0.0, null);
    }

    /**
     * Structured NL lookup: postcode + house number (+ optional suffix). The API returns every sub-address under a
     * house number when no suffix is given; this picks the suffix match when one was typed, else the first row.
     */
    private function structuredNl(AddressQuery $query): ?ResolvedAddress
    {
        $params = [
            'countryCode' => 'NL',
            'postalCode' => strtoupper((string) preg_replace('/\s+/', '', $query->postalCode)),
            'houseNumber' => $query->houseNumber,
            'limit' => (int) static::config()->get('result_limit'),
        ];
        if ($query->suffix !== '') {
            $params['houseNumberSuffix'] = $query->suffix;
        }

        $rows = $this->rows($this->getJson($this->base() . '/addresses', $params));
        if ($rows === []) {
            return null;
        }

        $chosen = $rows[0];
        if ($query->suffix !== '') {
            foreach ($rows as $row) {
                if (strcasecmp((string) ($row['houseNumberSuffix'] ?? ''), $query->suffix) === 0) {
                    $chosen = $row;
                    break;
                }
            }
        }

        return $this->mapAddress($chosen, 'NL', (string) ($chosen['street'] ?? '') !== '');
    }

    /**
     * Free-text search (`query`), the only path MyParcel offers for non-NL countries. `limit` is honoured for NL only.
     *
     * @return array<int, ResolvedAddress>
     */
    private function freeText(string $code, string $term, int $limit): array
    {
        $params = ['countryCode' => $code, 'query' => $term];
        if ($code === 'NL' && $limit > 0) {
            $params['limit'] = $limit;
        }

        $out = [];
        foreach ($this->rows($this->getJson($this->base() . '/addresses', $params)) as $row) {
            $exact = (string) ($row['street'] ?? '') !== '' && (string) ($row['houseNumber'] ?? '') !== '';
            $out[] = $this->mapAddress($row, $code, $exact);
        }

        return $out;
    }

    /**
     * The `results` rows of an `/addresses` response, keeping only array rows.
     *
     * @param array<mixed> $data
     * @return array<int, array<string, mixed>>
     */
    private function rows(array $data): array
    {
        $results = $data['results'] ?? null;
        if (!is_array($results)) {
            return [];
        }

        $rows = [];
        foreach ($results as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * Map one MyParcel address row onto the normalised {@see ResolvedAddress}. BE's `boxNumber` and other countries'
     * `houseNumberSuffix` both land in {@see ResolvedAddress::$suffix} (the VO has one suffix slot). MyParcel returns
     * no state/region on address rows, so state stays empty.
     *
     * @param array<string, mixed> $row
     */
    private function mapAddress(array $row, string $fallbackCode, bool $exact): ResolvedAddress
    {
        return new ResolvedAddress(
            street: (string) ($row['street'] ?? ''),
            houseNumber: (string) ($row['houseNumber'] ?? ''),
            suffix: (string) ($row['houseNumberSuffix'] ?? $row['boxNumber'] ?? ''),
            postalCode: (string) ($row['postalCode'] ?? ''),
            city: (string) ($row['city'] ?? ''),
            municipality: (string) ($row['municipality'] ?? ''),
            state: '',
            countryCode: strtoupper((string) ($row['countryCode'] ?? $fallbackCode)),
            lat: isset($row['latitude']) ? (float) $row['latitude'] : null,
            lng: isset($row['longitude']) ? (float) $row['longitude'] : null,
            exact: $exact,
            providerRef: $this->getKey(),
        );
    }

    private function base(): string
    {
        return rtrim((string) static::config()->get('endpoint'), '/');
    }

    private function apiKey(): string
    {
        $env = (string) static::config()->get('api_key_env');

        return $env === '' ? '' : (string) Environment::getEnv($env);
    }

    private function normaliseCountry(string $code): string
    {
        return strtoupper(trim($code));
    }

    /**
     * @return array<int, string>
     */
    private function supportedCountries(): array
    {
        return explode(' ', self::SUPPORTED_COUNTRIES);
    }

    /**
     * The hardened Guzzle client, rebuilt here (rather than inherited) so the MyParcel `Authorization: basic …` header
     * is always applied. Keeps SSL verify ON and the short timeout, and still merges the config / constructor options
     * last so the MockHandler test seam works.
     */
    protected function httpClient(): Client
    {
        if ($this->myClient instanceof Client) {
            return $this->myClient;
        }

        $headers = [
            'Accept' => 'application/json',
            'User-Agent' => 'silvershop-address-lookup-myparcel/1.0 (+https://github.com/silvershop)',
        ];
        $key = $this->apiKey();
        if ($key !== '') {
            $headers['Authorization'] = 'basic ' . base64_encode($key);
        }

        $this->myClient = new Client(array_merge(
            [
                'timeout' => (int) static::config()->get('timeout'),
                'connect_timeout' => (int) static::config()->get('timeout'),
                'verify' => true,
                'http_errors' => false,
                'headers' => $headers,
            ],
            (array) static::config()->get('http_options'),
            $this->myHttpOptions,
        ));

        return $this->myClient;
    }
}
