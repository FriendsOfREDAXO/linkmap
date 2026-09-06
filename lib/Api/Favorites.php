<?php

namespace FriendsOfRedaxo\Linkmap\Api;

use FriendsOfRedaxo\Linkmap\Favorites as FavoritesStore;
use FriendsOfRedaxo\Linkmap\Formatter;
use FriendsOfRedaxo\Linkmap\Permission;
use rex;
use rex_category;
use rex_i18n;
use rex_request;
use rex_response;

/**
 * Favoriten-Kategorien des eingeloggten Users.
 *
 * GET  index.php?rex-api-call=linkmap_favorites&clang=1
 * POST index.php?rex-api-call=linkmap_favorites&action=toggle&category_id=5&_csrf_token=...
 *
 * Schreibende Aufrufe sind CSRF-geschuetzt (Token-Key = Klassenname, siehe
 * rex_api_function::handleCall()); boot.php reicht das Token per #lm-root durch.
 */
final class Favorites extends AbstractEndpoint
{
    protected function handle(): array
    {
        $clang = $this->clang();
        $userId = rex::requireUser()->getId();
        $action = rex_request('action', 'string', '');

        if ('toggle' === $action) {
            if ('post' !== rex_request::requestMethod()) {
                $this->fail(rex_response::HTTP_BAD_REQUEST, 'POST required');
            }
            $categoryId = rex_request('category_id', 'int', -1);
            if ($categoryId < 0 || !Permission::hasCategoryAccess($categoryId)) {
                $this->fail(rex_response::HTTP_FORBIDDEN, 'Permission denied');
            }
            if ($categoryId > 0 && !rex_category::get($categoryId, $clang) instanceof rex_category) {
                $this->fail(rex_response::HTTP_NOT_FOUND, 'Category not found');
            }
            FavoritesStore::toggle($userId, $categoryId);
        }

        $favorites = [];
        foreach (FavoritesStore::get($userId) as $categoryId) {
            if (!Permission::hasCategoryAccess($categoryId)) {
                continue;
            }
            if (0 === $categoryId) {
                if (!Permission::hasRootAccess()) {
                    continue;
                }
                $favorites[] = [
                    'id' => 0,
                    'name' => rex_i18n::msg('root_level'),
                    'label' => rex_i18n::msg('root_level'),
                    'link' => '',
                    'online' => true,
                    'parentId' => 0,
                    'clang' => $clang,
                    'domain' => '',
                    'path' => [],
                ];
                continue;
            }
            $category = rex_category::get($categoryId, $clang);
            if ($category instanceof rex_category) {
                $favorites[] = Formatter::category($category);
            }
        }

        return ['clang' => $clang, 'favorites' => $favorites];
    }

    protected function requiresCsrfProtection(): bool
    {
        return 'toggle' === rex_request('action', 'string', '');
    }
}
