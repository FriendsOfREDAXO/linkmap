<?php

namespace FriendsOfRedaxo\Linkmap;

use rex_article;
use rex_category;
use rex_clang;
use rex_structure_element;


/**
 * Einheitliche JSON-Formen fuer Kategorien und Artikel -- alle Endpunkte und
 * das Overlay (linkmap.js) sprechen nur diese Struktur.
 */
final class Formatter
{
    /**
     * @return array{id: int, name: string, label: string, link: string, online: bool, status: int, parentId: int, clang: int, domain: string, path: list<string>}
     */
    public static function category(rex_category $category): array
    {
        $id = $category->getId();
        return [
            'id' => $id,
            'name' => self::name($category),
            'label' => self::label($category),
            'link' => 'redaxo://' . $id,
            'online' => $category->isOnline(),
            'status' => (int) $category->getValue('status'),
            'parentId' => $category->getParentId(),
            'clang' => $category->getClangId(),
            'domain' => DomainResolver::domainOf($id, $category->getClangId()),
            'path' => self::path($category),
        ];
    }

    /**
     * @return array{id: int, name: string, label: string, link: string, online: bool, status: int, startarticle: bool, sitestart: bool, hasTemplate: bool, categoryId: int, parentId: int, clang: int, clangCode: string, domain: string, path: list<string>, updatedate: int, updateuser: string}
     */
    public static function article(rex_article $article): array
    {
        $id = $article->getId();
        $clang = $article->getClangId();
        $clangObj = rex_clang::get($clang);

        return [
            'id' => $id,
            'name' => self::name($article),
            'label' => self::label($article),
            'link' => 'redaxo://' . $id,
            'online' => $article->isOnline(),
            'status' => (int) $article->getValue('status'),
            'startarticle' => $article->isStartArticle(),
            'sitestart' => $article->isSiteStartArticle(),
            'hasTemplate' => $article->hasTemplate(),
            'categoryId' => $article->getCategoryId(),
            'parentId' => $article->getParentId(),
            'clang' => $clang,
            'clangCode' => $clangObj ? $clangObj->getCode() : '',
            'domain' => DomainResolver::domainOf($id, $clang),
            'path' => self::path($article),
            'updatedate' => (int) $article->getUpdateDate(),
            'updateuser' => (string) $article->getUpdateUser(),
        ];
    }

    /** Anzeigename; leere Namen bekommen den Platzhalter der klassischen Linkmap. */
    public static function name(rex_structure_element $element): string
    {
        $name = trim($element->getName());
        return '' === $name ? '-' : $name;
    }

    /**
     * Label fuer rex:selectLink/Callbacks: nur der Name. Die klassische
     * Linkmap lieferte "Name [ID]" -- das landete in TinyMCE/CKE5 als
     * Linktext, wenn kein Text markiert war. Das Core-Format nutzen nur noch
     * REX_LINK_*_NAME und REX_LINKLIST-Optionen (linkmap_classic.js,
     * linkmap_takeover.php), damit sie nach dem Speichern so aussehen wie
     * vom Core gerendert.
     */
    public static function label(rex_structure_element $element): string
    {
        return self::name($element);
    }

    /**
     * Kategorie-Pfad (Namen der Eltern, ohne das Element selbst).
     *
     * @return list<string>
     */
    public static function path(rex_structure_element $element): array
    {
        $path = [];
        foreach ($element->getParentTree() as $parent) {
            if ($parent->getId() === $element->getId()) {
                continue;
            }
            $path[] = self::name($parent);
        }
        return $path;
    }
}
