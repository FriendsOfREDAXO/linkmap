<?php

namespace FriendsOfRedaxo\Linkmap\Api;

use FriendsOfRedaxo\Linkmap\Formatter;
use FriendsOfRedaxo\Linkmap\Permission;
use FriendsOfRedaxo\Linkmap\Source\SourceRegistry;
use rex_article;

use function count;

/**
 * Links (redaxo://ID, yform://tabelle/id, …) zu anzeigbaren Items aufloesen
 * -- fuer das lm-widget im Link-Modus. Unbekannte/nicht erlaubte Links
 * fehlen in der Antwort.
 *
 * GET index.php?rex-api-call=linkmap_link_resolve&links=redaxo://5,yform://rex_news/3&clang=1
 */
final class LinkResolve extends AbstractEndpoint
{
    private const MAX_LINKS = 200;

    protected function handle(): array
    {
        $clang = $this->clang();
        $items = [];
        $links = array_values(array_unique(array_filter(array_map('trim', explode(',', rex_request('links', 'string', ''))))));

        foreach (array_slice($links, 0, self::MAX_LINKS) as $link) {
            if (preg_match('~^redaxo://(\d+)$~', $link, $m)) {
                $article = rex_article::get((int) $m[1], $clang);
                if ($article instanceof rex_article && Permission::hasCategoryAccess($article->getCategoryId())) {
                    $item = Formatter::article($article);
                    $item['source'] = 'article';
                    $item['linkable'] = true;
                    $items[] = $item;
                }
                continue;
            }
            $provider = SourceRegistry::getProviderForLink($link);
            $item = null !== $provider ? $provider->resolve($link, $clang) : null;
            if (null !== $item) {
                $items[] = $item;
            }
        }

        return ['clang' => $clang, 'items' => $items, 'count' => count($items)];
    }
}
