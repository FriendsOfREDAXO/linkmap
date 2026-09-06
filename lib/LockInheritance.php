<?php

namespace FriendsOfRedaxo\Linkmap;

use rex_addon;
use rex_article;
use rex_category;
use rex_structure_element;

/**
 * Vererbte Sperre des accessdenied-Addons: ist dort "Kategoriestatus
 * vererben" aktiv, gelten alle Artikel und Unterkategorien einer gesperrten
 * Kategorie (Status 2) ebenfalls als gesperrt -- genau wie im Frontend-
 * Redirect des Addons (Accessdenied::handleFrontendRedirect()). Der Picker
 * zeigt solche Elemente mit demselben Status und nennt die sperrende
 * Kategorie.
 */
final class LockInheritance
{
    public const LOCKED_STATUS = 2;

    public static function isActive(): bool
    {
        $addon = rex_addon::get('accessdenied');
        return $addon->isAvailable() && (bool) $addon->getConfig('inherit', false);
    }

    /**
     * Sperrende Kategorie (Eltern, bei Kategorien ohne sich selbst), oder null.
     */
    public static function lockingCategory(rex_structure_element $element): ?rex_category
    {
        if (!self::isActive()) {
            return null;
        }
        $start = $element instanceof rex_article ? $element->getCategory() : ($element instanceof rex_category ? $element->getParent() : null);
        if (null === $start) {
            return null;
        }
        $found = $start->getClosest(static fn (rex_structure_element $cat): bool => self::LOCKED_STATUS === (int) $cat->getValue('status'));
        return $found instanceof rex_category ? $found : null;
    }
}
