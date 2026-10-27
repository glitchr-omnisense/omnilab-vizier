# omnilab/vizier

VizieR, the CDS's library of published astronomical catalogues, through TAPVizieR
(`https://tapvizier.cds.unistra.fr/TAPVizieR/tap/`, ADQL) for
[omnisense/omnilab](https://github.com/glitchr-omnisense/omnilab): the catalogues' tables by text
as datasets; outside the contract, the rows of a table around a point of the sky or an object,
with their units. No key.

## Installation

```sh
composer require omnilab/vizier
```

```php
use Omnilab\Vizier\VizierSourceFactory;

$vizier = (new VizierSourceFactory($httpClient))->create([
    'mailto' => 'support@glitchr.io',  // optional, in the User-Agent
    'throttle' => 0,                   // optional: seconds between two calls
]);
```

With the Symfony bundle, the factory is registered as `vizier` when the bundle knows it; otherwise
register `VizierSourceFactory` as a service (it is autoconfigured as a `SourceFactoryInterface`).

## Credentials

None: TAPVizieR is open, no key, no account.

## Options

The factory's options, with the defaults it applies (`VizierSourceFactory`):

| Option | Type | Default | Required | Effect |
|---|---|---|---|---|
| `mailto` | `?string` | null | no | a contact address, sent in the User-Agent |
| `user_agent` | `?string` | null | no | the whole User-Agent, in place of the one made from `mailto` |
| `throttle` | `float` | `0` | no | seconds left between two calls: the CDS publishes no rate for TAP (each call took 1 to 6 s) |
| `base_uri` | `string` | `https://tapvizier.cds.unistra.fr/TAPVizieR/tap/` | no | the TAP service (`sync` is joined to it): TAPVizieR, or a mirror |

## Capabilities

`capabilities()` answers `datasets`; an operation it does not answer throws `NotSupportedException` before any call.

| Capability | Supported | |
|---|---|---|
| `entity` | no | a row of a catalogue is no entity: `rows()` and `rowsOf()`, outside the contract |
| `search` | no |  |
| `series` | no |  |
| `products` | no | a table is a dataset (`datasets()`), not a product over a place on the Earth |
| `occurrences` | no |  |
| `datasets` | yes | VizieR's tables whose description holds every word of the text (each word in four cases), the largest first, 1,000 at most, one page (no `OFFSET` in its ADQL) |
| `jobs` | no |  |
| `open` | no |  |
| `structure` | no |  |
| `cross_references` | no |  |
| `forecast` | no |  |
| `historical` | no |  |
| `area` | no | the core's places are on the Earth: a cone on the sky is `rows()`'s |
| `station` | no |  |
| `cloud_cover` | no |  |

Outside the contract, on `Omnilab\Vizier\VizierSource`: `rows(string $table, float $ra, float
$dec, float $radius = 5 / 3600, int $limit = 50): list<Row>` (the rows of a table around a point
of the sky, in degrees, the nearest first; 10° and 1,000 rows at most) and `rowsOf(string $table,
Entity $object, float $radius = 5 / 3600, int $limit = 50): list<Row>` (around an entity with
`ra` and `dec` properties in degrees: omnilab/simbad's, ned's, gaia's). A `Row` holds `table`,
`separation` (arcseconds) and `values` (a `Property` by column), and `value($column)` gives one
value.

## Which API

TAPVizieR (`https://tapvizier.cds.unistra.fr/TAPVizieR/tap/sync`, ADQL, `FORMAT=json`): every
VizieR table is a TAP table named by its VizieR name in double quotes (`"I/239/hip_main"`), and
`TAP_SCHEMA.tables` / `TAP_SCHEMA.columns` describe them (description with the authors, number of
rows; each column's unit, UCD and description). VizieR's ADQL has **no `OFFSET`, no `ILIKE`, no
`LOWER()`** (each a 400, "Encountered ..."), which shapes what follows.

| Method | Reads | ADQL |
|---|---|---|
| `datasets()` | `Query::$text`, each word in the description (as written, lower case, capitalised, upper case), `limit` (1,000 at most) | `SELECT TOP n+1 table_name, description, nrows FROM TAP_SCHEMA.tables WHERE (description LIKE '%w%' OR ...) AND ... ORDER BY nrows DESC, table_name ASC` |
| `rows()` | outside the contract: a table's name, a right ascension and declination (degrees), a radius (degrees, 5" by default, 10° at most), `limit` (50; 1,000 at most) | its main position columns from `TAP_SCHEMA.columns` (UCD `pos.eq.ra;meta.main`, `pos.eq.dec;meta.main`), then `SELECT TOP n *, DISTANCE(...) AS "_separation" FROM "table" WHERE 1 = CONTAINS(...) ORDER BY "_separation" ASC` |
| `rowsOf()` | outside the contract: a table and an `Entity` with `ra` and `dec` properties in degrees (omnilab/simbad's, ned's, gaia's) | as `rows()` |

**Datasets**: a `Product` per table - `id` the table (`I/239/hip_main`), `collection` its
catalogue (`I/239`), `properties` `title`, `authors` (the description's bracketed end) and
`rows`, `url` its VizieR page; no footprint, period nor asset. VizieR ranks nothing: the largest
tables first. One page: `next` is null, `total` known when fewer than the limit matched.

**Rows** (`Row`): `table`, `separation` from the point (arcsec), `values` by column, each a
`Property` (key the column's name, label VizieR's description, value a `Quantity` when the column
has a unit and the value is a number; a blank cell is no value). Why outside the contract: the
core's places are on the Earth, and a row of a catalogue is no entity with an identifier of its
own. A table without main position columns (or unknown) is a `ProviderException` saying so. A
table's name is checked before any call (letters, digits, `+_./-`).

**Epochs**: a table's positions are at its own epoch. Barnard's star moves 10.4" a year: SIMBAD's
J2000 position is 166" from Gaia DR3's (epoch J2016.0) - with the default 5", `rowsOf()` finds
other stars; ask a radius that covers the motion.

## What VizieR does not give here

The catalogues' ReadMe and full metadata, the ASU and cone-search interfaces, cross-matching
(X-Match), the TAP tables of other services, the asynchronous queries, and pages past the first
(no `OFFSET`).

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnilab\Source\Query;
use Omnilab\Vizier\VizierSourceFactory;
use Symfony\Component\HttpClient\HttpClient;

$vizier = (new VizierSourceFactory(HttpClient::create()))->create(['mailto' => 'you@example.org']);

foreach ($vizier->datasets(new Query('hipparcos', limit: 3)) as $table) {
    printf("%s  %s rows  %s\n", $table->id, $table->properties['rows'] ?? '?', $table->properties['title'] ?? '');
}

// Sirius, at its J2000 position (degrees), within 30 arcseconds.
foreach ($vizier->rows('I/239/hip_main', 101.287155, -16.716116, 30 / 3600, 3) as $row) {
    printf("HIP %s at %.2f\": Vmag %s\n", $row->values['HIP']->value ?? '?', $row->separation, $row->values['Vmag']->value ?? '?');
}
```

```
I/337/tgas  2057050 rows  TGAS: Subset of GaiaSource comprising those stars in the Hipparcos and Tycho-2 Catalogues for which a full 5-parameter astrometric solution has been possible in Gaia Data Release (TgasSource)
J/A+A/657/A7/tablea2  175155 rows  CPM candidate companions for Hipparcos stars
I/239/hip_main  118218 rows  The Hipparcos Main Catalogue
HIP 32349 at 11.72": Vmag -1.44 mag
```

(as answered on 2026-10-27; `Vmag` is a `Quantity`, its unit `mag`)

```yaml
# config/packages/omnilab.yaml
omnilab:
    sources:
        vizier: { factory: vizier, options: { mailto: '%env(OMNILAB_MAILTO)%' } }
```

The bundle injects it as `SourceInterface $vizier`; `rows()` and `rowsOf()` are methods of
`VizierSource`, not of the interface: check the instance before calling them.

## Errors

Every call goes through `HttpSource`: a 404 or a 410 (and a 204) is null - an empty page for a list -; a 429 is a `RateLimitedException` (`retryAfter` from `Retry-After`); a 5xx, a time-out or a host that does not answer an `UnavailableException`; any other 4xx a `ProviderException` with its `status` and what the service said; an answer that is not JSON a `ProviderException`, a web page answered for JSON an `UnavailableException`.

TAP's errors (a VOTable whose `QUERY_STATUS` is `ERROR`, with a 400) are read into the
`ProviderException`'s message (`Encountered ...` for ADQL VizieR does not take). Before any call:
a table's name that is not VizieR's form (letters, digits, `+_./-`), a position outside the sky, a
radius not above 0 or beyond 10°, an entity without `ra` and `dec` (`\InvalidArgumentException`).
A table without main position columns, or unknown, is a `ProviderException` that says so.

## Verified, and not

Against the real service on 2026-10-16: the tables naming Hipparcos (TGAS first, I/239/hip_main
third, 5.1 s), "Gaia DR3 source" (I/350/gaiaedr3, I/355/gaiadr3...), a text no table holds;
I/239/hip_main around Sirius (HIP 32349, V -1.44, 11.72" from the J2000 position, 0.8 s);
I/355/gaiadr3 around SIMBAD's Barnard's star (within 30": three other sources; within 200":
Gaia DR3 4472832130942575872 at 166.3"); `ILIKE`, `LOWER()` and `OFFSET` refused (400). The
fixtures in `Tests/Fixtures/` are its answers.

Again on 2026-10-27: the tables naming Hipparcos (the same three first) and Sirius in
I/239/hip_main at 11.72" (the example above).

Not verified: a 429 or a 5xx (none met); a table whose main positions are not in degrees; a
mirror (`base_uri`).

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by the CDS (Université de Strasbourg / CNRS). The CDS asks that work using VizieR
acknowledge it ("This research has made use of the VizieR catalogue access tool, CDS, Strasbourg,
France (DOI: 10.26093/cds/vizier)"), and each catalogue is its authors' work, to be cited from its
own reference, as the README's [Terms of use](https://github.com/glitchr-omnisense/omnilab-vizier#terms-of-use)
says. Whoever runs the package is responsible for complying with them and for any use made of
what it returns: see the README's
[Responsibility](https://github.com/glitchr-omnisense/omnilab-vizier#responsibility) section.
