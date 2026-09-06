<?php

namespace FriendsOfRedaxo\Linkmap\Api;

use FriendsOfRedaxo\Linkmap\Formatter;
use FriendsOfRedaxo\Linkmap\Permission;
use rex_article;
use rex_category;
use rex_response;

use function count;

/**
 * Inhalt einer Kategorie fuer die Hauptansicht: Unterkategorien + Artikel +
 * Breadcrumb. Kategorie 0 = oberste Ebene (Root-Artikel), wie in der
 * klassischen Linkmap nur ohne Mountpoints bzw. mit linkmap[all_categories]
 * erreichbar; bei genau einem Mountpoint wird direkt dorthin umgeleitet.
 *
 * GET index.php?rex-api-call=linkmap_articles&category_id=5&clang=1
 */
final class Articles extends AbstractEndpoint
{
    protected function handle(): array
    {
        $clang = $this->clang();
        $categoryId = rex_request('category_id', 'int', 0);

        if (0 === $categoryId) {
            $roots = Permission::getRootCategories($clang);
            if (!Permission::hasRootAccess() && 1 === count($roots)) {
                return $this->category($roots[0]->getId(), $clang);
            }

            $articles = Permission::hasRootAccess() ? rex_article::getRootArticles(false, $clang) : [];

            return [
                'clang' => $clang,
                'categoryId' => 0,
                'category' => null,
                'breadcrumb' => [],
                'categories' => array_map(Formatter::category(...), $roots),
                'articles' => array_map(Formatter::article(...), $articles),
            ];
        }

        return $this->category($categoryId, $clang);
    }

    /** @return array<string, mixed> */
    private function category(int $categoryId, int $clang): array
    {
        $category = rex_category::get($categoryId, $clang);
        if (!$category instanceof rex_category) {
            $this->fail(rex_response::HTTP_NOT_FOUND, 'Category not found');
        }
        if (!Permission::hasCategoryAccess($categoryId)) {
            $this->fail(rex_response::HTTP_FORBIDDEN, 'Permission denied');
        }

        $breadcrumb = [];
        foreach ($category->getParentTree() as $parent) {
            $breadcrumb[] = ['id' => $parent->getId(), 'name' => Formatter::name($parent)];
        }

        return [
            'clang' => $clang,
            'categoryId' => $categoryId,
            'category' => Formatter::category($category),
            'breadcrumb' => $breadcrumb,
            'categories' => array_map(Formatter::category(...), $category->getChildren(false)),
            'articles' => array_map(Formatter::article(...), $category->getArticles(false)),
        ];
    }
}
