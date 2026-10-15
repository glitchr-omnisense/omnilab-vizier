<?php

namespace Omnilab\Vizier\Tests;

use Omnilab\Exception\NotSupportedException;
use Omnilab\Exception\ProviderException;
use Omnilab\Model\Entity;
use Omnilab\Model\Identifier;
use Omnilab\Model\Property;
use Omnilab\Model\Quantity;
use Omnilab\Model\Scheme;
use Omnilab\Source\Capability;
use Omnilab\Source\Query;
use Omnilab\Vizier\Row;
use Omnilab\Vizier\VizierSource;
use Omnilab\Vizier\VizierSourceFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Fixtures recorded from tapvizier.cds.unistra.fr on 2026-10-16: the
 * tables whose description names Hipparcos (six, the largest first), a
 * text no table holds, the main position columns of the Hipparcos Main
 * Catalogue (I/239/hip_main), and its rows within 5' of Sirius.
 */
final class VizierSourceTest extends TestCase
{
    /** @var list<string> the ADQL queries asked */
    private array $calls = [];

    private function source(?MockResponse $response = null): VizierSource
    {
        $http = new MockHttpClient(function (string $method, string $url) use ($response): MockResponse {
            self::assertSame('/TAPVizieR/tap/sync', parse_url($url, \PHP_URL_PATH));
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            $this->calls[] = $adql = $query['QUERY'];
            if ($response) {
                return $response;
            }
            $fixture = match (true) {
                str_contains($adql, "LIKE '%Hipparcos%'") => 'search-hipparcos.json',
                str_contains($adql, 'FROM TAP_SCHEMA.columns') && str_contains($adql, 'I/239/hip_main') => 'columns-hip_main.json',
                str_contains($adql, 'FROM "I/239/hip_main"') => 'rows-hip_main-sirius.json',
                default => 'search-none.json',
            };

            return new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/'.$fixture));
        });
        $source = (new VizierSourceFactory($http))->create();
        self::assertInstanceOf(VizierSource::class, $source);

        return $source;
    }

    public function testTheTablesThatNameHipparcos(): void
    {
        $page = $this->source()->datasets(new Query('hipparcos', limit: 5));

        self::assertSame("SELECT TOP 6 table_name, description, nrows FROM TAP_SCHEMA.tables WHERE (description LIKE '%hipparcos%' OR description LIKE '%Hipparcos%' OR description LIKE '%HIPPARCOS%') ORDER BY nrows DESC, table_name ASC", $this->calls[0], 'no ILIKE nor LOWER at VizieR: each case asked');
        self::assertCount(5, $page);
        self::assertNull($page->next, 'no OFFSET in VizieR\'s ADQL');
        self::assertNull($page->total, 'more than five');
        $hip = $page->items[2];
        self::assertSame('I/239/hip_main', $hip->id);
        self::assertSame('I/239', $hip->collection);
        self::assertSame('The Hipparcos Main Catalogue', $hip->properties['title']);
        self::assertSame('ESA 1997', $hip->properties['authors']);
        self::assertSame(118218, $hip->properties['rows']);
        self::assertSame('https://vizier.cds.unistra.fr/viz-bin/VizieR-3?-source=I/239/hip_main', $hip->url);
        self::assertSame('vizier', $hip->source);
        self::assertSame('I/337/tgas', $page->first()->id, 'the largest first');
    }

    public function testNothingAndTheWholePage(): void
    {
        $none = $this->source()->datasets(new Query('zzqqxx'));
        self::assertCount(0, $none);
        self::assertSame(0, $none->total);
        self::assertCount(0, $this->source()->datasets(new Query("  '%  ")));
        self::assertCount(1, $this->calls, 'an empty text asks nothing');
    }

    public function testTheRowsOfACatalogueAroundSirius(): void
    {
        $rows = $this->source()->rows('I/239/hip_main', 101.28715533, -16.71611586, 5 / 60, 4);

        self::assertCount(2, $this->calls, 'its position columns, then its rows');
        self::assertSame("SELECT column_name, unit, ucd FROM TAP_SCHEMA.columns WHERE table_name = '\"I/239/hip_main\"' AND ucd LIKE 'pos.eq.%;meta.main'", $this->calls[0]);
        self::assertSame("SELECT TOP 4 *, DISTANCE(POINT('ICRS', \"RAICRS\", \"DEICRS\"), POINT('ICRS', 101.28715533, -16.71611586)) AS \"_separation\" FROM \"I/239/hip_main\" WHERE 1 = CONTAINS(POINT('ICRS', \"RAICRS\", \"DEICRS\"), CIRCLE('ICRS', 101.28715533, -16.71611586, 0.0833333333)) ORDER BY \"_separation\" ASC", $this->calls[1]);
        self::assertCount(1, $rows);
        $sirius = $rows[0];
        self::assertInstanceOf(Row::class, $sirius);
        self::assertSame('I/239/hip_main', $sirius->table);
        self::assertEqualsWithDelta(11.72, $sirius->separation, 0.01, 'arcseconds: Hipparcos\' epoch J1991.25');
        self::assertSame(32349, $sirius->value('HIP'));
        self::assertEquals(new Quantity(-1.44, 'mag'), $sirius->value('Vmag'));
        self::assertSame('06 45 09.25', $sirius->value('RAhms'));
        self::assertEquals(new Quantity(379.21, 'mas'), $sirius->value('Plx'));
        self::assertSame('A0m...', $sirius->value('SpType'));
        self::assertNull($sirius->value('Proxy'), 'a blank is no value');
        self::assertSame('vizier', $sirius->values['HIP']->source);
    }

    public function testTheRowsAroundAnEntity(): void
    {
        $star = new Entity(Identifier::of(Scheme::SIMBAD, '* alf CMa'), 'Sirius', properties: [new Property('ra', 'RA', new Quantity(101.28715533, 'deg')), new Property('dec', 'Dec', new Quantity(-16.71611586, 'deg'))]);
        self::assertSame(32349, $this->source()->rowsOf('I/239/hip_main', $star, 5 / 60, 4)[0]->value('HIP'));

        $this->expectException(\InvalidArgumentException::class);
        $this->source()->rowsOf('I/239/hip_main', new Entity(Identifier::of(Scheme::WIKIDATA, 'Q8063'), 'no position'));
    }

    public function testWhatIsRefused(): void
    {
        foreach ([['I/239/hip_main" WHERE 1=1 --', 10.0, 10.0], ['I/239/hip_main', 360.0, 0.0], ['I/239/hip_main', 10.0, 0.0, 0.0]] as $args) {
            try {
                $this->source()->rows(...$args);
                self::fail('refused');
            } catch (\InvalidArgumentException) {
            }
        }
        self::assertSame([], $this->calls);
        try {
            $this->source()->rows('B/none/none', 10.0, 10.0);
            self::fail('no position columns');
        } catch (ProviderException $e) {
            self::assertStringContainsString('has no main position', $e->getMessage());
        }
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Encountered "ILIKE"');
        $this->source(new MockResponse('<VOTABLE><INFO name="QUERY_STATUS" value="ERROR">Incorrect ADQL query:  Encountered "ILIKE".</INFO></VOTABLE>', ['http_code' => 400]))->datasets(new Query('gaia'));
    }

    public function testTheFactory(): void
    {
        $factory = new VizierSourceFactory(new MockHttpClient());
        $source = $factory->create(['mailto' => 'support@glitchr.io']);
        self::assertSame('vizier', $factory->getName());
        self::assertSame([Capability::DATASETS], $source->capabilities());
        $this->expectException(NotSupportedException::class);
        $source->entity('I/239');
    }
}
