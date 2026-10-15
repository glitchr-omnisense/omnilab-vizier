# omnilab/vizier

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

## Verified, and not

Against the real service on 2026-10-16: the tables naming Hipparcos (TGAS first, I/239/hip_main
third, 5.1 s), "Gaia DR3 source" (I/350/gaiaedr3, I/355/gaiadr3...), a text no table holds;
I/239/hip_main around Sirius (HIP 32349, V -1.44, 11.72" from the J2000 position, 0.8 s);
I/355/gaiadr3 around SIMBAD's Barnard's star (within 30": three other sources; within 200":
Gaia DR3 4472832130942575872 at 166.3"); `ILIKE`, `LOWER()` and `OFFSET` refused (400). The
fixtures in `Tests/Fixtures/` are its answers.

Not verified: a 429 or a 5xx (none met); a table whose main positions are not in degrees; a
mirror (`base_uri`).
