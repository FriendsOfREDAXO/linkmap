<?php

namespace FriendsOfRedaxo\Linkmap;

use rex_i18n;
use rex_string;
use rex_yform_manager_table;

use function in_array;
use function is_array;

/**
 * Serverseitiger Renderer fuer das Linkmap-Widget (<input class="lm-widget">).
 *
 * Gemeinsame Basis fuer alle Addons, die eine Artikelauswahl brauchen
 * (YForm-Werttyp, MForm, Builder, eigene Module): ein Aufruf liefert das
 * fertige Input, linkmap_widget.js macht daraus im Backend die Auswahl mit
 * Namensanzeige. Der Wert bleibt die Artikel-ID (bei multiple kommasepariert),
 * also dasselbe Format wie REX_LINK/REX_LINKLIST und be_link.
 *
 * Optionen: multiple (bool), max (int), category (int), clang (int),
 * domain (string), format ('id'|'link'), sources ('all'|list<string>),
 * id (string), class (string), attributes (array).
 *
 * format=link speichert Links ("redaxo://12", "yform://rex_news/3") statt
 * IDs -- nur damit sind Datensatz-Quellen (sources) moeglich.
 *
 * categories=true schaltet den Kategorie-Picker: nur Kategorien waehlbar,
 * Wert = ID des Startartikels (identisch mit der Kategorie-ID).
 *
 * table=<yform-tabelle> schaltet den Relation-Modus: nur Datensaetze dieser
 * Tabelle, Wert = Datensatz-ID(s), keine Struktur, keine URL-Aufloesung
 * noetig (wie be_manager_relation, nur mit dem Overlay).
 */
final class Widget
{
    /**
     * @param array{multiple?: bool, max?: int, category?: int, clang?: int, domain?: string, format?: string, sources?: string|list<string>, table?: string, categories?: bool, id?: string, class?: string, attributes?: array<string, string|int>} $options
     */
    public static function render(string $name, string|int|null $value, array $options = []): string
    {
        $table = trim((string) ($options['table'] ?? ''));
        $linkFormat = '' === $table && 'link' === ($options['format'] ?? 'id');
        $attributes = [
            'class' => trim('lm-widget form-control ' . (string) ($options['class'] ?? '')),
            'name' => $name,
            'value' => $linkFormat
                ? self::normalizeLinks($value, (bool) ($options['multiple'] ?? false))
                : self::normalizeValue($value, (bool) ($options['multiple'] ?? false)),
        ];
        if (!empty($options['categories']) && '' === $table) {
            $attributes['data-lm-categories'] = 'true';
        }
        if ('' !== $table) {
            $attributes['data-lm-table'] = $table;
            $yformTable = class_exists(rex_yform_manager_table::class) ? rex_yform_manager_table::get($table) : null;
            $attributes['data-lm-table-label'] = null !== $yformTable ? rex_i18n::translate($yformTable->getName()) : $table;
        }
        if ($linkFormat) {
            $attributes['data-lm-format'] = 'link';
            $sources = $options['sources'] ?? [];
            $attributes['data-lm-sources'] = is_array($sources) ? implode(',', $sources) : (string) $sources;
        }
        if (!empty($options['id'])) {
            $attributes['id'] = (string) $options['id'];
        }
        if (!empty($options['multiple'])) {
            $attributes['data-lm-multiple'] = 'true';
        }
        if (!empty($options['max'])) {
            $attributes['data-lm-max'] = (int) $options['max'];
        }
        if (!empty($options['category'])) {
            $attributes['data-lm-category'] = (int) $options['category'];
        }
        if (!empty($options['clang'])) {
            $attributes['data-lm-clang'] = (int) $options['clang'];
        }
        if (!empty($options['domain'])) {
            $attributes['data-lm-domain'] = (string) $options['domain'];
        }
        foreach ($options['attributes'] ?? [] as $key => $attributeValue) {
            $attributes[(string) $key] = (string) $attributeValue;
        }

        return '<input' . rex_string::buildAttributes($attributes) . '>';
    }

    /**
     * Bereinigt Link-Werte (format=link): redaxo://ID oder <schema>://... ohne
     * Whitespace/Komma, ohne Duplikate, bei Einzelauswahl nur der erste.
     */
    public static function normalizeLinks(string|int|null $value, bool $multiple): string
    {
        $links = [];
        foreach (explode(',', (string) $value) as $part) {
            $part = trim($part);
            if ('' === $part) {
                continue;
            }
            if (ctype_digit($part)) {
                $part = 'redaxo://' . (int) $part;
            }
            if (preg_match('~^(redaxo://\d+|[a-z0-9_]+://[^\s,]+)$~i', $part) && !in_array($part, $links, true)) {
                $links[] = $part;
            }
        }
        if (!$multiple) {
            $links = array_slice($links, 0, 1);
        }
        return implode(',', $links);
    }

    /**
     * Bereinigt einen gespeicherten Wert: nur positive IDs, ohne Duplikate,
     * bei Einzelauswahl nur die erste. Existenz der Artikel prueft das Widget
     * clientseitig (LM.resolve), Konsumenten mit Persistenz sollten sie beim
     * Speichern selbst pruefen (siehe rex_yform_value_linkmap::enterObject()).
     */
    public static function normalizeValue(string|int|null $value, bool $multiple): string
    {
        $ids = [];
        foreach (explode(',', (string) $value) as $part) {
            $id = (int) trim($part);
            if ($id > 0 && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        if (!$multiple) {
            $ids = array_slice($ids, 0, 1);
        }
        return implode(',', $ids);
    }
}
