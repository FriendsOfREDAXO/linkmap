<?php

namespace FriendsOfRedaxo\Linkmap\Api;

use FriendsOfRedaxo\Linkmap\Formatter;
use FriendsOfRedaxo\Linkmap\Permission;
use rex_article;

/**
 * Einzelne Artikel per ID aufloesen -- fuer das Detail-Panel im Overlay und
 * die Anzeige gespeicherter Werte im lm-widget. Nicht auffindbare oder
 * nicht erlaubte IDs fehlen in der Antwort einfach (Widget zeigt dann die
 * nackte ID).
 *
 * GET index.php?rex-api-call=linkmap_article&ids=12,15&clang=1
 */
final class Article extends AbstractEndpoint
{
    private const MAX_IDS = 200;

    protected function handle(): array
    {
        $clang = $this->clang();
        $raw = rex_request('ids', 'string', '');
        if ('' === $raw) {
            $raw = rex_request('id', 'string', '');
        }

        $ids = [];
        foreach (explode(',', $raw) as $part) {
            $id = (int) trim($part);
            if ($id > 0 && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
            if (count($ids) >= self::MAX_IDS) {
                break;
            }
        }

        $articles = [];
        foreach ($ids as $id) {
            $article = rex_article::get($id, $clang);
            if (!$article instanceof rex_article || !Permission::hasCategoryAccess($article->getCategoryId())) {
                continue;
            }
            $articles[] = Formatter::article($article);
        }

        return ['clang' => $clang, 'articles' => $articles];
    }
}
