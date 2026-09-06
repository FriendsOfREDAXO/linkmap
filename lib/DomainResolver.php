<?php

namespace FriendsOfRedaxo\Linkmap;

use rex_addon;
use rex_article;
use rex_yrewrite;

use function count;

/**
 * Optionale yrewrite-Anbindung: Domain-Liste fuer den Filter im Overlay und
 * Domain-Zuordnung einzelner Artikel/Kategorien.
 *
 * Die Zuordnung laeuft ueber die Mountpoints der Domains (Elternkette des
 * Elements, tiefster Mountpoint gewinnt; ohne Treffer die Domain mit
 * Mountpoint 0). Bewusst NICHT ueber rex_yrewrite::getDomainByArticleId():
 * das liest yrewrites generierte Pfad-Datei, die nach dem Anlegen einer
 * Domain oder neuer Artikel veraltet sein kann -- dann landen Elemente
 * faelschlich auf der Default-Domain und der Filter zeigt leere Baeume.
 * Ohne yrewrite (oder mit nur einer Domain) ist der Filter unsichtbar und
 * jede Zuordnung leer.
 */
final class DomainResolver
{
    /** @var array<int, string>|null mountId => Domain-Name */
    private static ?array $mountMap = null;

    public static function isAvailable(): bool
    {
        return rex_addon::get('yrewrite')->isAvailable() && class_exists(rex_yrewrite::class);
    }

    /** Ob sich ein Domain-Filter lohnt: mindestens zwei echte (nicht-default) Domains. */
    public static function hasFilter(): bool
    {
        return count(self::getDomains()) >= 2;
    }

    /**
     * Echte Domains ohne den yrewrite-internen "default"-Eintrag.
     *
     * @return list<array{name: string, host: string, url: string, mountId: int, startId: int}>
     */
    public static function getDomains(): array
    {
        if (!self::isAvailable()) {
            return [];
        }

        $domains = [];
        foreach (rex_yrewrite::getDomains() as $name => $domain) {
            if ('default' === (string) $name) {
                continue;
            }
            $domains[] = [
                'name' => (string) $domain->getName(),
                'host' => (string) $domain->getHost(),
                'url' => (string) $domain->getUrl(),
                'mountId' => (int) $domain->getMountId(),
                'startId' => (int) $domain->getStartId(),
            ];
        }

        return $domains;
    }

    /** Name der Domain mit Mountpoint 0 (Standard-Domain), leer wenn keine. */
    public static function getDefaultDomainName(): string
    {
        return self::mountMap()[0] ?? '';
    }

    /** Domain-Name eines Artikels bzw. einer Kategorie (leer ohne yrewrite oder ohne Zuordnung). */
    public static function domainOf(int $articleId, int $clang): string
    {
        if (!self::isAvailable() || $articleId <= 0) {
            return '';
        }

        $map = self::mountMap();
        if ([] === $map) {
            return '';
        }

        $article = rex_article::get($articleId, $clang);
        if (!$article instanceof rex_article) {
            return $map[0] ?? '';
        }

        $ids = array_map(intval(...), $article->getPathAsArray());
        $ids[] = $articleId;
        for ($i = count($ids) - 1; $i >= 0; --$i) {
            if ($ids[$i] > 0 && isset($map[$ids[$i]])) {
                return $map[$ids[$i]];
            }
        }

        return $map[0] ?? '';
    }

    /** @return array<int, string> */
    private static function mountMap(): array
    {
        if (null === self::$mountMap) {
            self::$mountMap = [];
            foreach (self::getDomains() as $domain) {
                self::$mountMap[$domain['mountId']] = $domain['name'];
            }
        }
        return self::$mountMap;
    }
}
