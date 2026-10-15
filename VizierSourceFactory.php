<?php

namespace Omnilab\Vizier;

use Omnilab\Config;
use Omnilab\Source\SourceFactory;
use Omnilab\Source\SourceInterface;
use Symfony\Component\HttpClient\HttpClient;

/**
 * VizieR, the CDS's library of published astronomical catalogues: its
 * tables by text, and the rows of a table around a point of the sky - no
 * key needed.
 *
 *   options:
 *     mailto: 'support@glitchr.io'     # in the User-Agent
 *     throttle: 0                      # seconds between two calls: the CDS publishes no rate for TAP
 *     base_uri: 'https://tapvizier.cds.unistra.fr/TAPVizieR/tap/'
 */
final class VizierSourceFactory extends SourceFactory
{
    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnilab.factory_name' => 'vizier',
            'omnilab.required_options' => [],
            'throttle' => 0.0,
            'base_uri' => VizierSource::BASE_URI,
        ]);
    }

    protected function build(Config $c): SourceInterface
    {
        return new VizierSource(
            $this->http ?? HttpClient::create(),
            (string) $c->get('base_uri', VizierSource::BASE_URI),
            ['User-Agent' => self::userAgent($c)],
            (float) $c->get('throttle'),
        );
    }
}
