# omnilab/vizier

VizieR, the CDS's library of published astronomical catalogues (Strasbourg), for
[omnisense/omnilab](https://github.com/glitchr-omnisense/omnilab): its tables by text, as datasets -
each a `Product` whose id is the table's name (`I/239/hip_main`), with its catalogue, title,
authors and number of rows -, and, outside the contract, the rows of a table around a point of
the sky or an object, each column with its unit and description. Through TAPVizieR, in ADQL. No
key.

```php
$vizier = (new VizierSourceFactory($http))->create(['mailto' => 'support@glitchr.io']);
$tables = $vizier->datasets(new Query('hipparcos', limit: 5));   // I/337/tgas, ..., I/239/hip_main (118,218 rows)
$rows = $vizier->rows('I/239/hip_main', 101.28715533, -16.71611586, 5 / 60);
$rows[0]->value('HIP');                        // 32349 (Sirius)
$rows[0]->value('Vmag');                       // -1.44 mag
$rows[0]->separation;                          // 11.72 (arcsec)
$vizier->rowsOf('I/355/gaiadr3', $simbad->entity("Barnard's star"), 200 / 3600, 1000);
```

```yaml
omnilab:
    sources:
        vizier:
            factory: vizier
            options:
                mailto: 'support@glitchr.io'   # in the User-Agent
```

See [docs/](docs/index.md) for what is read, how it maps, and what VizieR does not give here.

## Install and a first call

```sh
composer require omnilab/vizier
```

A real call, with no account and no key: the VizieR tables a text names. `mailto` is your address,
sent in the User-Agent so that the service can reach you before it blocks a caller; the source keeps
to the limits its [`policy.json`](policy.json) declares.

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnilab\Source\Query;
use Omnilab\Vizier\VizierSourceFactory;

$vizier = (new VizierSourceFactory())->create(['mailto' => 'you@example.org']);   // your address, in the User-Agent
foreach ($vizier->datasets(new Query('hipparcos', limit: 3)) as $table) {
    echo $table->id, "\n";
}
```

Not run on 2026-11-13: the CDS's services (SIMBAD, VizieR) answered their maintenance page (404) that day.

## Terms of use

VizieR is a service of the CDS (Centre de Données astronomiques de Strasbourg, Université de
Strasbourg / CNRS). The CDS asks that work using it acknowledge it: "This research has made use of
the VizieR catalogue access tool, CDS, Strasbourg, France (DOI: 10.26093/cds/vizier). The
original description of the VizieR service was published in Ochsenbein et al. 2000, A&AS 143, 23."
Each catalogue is its authors' work, to be cited from its own reference (its VizieR page gives
it). No rate is published for TAP; each call here took 1 to 6 s.

## Usage policy

Declared as data in [`policy.json`](policy.json) (its pages could not be read on 2026-11-10 (https://cds.unistra.fr/vizier-org/licences_vizier.html): see the notes in the file), and honoured on every request by the
core: a limit reached waits when its window ends within `max_wait` seconds, else the request is not
sent ([Usage policies](https://github.com/glitchr-omnisense/omnilab/blob/1.x/docs/policies.md)).

- Limits: not published (unknown).
- Licence: not stated; commercial use: unknown.
- Credit: not required: This research has made use of the VizieR catalogue access tool, CDS, Strasbourg Astronomical Observatory, France (DOI: 10.26093/cds/vizier) (https://vizier.cds.unistra.fr/).
- Needs: nothing.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by the CDS, the Université de Strasbourg or the CNRS. It reaches their service on behalf of whoever runs it, and it does not
redistribute their data beyond the short excerpts recorded as test fixtures. Its MIT licence
covers this package's code only and grants no right to the service or to its data.

Whoever installs and runs this package is responsible for complying with the terms above, for
obtaining whatever they require (an account, a key, a licence or subscription, an attribution or
a citation, a rate limit to respect), and for any use made of what it returns. The authors
publish it free of charge, make no commercial use of it, and accept no liability for how it is
used (see [LICENSE](LICENSE)).

License: MIT.
