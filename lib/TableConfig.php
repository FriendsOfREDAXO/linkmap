<?php

namespace FriendsOfRedaxo\Linkmap;

use rex_config;

use function is_array;

/**
 * Konfiguration der YForm-Tabellen, die im Picker als Datensatz-Quelle
 * freigegeben sind (rex_config linkmap/yform_tables, Einstellungsseite).
 *
 * Pro Tabelle:
 *   enabled      bool
 *   label        Label-Template, z.B. "{title} ({date})"; leer = erste Textspalte
 *   search       kommaseparierte Suchspalten; leer = YForm-"durchsuchbar"-Felder
 *   columns      Spalten der Listenansicht; leer = im Table Manager sichtbare Felder
 *   order        ORDER BY, z.B. "date DESC"
 *   filter       zusaetzliche WHERE-Klausel, z.B. "status = 1"
 *   clang_field  Spalte mit Sprach-ID, leer = sprachunabhaengig
 *   url_template Fallback-URL ohne Schema, z.B. "/news/{id}" (Platzhalter {spalte})
 *   schemes      erlaubte URL-Schemata (Kennungen wie "url:news,vu:3"); leer = alle
 */
final class TableConfig
{
    private const KEY = 'yform_tables';

    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        $stored = rex_config::get('linkmap', self::KEY);
        return is_array($stored) ? $stored : [];
    }

    /** @return array<string, mixed>|null */
    public static function get(string $table): ?array
    {
        return self::all()[$table] ?? null;
    }

    public static function isEnabled(string $table): bool
    {
        $config = self::get($table);
        return null !== $config && !empty($config['enabled']);
    }

    /**
     * Erlaubte Schema-Kennungen einer Tabelle; leer = alle Schemata erlaubt.
     *
     * @return list<string>
     */
    public static function allowedSchemes(string $table): array
    {
        $raw = (string) (self::get($table)['schemes'] ?? '');
        return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $s): bool => '' !== $s));
    }

    /** @return list<string> */
    public static function enabledTables(): array
    {
        $tables = [];
        foreach (self::all() as $table => $config) {
            if (!empty($config['enabled'])) {
                $tables[] = (string) $table;
            }
        }
        return $tables;
    }

    /** @param array<string, array<string, mixed>> $config */
    public static function save(array $config): void
    {
        rex_config::set('linkmap', self::KEY, $config);
    }
}
