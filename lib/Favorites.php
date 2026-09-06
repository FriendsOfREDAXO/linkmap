<?php

namespace FriendsOfRedaxo\Linkmap;

use rex_config;

use function is_array;

/**
 * Favoriten-Kategorien pro Backend-User (rex_config linkmap/favorites_<id>).
 */
final class Favorites
{
    /** @return list<int> */
    public static function get(int $userId): array
    {
        $stored = rex_config::get('linkmap', self::key($userId));
        return is_array($stored) ? self::normalize($stored) : [];
    }

    /** @param list<int> $categoryIds */
    public static function set(int $userId, array $categoryIds): void
    {
        rex_config::set('linkmap', self::key($userId), self::normalize($categoryIds));
    }

    /** @return list<int> neue Liste */
    public static function toggle(int $userId, int $categoryId): array
    {
        $current = self::get($userId);
        $index = array_search($categoryId, $current, true);
        if (false === $index) {
            $current[] = $categoryId;
        } else {
            unset($current[$index]);
        }
        $current = array_values($current);
        self::set($userId, $current);
        return $current;
    }

    private static function key(int $userId): string
    {
        return 'favorites_' . $userId;
    }

    /**
     * @param array<mixed> $ids
     * @return list<int>
     */
    private static function normalize(array $ids): array
    {
        $result = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id >= 0 && !in_array($id, $result, true)) {
                $result[] = $id;
            }
        }
        return $result;
    }
}
