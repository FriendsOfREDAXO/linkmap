<?php

namespace FriendsOfRedaxo\Linkmap\Api;

use FriendsOfRedaxo\Linkmap\Source\SourceRegistry;
use rex_response;

/**
 * Eintraege eines Quellen-Containers (z.B. YForm-Tabelle), durchsuchbar
 * und paginiert.
 *
 * GET index.php?rex-api-call=linkmap_source_browse&source=yform&container=rex_news&q=…&p=1&clang=1&sort=title&dir=asc
 *
 * Seitenzahl als "p", nicht "page": "page" ist REDAXOs Backend-Seitenparameter
 * und wuerde den API-Aufruf auf eine (nicht existierende) Backend-Seite umleiten.
 */
final class SourceBrowse extends AbstractEndpoint
{
    protected function handle(): array
    {
        $provider = SourceRegistry::getProvider(rex_request('source', 'string', ''));
        if (null === $provider) {
            $this->fail(rex_response::HTTP_NOT_FOUND, 'Unknown source');
        }
        $container = rex_request('container', 'string', '');
        // mode=relation: Relation-Felder legen die Tabelle selbst fest, sie muss
        // nicht als Quelle freigegeben sein -- Rechte prueft der Provider.
        if ('relation' === rex_request('mode', 'string', '')) {
            if (!$provider->hasContainerAccess($container)) {
                $this->fail(rex_response::HTTP_FORBIDDEN, 'Permission denied');
            }
        } else {
            $known = array_filter($provider->getContainers(), static fn (array $c): bool => $c['id'] === $container);
            if ([] === $known) {
                $this->fail(rex_response::HTTP_FORBIDDEN, 'Permission denied');
            }
        }

        $result = $provider->browse(
            $container,
            rex_request('q', 'string', ''),
            max(1, rex_request('p', 'int', 1)),
            $this->clang(),
            rex_request('sort', 'string', ''),
            rex_request('dir', 'string', 'asc'),
        );
        $result['source'] = $provider->getId();
        $result['container'] = $container;
        $result['clang'] = $this->clang();
        return $result;
    }
}
