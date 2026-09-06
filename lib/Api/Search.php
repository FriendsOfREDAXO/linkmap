<?php

namespace FriendsOfRedaxo\Linkmap\Api;

use FriendsOfRedaxo\Linkmap\Formatter;
use FriendsOfRedaxo\Linkmap\Permission;
use rex;
use rex_addon;
use rex_article;
use rex_sql;

use function count;
use function strlen;

/**
 * Artikelsuche ueber die ganze Struktur (Name oder ID), optional auf eine
 * yrewrite-Domain eingeschraenkt. Rechte und Domain werden nach dem
 * SQL-Treffer pro Artikel geprueft, deshalb wird bewusst mehr geladen
 * (SCAN_LIMIT) als zurueckgegeben (search_limit aus den Einstellungen).
 *
 * GET index.php?rex-api-call=linkmap_search&q=kontakt&clang=1&domain=example.org
 */
final class Search extends AbstractEndpoint
{
    private const SCAN_LIMIT = 400;

    protected function handle(): array
    {
        $clang = $this->clang();
        $query = trim(rex_request('q', 'string', ''));
        $domain = trim(rex_request('domain', 'string', ''));
        $limit = max(1, min(200, (int) rex_addon::get('linkmap')->getConfig('search_limit', 50)));

        if ('' === $query) {
            return ['clang' => $clang, 'query' => '', 'articles' => [], 'truncated' => false];
        }

        $sql = rex_sql::factory();
        $where = 'clang_id = :clang AND (name LIKE :name';
        $params = ['clang' => $clang, 'name' => '%' . $sql->escapeLikeWildcards($query) . '%', 'exact' => 0];
        if (ctype_digit($query) && strlen($query) < 10) {
            $where .= ' OR id = :id';
            $params['id'] = (int) $query;
            $params['exact'] = (int) $query;
        }
        $where .= ')';

        $rows = $sql->getArray(
            'SELECT id FROM ' . rex::getTable('article') . ' WHERE ' . $where . ' ORDER BY (id = :exact) DESC, name ASC LIMIT ' . self::SCAN_LIMIT,
            $params,
        );

        $articles = [];
        $truncated = false;
        foreach ($rows as $row) {
            $article = rex_article::get((int) $row['id'], $clang);
            if (!$article instanceof rex_article) {
                continue;
            }
            if (!Permission::hasCategoryAccess($article->getCategoryId())) {
                continue;
            }
            $data = Formatter::article($article);
            if ('' !== $domain && $data['domain'] !== $domain) {
                continue;
            }
            if (count($articles) >= $limit) {
                $truncated = true;
                break;
            }
            $articles[] = $data;
        }

        return [
            'clang' => $clang,
            'query' => $query,
            'articles' => $articles,
            'truncated' => $truncated,
        ];
    }
}
