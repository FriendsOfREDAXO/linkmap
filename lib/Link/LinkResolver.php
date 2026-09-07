<?php

namespace FriendsOfRedaxo\Linkmap\Link;

use FriendsOfRedaxo\Linkmap\TableConfig;
use rex_addon;
use rex_clang;
use rex_config;
use rex_extension;
use rex_extension_point;
use rex_yform_manager_dataset;
use rex_yform_manager_table;
use rex_yrewrite;
use Url\Profile;

use function count;
use function in_array;
use function is_string;

/**
 * Loest yform://-Links zu Frontend-URLs auf.
 *
 * Linkformat: yform://<tabelle>/<id>[?scheme=<provider>:<key>]
 *   - ohne scheme: automatische Wahl (Sprache, aktuelle Domain, erstes
 *     Schema, das fuer den Datensatz eine URL liefert)
 *   - mit scheme: genau dieses Schema (url-Addon-Namespace bzw.
 *     virtual_urls-Profil-ID)
 *
 * Kette: Schema-Provider (SchemeRegistry) -> Extension Point
 * LINKMAP_RESOLVE_URL -> url_template aus der Tabellenkonfiguration.
 *
 * Altformate (news://5, rex-news://5, rex_news://5) werden nur ueber
 * normalizeLegacy() verstanden, siehe Einstellung "Altformate aufloesen".
 */
final class LinkResolver
{
    public const SCHEME = 'yform';

    /**
     * @return array{table: string, id: int, scheme: string|null}|null
     */
    public static function parse(string $link): ?array
    {
        if (!preg_match('~^' . self::SCHEME . '://([a-z0-9_]+)/(\d+)(?:\?scheme=([a-z0-9_]+:[^&\s"\']+))?$~i', trim($link), $m)) {
            return null;
        }
        return [
            'table' => $m[1],
            'id' => (int) $m[2],
            'scheme' => isset($m[3]) ? rawurldecode($m[3]) : null,
        ];
    }

    public static function build(string $table, int $id, ?string $scheme = null): string
    {
        return self::SCHEME . '://' . $table . '/' . $id . (null !== $scheme && '' !== $scheme ? '?scheme=' . rawurlencode($scheme) : '');
    }

    public static function isLink(string $link): bool
    {
        return null !== self::parse($link);
    }

    /** Frontend-URL fuer einen yform://-Link (oder Altformat, wenn aktiviert). */
    public static function url(string $link, ?int $clang = null): ?string
    {
        $parsed = self::parse($link) ?? self::parse((string) self::normalizeLegacy($link));
        if (null === $parsed) {
            return null;
        }
        return self::urlFor($parsed['table'], $parsed['id'], $parsed['scheme'], $clang ?? rex_clang::getCurrentId());
    }

    public static function urlFor(string $table, int $id, ?string $schemeId, int $clang): ?string
    {
        if ($id <= 0 || '' === $table) {
            return null;
        }

        if (null !== $schemeId) {
            $scheme = SchemeRegistry::getScheme($table, $schemeId);
            $url = null !== $scheme ? self::urlViaScheme($scheme, $id, $clang) : null;
            if (null !== $url) {
                return $url;
            }
        }

        foreach (self::rankedSchemes($table, $clang) as $scheme) {
            $url = self::urlViaScheme($scheme, $id, $clang);
            if (null !== $url) {
                return $url;
            }
        }

        /** @var mixed $url */
        $url = rex_extension::registerPoint(new rex_extension_point('LINKMAP_RESOLVE_URL', '', [
            'table' => $table,
            'id' => $id,
            'scheme' => $schemeId,
            'clang' => $clang,
        ]));
        if (is_string($url) && '' !== $url) {
            return $url;
        }

        return self::urlViaTemplate($table, $id, $clang);
    }

    /**
     * Alle Schemata der Tabelle, die fuer diesen Datensatz eine URL liefern --
     * Grundlage fuer die Schema-Auswahl im Picker.
     *
     * @return list<array{scheme: string, label: string, url: string, preferred: bool}>
     */
    public static function candidates(string $table, int $id, int $clang): array
    {
        $result = [];
        $first = true;
        foreach (self::rankedSchemes($table, $clang) as $scheme) {
            $url = self::urlViaScheme($scheme, $id, $clang);
            if (null === $url) {
                continue;
            }
            $result[] = ['scheme' => $scheme->id(), 'label' => $scheme->label, 'url' => $url, 'preferred' => $first];
            $first = false;
        }
        if ([] === $result) {
            $url = self::urlViaTemplate($table, $id, $clang);
            if (null !== $url) {
                $result[] = ['scheme' => '', 'label' => \rex_i18n::msg('linkmap_scheme_url_template'), 'url' => $url, 'preferred' => true];
            }
        }
        return $result;
    }

    /** Ob fuer die Tabelle ueberhaupt ein Schema oder ein URL-Template existiert. */
    public static function tableIsLinkable(string $table): bool
    {
        if ([] !== SchemeRegistry::getSchemes($table)) {
            return true;
        }
        $config = TableConfig::get($table);
        return null !== $config && '' !== trim((string) ($config['url_template'] ?? ''));
    }

    /**
     * Ersetzt href="yform://..." in HTML durch aufgeloeste URLs (Frontend-
     * OUTPUT_FILTER). Nicht aufloesbare Links werden zu "#" mit
     * data-linkmap-unresolved, damit der Browser nicht auf yform:// springt.
     */
    public static function replaceInHtml(string $html, int $clang): string
    {
        if (!str_contains($html, '://')) {
            return $html;
        }
        $legacy = (bool) rex_config::get('linkmap', 'resolve_legacy_links', false);
        $pattern = $legacy
            ? '~(href=)(["\'])([a-z0-9_-]+://[^"\']+)\2~i'
            : '~(href=)(["\'])(' . self::SCHEME . '://[^"\']+)\2~i';

        return (string) preg_replace_callback($pattern, static function (array $m) use ($clang): string {
            $raw = html_entity_decode($m[3], ENT_QUOTES);
            $parsed = self::parse($raw) ?? self::parse((string) self::normalizeLegacy($raw));
            if (null === $parsed) {
                return $m[0];
            }
            $url = self::urlFor($parsed['table'], $parsed['id'], $parsed['scheme'], $clang);
            if (null === $url) {
                return $m[1] . $m[2] . '#' . $m[2] . ' data-linkmap-unresolved="' . rex_escape($raw) . '"';
            }
            return $m[1] . $m[2] . rex_escape($url) . $m[2];
        }, $html);
    }

    /**
     * Altformate anderer Addons -> kanonischer Link:
     *   news://5        (url-Addon-Namespace "news" oder Tabelle rex_news)
     *   rex-news://5    (TinyMCE/MForm: Tabellenname mit Bindestrichen)
     *   rex_news://5    (CKE5-Default: Tabellenname)
     */
    public static function normalizeLegacy(string $link): ?string
    {
        if (!preg_match('~^([a-z0-9_-]+)://(\d+)$~i', trim($link), $m)) {
            return null;
        }
        $alias = strtolower($m[1]);
        $id = (int) $m[2];
        if (self::SCHEME === $alias || 'redaxo' === $alias || $id <= 0) {
            return null;
        }

        // url-Addon-Namespace
        if (rex_addon::get('url')->isAvailable() && class_exists(Profile::class)) {
            $profiles = array_values((array) Profile::getByNamespace($alias));
            $profile = $profiles[0] ?? null;
            if ($profile instanceof Profile) {
                return self::build($profile->getTableName(), $id, 'url:' . $profile->getNamespace());
            }
        }

        // Tabellenname (mit/ohne Bindestrich-Ersetzung, mit/ohne rex_-Praefix)
        $tables = array_keys(rex_yform_manager_table::getAll());
        foreach ([$alias, str_replace('-', '_', $alias), 'rex_' . $alias, 'rex_' . str_replace('-', '_', $alias)] as $candidate) {
            if (in_array($candidate, $tables, true)) {
                return self::build($candidate, $id);
            }
        }

        return null;
    }

    /**
     * Schemata der Tabelle in Praeferenz-Reihenfolge: passende Sprache und
     * aktuelle Domain zuerst, danach domainunabhaengige, danach der Rest.
     *
     * @return list<Scheme>
     */
    private static function rankedSchemes(string $table, int $clang): array
    {
        $currentDomain = '';
        if (rex_addon::get('yrewrite')->isAvailable()) {
            $domainObj = rex_yrewrite::getCurrentDomain();
            $currentDomain = null !== $domainObj && 'default' !== $domainObj->getName() ? (string) $domainObj->getName() : '';
        }

        // Freigabe pro Tabelle (Einstellungen -> Datensatz-Quellen): nur die dort
        // angehakten Schemata; ohne Angabe alle. Ein explizit im Link genanntes
        // Schema (?scheme=...) bleibt davon unberuehrt (urlFor()).
        $allowed = TableConfig::allowedSchemes($table);
        $schemes = array_values(array_filter(SchemeRegistry::getSchemes($table), static fn (Scheme $s): bool => $s->supportsClang($clang) && ([] === $allowed || in_array($s->id(), $allowed, true))));
        usort($schemes, static function (Scheme $a, Scheme $b) use ($currentDomain): int {
            $rank = static function (Scheme $s) use ($currentDomain): int {
                if ('' !== $currentDomain && $s->domain === $currentDomain) {
                    return 0;
                }
                if ('' === $s->domain) {
                    return 1;
                }
                return 2;
            };
            return $rank($a) <=> $rank($b);
        });
        return $schemes;
    }

    private static function urlViaScheme(Scheme $scheme, int $id, int $clang): ?string
    {
        $provider = SchemeRegistry::getProvider($scheme->provider);
        if (null === $provider) {
            return null;
        }
        try {
            return $provider->getUrl($scheme, $id, $clang);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function urlViaTemplate(string $table, int $id, int $clang): ?string
    {
        $config = TableConfig::get($table);
        $template = trim((string) ($config['url_template'] ?? ''));
        if ('' === $template) {
            return null;
        }
        if (!rex_addon::get('yform')->isAvailable() || null === rex_yform_manager_table::get($table)) {
            return null;
        }
        $dataset = rex_yform_manager_dataset::get($id, $table);
        if (null === $dataset) {
            return null;
        }
        $url = preg_replace_callback('~\{([a-z0-9_]+)\}~i', static function (array $m) use ($dataset, $id, $clang): string {
            if ('id' === $m[1]) {
                return (string) $id;
            }
            if ('clang' === $m[1]) {
                return (string) $clang;
            }
            return $dataset->hasValue($m[1]) ? rawurlencode((string) $dataset->getValue($m[1])) : '';
        }, $template);
        return is_string($url) && '' !== $url ? $url : null;
    }
}
