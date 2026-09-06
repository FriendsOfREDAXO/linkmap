<?php

namespace FriendsOfRedaxo\Linkmap;

use rex;
use rex_category;
use rex_structure_perm;
use rex_user;

/**
 * Buendelt alle Rechtepruefungen des Pickers.
 *
 * Grundrecht ist wie bei der klassischen Linkmap structure/hasStructurePerm;
 * der sichtbare Baum folgt den Mountpoints des Users, es sei denn, er hat
 * das Core-Recht linkmap[all_categories]. Verlauf und "alle Aenderungen"
 * sind eigene Rechte (boot.php).
 */
final class Permission
{
    public static function getUser(): ?rex_user
    {
        return rex::getUser();
    }

    public static function hasStructureAccess(): bool
    {
        $user = self::getUser();
        if (null === $user) {
            return false;
        }
        return $user->isAdmin() || self::structurePerm($user)->hasStructurePerm();
    }

    public static function seesAllCategories(): bool
    {
        $user = self::getUser();
        if (null === $user) {
            return false;
        }
        return $user->isAdmin() || $user->hasPerm('linkmap[all_categories]');
    }

    public static function hasCategoryAccess(int $categoryId): bool
    {
        $user = self::getUser();
        if (null === $user) {
            return false;
        }
        if (self::seesAllCategories()) {
            return true;
        }
        return self::structurePerm($user)->hasCategoryPerm($categoryId);
    }

    public static function hasHistoryAccess(): bool
    {
        $user = self::getUser();
        if (null === $user) {
            return false;
        }
        return $user->isAdmin() || $user->hasPerm('linkmap[history]');
    }

    public static function seesAllChanges(): bool
    {
        $user = self::getUser();
        if (null === $user) {
            return false;
        }
        return $user->isAdmin() || $user->hasPerm('linkmap[all_changes]');
    }

    /**
     * Wurzeln des sichtbaren Baums: Mountpoint-Kategorien des Users oder
     * (ohne Mountpoints bzw. mit linkmap[all_categories]) alle Root-Kategorien.
     *
     * @return list<rex_category>
     */
    public static function getRootCategories(int $clang): array
    {
        $user = self::getUser();
        if (null === $user) {
            return [];
        }

        if (!self::seesAllCategories()) {
            $mountpoints = self::structurePerm($user)->getMountpoints();
            if (count($mountpoints) > 0) {
                $roots = [];
                foreach ($mountpoints as $mountpointId) {
                    $category = rex_category::get((int) $mountpointId, $clang);
                    if ($category instanceof rex_category) {
                        $roots[] = $category;
                    }
                }
                return $roots;
            }
        }

        return rex_category::getRootCategories(false, $clang);
    }

    /** Ob der User Artikel der obersten Ebene (Kategorie 0) sehen darf. */
    public static function hasRootAccess(): bool
    {
        $user = self::getUser();
        if (null === $user) {
            return false;
        }
        if (self::seesAllCategories()) {
            return true;
        }
        return !self::structurePerm($user)->hasMountpoints();
    }

    private static function structurePerm(rex_user $user): rex_structure_perm
    {
        /** @var rex_structure_perm $perm */
        $perm = $user->getComplexPerm('structure');
        return $perm;
    }
}
