<?php

namespace FriendsOfRedaxo\Linkmap\Api;

use FriendsOfRedaxo\Linkmap\Formatter;
use FriendsOfRedaxo\Linkmap\Permission;
use rex_category;

/**
 * Kompletter Kategoriebaum einer Sprache fuer die Sidebar -- bewusst in einem
 * Rutsch statt lazy pro Ebene, damit Live-Filter (Name/ID/Domain) und
 * Domain-Filter rein clientseitig laufen koennen.
 * Artikel haengen NICHT am Baum (siehe Articles.php), das haelt die Antwort
 * auch bei grossen Strukturen klein.
 *
 * GET index.php?rex-api-call=linkmap_tree&clang=1
 */
final class Tree extends AbstractEndpoint
{
    protected function handle(): array
    {
        $clang = $this->clang();

        return [
            'clang' => $clang,
            'rootAccess' => Permission::hasRootAccess(),
            'tree' => $this->branch(Permission::getRootCategories($clang)),
        ];
    }

    /**
     * @param list<rex_category> $categories
     * @return list<array<string, mixed>>
     */
    private function branch(array $categories): array
    {
        $nodes = [];
        foreach ($categories as $category) {
            $node = Formatter::category($category);
            $node['children'] = $this->branch($category->getChildren(false));
            $nodes[] = $node;
        }
        return $nodes;
    }
}
