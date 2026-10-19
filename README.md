# silvershop/address-lookup-myparcel

A **satellite provider** for [`silvershop/address-lookup`](https://github.com/silvershop/silvershop-address-lookup)
(CMS 6) that resolves addresses through the **[MyParcel Address API](https://address.api.myparcel.nl)**.

It registers one provider (`myparcel`) in the base module's per-country registry and — out of the box — **closes the
Belgium gap**: the base maps `BE` to PDOK, but PDOK is NL-only, so Belgian checkouts fell back to the plain manual
form. MyParcel resolves Belgium, so this module routes `BE → myparcel`.

## Capabilities

| Country | Mode | Endpoint | Resolves |
| --- | --- | --- | --- |
| **NL** | `postcode_number` | `GET /addresses?countryCode=NL&postalCode=&houseNumber=` | **street + city + municipality** (+ lat/lng) |
| **BE** + every other [supported country](https://address.api.myparcel.nl/openapi.yaml) | `autocomplete` | `GET /addresses?countryCode=&query=` | candidate addresses from a free-text term (BE rows include the `boxNumber`) |
| any supported country | validation | `GET /validate?countryCode=…` | `{valid: bool}` |

> **Why is BE `autocomplete`, not `postcode_number`?** The MyParcel `/addresses` structured params
> (`postalCode` / `houseNumber`) are **NL-only** — for every other country the API ignores them and requires a
> free-text `query` that contains a street. A Belgian postcode + house number alone resolves nothing upstream, so the
> honest, working mode for BE is free-text autocomplete. (The base checkout bootstrap currently drives only the
> `lookup` endpoint; a free-text suggest UI for `autocomplete` countries is a base-module concern.)

## Installation

```bash
composer require silvershop/address-lookup-myparcel
```

Then run `dev/build?flush=all`.

### API key

The provider authenticates with `Authorization: basic base64(key)` and reads the key from the environment variable
**`SS_MYPARCEL_API_KEY`** — the **same key** [`silvershop/shipping-myparcel`](https://github.com/silvershop) already
uses. A shop that already ships with MyParcel needs **no extra configuration**: add the module, flush, done. (This
module does not depend on shipping-myparcel's code — only on the shared key.)

```dotenv
# .env
SS_MYPARCEL_API_KEY="your-myparcel-api-key"
```

Without a key the provider is **inert**: the registry skips it and the checkout form stays manual, so the module is
safe to install before credentials are supplied.

## Configuration

Registration and the BE mapping are applied automatically by `_config/address-lookup-myparcel.yml`:

```yaml
SilverShop\AddressLookup\Provider\AddressLookupProviderRegistry:
  providers:
    myparcel: '%$SilverShop\AddressLookup\MyParcel\MyParcelAddressProvider'
  by_country:
    BE: myparcel
```

**NL is deliberately left on the free government PDOK provider.** To use MyParcel for NL as well, remap it in your
project config:

```yaml
SilverShop\AddressLookup\Provider\AddressLookupProviderRegistry:
  by_country:
    NL: myparcel
```

You can route any other [supported country](https://address.api.myparcel.nl/openapi.yaml) to MyParcel the same way
(it will use free-text autocomplete).

### Provider options

```yaml
SilverShop\AddressLookup\MyParcel\MyParcelAddressProvider:
  endpoint: 'https://address.api.myparcel.nl'   # override to pin a mirror / sandbox host
  api_key_env: 'SS_MYPARCEL_API_KEY'            # the env var holding the key
  result_limit: 10                              # max rows (NL only; others default to 5 upstream)
  validation_type: ~                            # 'FORMAT' = validate shape only; null = also validate existence
  timeout: 5                                    # upstream timeout, seconds (inherited from the HTTP base)
```

## Consent

MyParcel is the shop's **own-logistics processor** used only to fulfil the order, so the provider is
**functional/necessary** and declares **no consent category** — it runs without a cookie gate (same posture as the
base module's bundled providers).

## Hardening

Inherited from the base module's `AbstractHttpProvider`: **SSL verify ON**, a short upstream timeout, and a graceful
empty answer on any miss or fault, so a slow or failing address API never blocks checkout. The lookup endpoint itself
(POST-only, same-origin + CSRF, rate-limited) lives in the base module.

## Requirements

- PHP ^8.3
- silverstripe/framework ^6
- silvershop/core ^6
- silvershop/address-lookup ^1
- A MyParcel API key (`SS_MYPARCEL_API_KEY`)

## License

BSD-3-Clause.
