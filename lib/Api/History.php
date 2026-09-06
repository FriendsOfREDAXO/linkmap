<?php

namespace FriendsOfRedaxo\Linkmap\Api;

use FriendsOfRedaxo\Linkmap\Formatter;
use FriendsOfRedaxo\Linkmap\Permission;
use rex;
use rex_addon;
use rex_article;
use rex_response;
use rex_sql;

use function count;

/**
 * Zuletzt bearbeitete Artikel: sprachuebergreifend nach updatedate, ohne linkmap[all_changes] nur die
 * eigenen Aenderungen. Rechte auf die Kategorie werden pro Treffer geprueft.
 *
 * GET index.php?rex-api-call=linkmap_history
 */
final class History extends AbstractEndpoint
{
    protected function handle(): array
    {
        if (!Permission::hasHistoryAccess()) {
            $this->fail(rex_response::HTTP_FORBIDDEN, 'Permission denied');
        }

        $limit = max(1, min(100, (int) rex_addon::get('linkmap')->getConfig('history_limit', 15)));

        $where = '';
        $params = [];
        if (!Permission::seesAllChanges()) {
            $where = ' WHERE updateuser = :user';
            $params['user'] = (string) rex::requireUser()->getLogin();
        }

        // Etwas mehr laden als angezeigt: Treffer ohne Kategorie-Recht fallen weg.
        $rows = rex_sql::factory()->getArray(
            'SELECT id, clang_id FROM ' . rex::getTable('article') . $where . ' ORDER BY updatedate DESC LIMIT ' . ($limit * 3),
            $params,
        );

        $articles = [];
        foreach ($rows as $row) {
            $article = rex_article::get((int) $row['id'], (int) $row['clang_id']);
            if (!$article instanceof rex_article || !Permission::hasCategoryAccess($article->getCategoryId())) {
                continue;
            }
            $articles[] = Formatter::article($article);
            if (count($articles) >= $limit) {
                break;
            }
        }

        return ['articles' => $articles];
    }
}
