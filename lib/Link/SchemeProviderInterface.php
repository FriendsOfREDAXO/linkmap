<?php

namespace FriendsOfRedaxo\Linkmap\Link;

/**
 * Liefert URL-Schemata fuer YForm-Tabellen und loest Datensaetze darueber
 * zu Frontend-URLs auf. Eingebaut: url-Addon und virtual_urls (lib/Link/
 * Provider). Andere Addons registrieren eigene Provider ueber
 * SchemeRegistry::register() in ihrer boot.php.
 */
interface SchemeProviderInterface
{
    /** Kurz-ID, Praefix im Schema-Bezeichner (z.B. "url", "vu"). */
    public function getId(): string;

    public function getLabel(): string;

    /**
     * Alle Schemata, die dieser Provider fuer die Tabelle kennt.
     *
     * @return list<Scheme>
     */
    public function getSchemes(string $table): array;

    /**
     * Frontend-URL des Datensatzes ueber genau dieses Schema, null wenn der
     * Datensatz dort keine URL hat (z.B. Restriktion greift, Slug fehlt).
     */
    public function getUrl(Scheme $scheme, int $datasetId, int $clang): ?string;
}
