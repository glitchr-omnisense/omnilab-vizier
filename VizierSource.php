<?php

namespace Omnilab\Vizier;

use Omnilab\Exception\ProviderException;
use Omnilab\Model\Entity;
use Omnilab\Model\Product;
use Omnilab\Model\Property;
use Omnilab\Model\Quantity;
use Omnilab\Source\Capability;
use Omnilab\Source\HttpSource;
use Omnilab\Source\Page;
use Omnilab\Source\Query;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * VizieR, the CDS's library of published astronomical catalogues
 * (Strasbourg), through its TAP service: the tables whose description holds
 * a text, as datasets (datasets(): a Product per table, its id the table's
 * name, I/239/hip_main), and, outside the contract, the rows of a table
 * around a point of the sky or an object (rows(), rowsOf()).
 *
 * No key. VizieR's ADQL has no OFFSET, no ILIKE and no LOWER: a search is
 * one page, and asks each word as written, lower case, capitalised and
 * upper case.
 */
final class VizierSource extends HttpSource
{
    public const BASE_URI = 'https://tapvizier.cds.unistra.fr/TAPVizieR/tap/';

    /** The most tables a search gives, the most rows a cone. */
    public const MAX_LIMIT = 1000;

    public function __construct(
        HttpClientInterface $http,
        string $baseUri = self::BASE_URI,
        array $headers = [],
        float $throttle = 0.0,
    ) {
        parent::__construct($http, $baseUri, $headers, $throttle);
    }

    public function getName(): string
    {
        return 'vizier';
    }

    public function capabilities(): array
    {
        return [Capability::DATASETS];
    }

    /**
     * The tables whose description holds every word of the text, the
     * largest first (VizieR ranks nothing), one page: Page::$next is
     * always null, and Page::$total known when fewer than the limit were
     * found. Each a Product: its id the table's name (I/239/hip_main), its
     * collection the catalogue's (I/239), its title and authors, its rows.
     *
     * @return Page<Product>
     */
    public function datasets(Query $query): Page
    {
        $words = array_values(array_filter(preg_split('~\s+~u', trim((string) preg_replace("~[%_'\"]~u", ' ', $query->text))) ?: [], static fn (string $w) => '' !== $w));
        if (!$words) {
            return new Page();
        }
        $limit = max(1, min(self::MAX_LIMIT, $query->limit));
        $where = [];
        foreach ($words as $word) {
            $cases = array_values(array_unique([$word, mb_strtolower($word), mb_convert_case($word, \MB_CASE_TITLE), mb_strtoupper($word)]));
            $where[] = '('.implode(' OR ', array_map(static fn (string $w) => "description LIKE '%".$w."%'", $cases)).')';
        }
        $rows = $this->query('SELECT TOP '.($limit + 1).' table_name, description, nrows FROM TAP_SCHEMA.tables WHERE '.implode(' AND ', $where).' ORDER BY nrows DESC, table_name ASC');
        $more = \count($rows) > $limit;
        $items = array_map(fn (array $row) => $this->toDataset($row), \array_slice($rows, 0, $limit));

        return new Page($items, null, $more ? null : \count($items));
    }

    /**
     * The rows of a table within a radius of a point of the sky, the
     * nearest first, by the table's main position columns (its
     * pos.eq.ra;meta.main and pos.eq.dec;meta.main: one call to read them,
     * one for the rows). Outside the contract: the core's places are on
     * the Earth, and a row is no entity of its own.
     *
     * @param string $table  VizieR's name: I/239/hip_main, I/355/gaiadr3
     * @param float  $ra     degrees (ICRS / J2000)
     * @param float  $dec    degrees
     * @param float  $radius degrees (5 arcseconds by default)
     *
     * @return list<Row>
     */
    public function rows(string $table, float $ra, float $dec, float $radius = 5 / 3600, int $limit = 50): array
    {
        $table = self::table($table);
        if ($ra < 0 || $ra >= 360 || $dec < -90 || $dec > 90) {
            throw new \InvalidArgumentException(\sprintf('%F, %F is not a right ascension and a declination in degrees.', $ra, $dec));
        }
        if ($radius <= 0 || $radius > 10) {
            throw new \InvalidArgumentException(\sprintf('A radius of %F degrees.', $radius));
        }
        $position = [];
        foreach ($this->query("SELECT column_name, unit, ucd FROM TAP_SCHEMA.columns WHERE table_name = '\"".$table."\"' AND ucd LIKE 'pos.eq.%;meta.main'") as $column) {
            $position[str_starts_with((string) $column['ucd'], 'pos.eq.ra') ? 'ra' : 'dec'] = trim((string) $column['column_name'], '"');
        }
        if (!isset($position['ra'], $position['dec'])) {
            throw new ProviderException('vizier', \sprintf('the table %s has no main position (pos.eq.ra;meta.main): unknown, or not a table of positions', $table));
        }
        $columns = \sprintf("POINT('ICRS', \"%s\", \"%s\")", $position['ra'], $position['dec']);
        $rows = $this->query('SELECT TOP '.max(1, min(self::MAX_LIMIT, $limit)).' *, DISTANCE('.$columns.", POINT('ICRS', ".self::number($ra).', '.self::number($dec).')) AS "_separation"'
            .' FROM "'.$table.'" WHERE 1 = CONTAINS('.$columns.", CIRCLE('ICRS', ".self::number($ra).', '.self::number($dec).', '.self::number($radius).'))'
            .' ORDER BY "_separation" ASC', true);

        return array_map(static function (array $row) use ($table): Row {
            $values = [];
            $separation = null;
            foreach ($row as $name => [$value, $meta]) {
                if ('_separation' === $name) {
                    $separation = is_numeric($value) ? $value * 3600 : null;
                    continue;
                }
                if (null === $value || (\is_string($value) && '' === trim($value))) {
                    continue;
                }
                $unit = self::text($meta['unit'] ?? null);
                $label = self::text($meta['description'] ?? null) ?? $name;
                $values[$name] = new Property($name, $label, null !== $unit && is_numeric($value) && !\is_string($value) ? new Quantity($value, $unit) : (\is_string($value) ? trim($value) : $value), 'vizier');
            }

            return new Row($table, $separation, $values);
        }, $rows);
    }

    /**
     * The rows of a table around an entity that has a position on the sky
     * - its "ra" and "dec" properties in degrees, as omnilab/simbad,
     * omnilab/ned and omnilab/gaia give them.
     *
     * @return list<Row>
     */
    public function rowsOf(string $table, Entity $object, float $radius = 5 / 3600, int $limit = 50): array
    {
        $ra = $object->property('ra')?->number();
        $dec = $object->property('dec')?->number();
        if (null === $ra || null === $dec) {
            throw new \InvalidArgumentException(\sprintf('%s has no position on the sky (no "ra" and "dec" properties).', $object->label));
        }

        return $this->rows($table, (float) $ra, (float) $dec, $radius, $limit);
    }

    /** A table's name as VizieR writes it: I/239/hip_main, its quotes and a "vizier:" dropped; refused when it could break the query. */
    private static function table(string $table): string
    {
        $table = trim((string) preg_replace('~^vizier:~i', '', trim($table)), '"');
        if (!preg_match('~^[A-Za-z0-9+_./-]{3,120}$~', $table)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not the name of a VizieR table (I/239/hip_main).', $table));
        }

        return $table;
    }

    /**
     * Runs an ADQL query; the rows keyed by column, each value with its
     * column's metadata when asked.
     *
     * @return list<array<string, mixed>>
     */
    private function query(string $adql, bool $withMeta = false): array
    {
        $data = $this->getJson('sync', ['REQUEST' => 'doQuery', 'LANG' => 'ADQL', 'FORMAT' => 'json', 'QUERY' => $adql]) ?? [];
        $meta = array_values(array_filter($data['metadata'] ?? [], 'is_array'));
        $names = array_map(static fn (array $c) => (string) $c['name'], $meta);
        $rows = [];
        foreach ($data['data'] ?? [] as $values) {
            if (\is_array($values) && \count($values) === \count($names)) {
                $rows[] = $withMeta ? array_combine($names, array_map(null, $values, $meta)) : array_combine($names, $values);
            }
        }

        return $rows;
    }

    /** @param array<string, mixed> $row a row of TAP_SCHEMA.tables */
    private function toDataset(array $row): Product
    {
        $table = trim((string) $row['table_name'], '"');
        $description = (string) self::text($row['description'] ?? null);
        // "The Hipparcos Main Catalogue ( ESA 1997)": the title, then its authors in brackets.
        $title = $description;
        $authors = null;
        if (preg_match('~^(.*?)\s*\(\s*([^()]*)\)\s*$~s', $description, $m) && '' !== trim($m[1])) {
            [$title, $authors] = [trim($m[1]), trim($m[2])];
        }
        $catalogue = preg_match('~^(.+)/[^/]+$~', $table, $m) && substr_count($table, '/') >= 2 ? $m[1] : $table;

        return new Product(
            id: $table,
            collection: $catalogue,
            properties: array_filter([
                'title' => $title,
                'authors' => $authors,
                'rows' => is_numeric($row['nrows'] ?? null) ? (int) $row['nrows'] : null,
            ], static fn (mixed $v) => null !== $v && '' !== $v),
            url: 'https://vizier.cds.unistra.fr/viz-bin/VizieR-3?-source='.str_replace('%2F', '/', rawurlencode($table)),
            source: 'vizier',
        );
    }

    /** A number as ADQL takes it, never in exponent form. */
    private static function number(float $value): string
    {
        return rtrim(rtrim(\sprintf('%.10F', $value), '0'), '.');
    }
}
