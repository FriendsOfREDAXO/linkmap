<?php

namespace FriendsOfRedaxo\Linkmap\Api;

use FriendsOfRedaxo\Linkmap\Link\LinkResolver;
use FriendsOfRedaxo\Linkmap\Source\SourceRegistry;
use rex_response;

/**
 * Moegliche URL-Schemata (mit Vorschau-URL) fuer einen yform://-Link --
 * Grundlage fuer die Schema-Auswahl im Picker, wenn eine Tabelle mehrere
 * Profile hat.
 *
 * GET index.php?rex-api-call=linkmap_link_schemes&link=yform://rex_news/42&clang=1
 */
final class LinkSchemes extends AbstractEndpoint
{
    protected function handle(): array
    {
        $link = rex_request('link', 'string', '');
        $parsed = LinkResolver::parse($link);
        if (null === $parsed) {
            $this->fail(rex_response::HTTP_BAD_REQUEST, 'Invalid link');
        }
        $clang = $this->clang();

        // Schema-Vorschau nur fuer Datensaetze, die der User auch sehen darf
        $provider = SourceRegistry::getProviderForLink($link);
        if (null === $provider || null === $provider->resolve(LinkResolver::build($parsed['table'], $parsed['id']), $clang)) {
            $this->fail(rex_response::HTTP_FORBIDDEN, 'Permission denied');
        }

        return [
            'link' => LinkResolver::build($parsed['table'], $parsed['id']),
            'clang' => $clang,
            'candidates' => LinkResolver::candidates($parsed['table'], $parsed['id'], $clang),
        ];
    }
}
