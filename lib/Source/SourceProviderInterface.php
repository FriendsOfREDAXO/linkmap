<?php

namespace FriendsOfRedaxo\Linkmap\Source;

/**
 * Eine Datensatz-Quelle fuer den Picker (neben der Artikelstruktur):
 * liefert "Container" (bei YForm: Tabellen), deren Eintraege durchsuchbar
 * sind und als Link "<schema>://..." ausgewaehlt werden. Eingebaut: YForm
 * (YFormSourceProvider); andere Addons registrieren eigene Quellen ueber
 * SourceRegistry::register() in ihrer boot.php.
 *
 * Item-Form (alle Endpunkte und das Overlay sprechen nur diese Struktur):
 *   id (int), link (string), name, label, meta (string), online (bool),
 *   status (int|null), source (Provider-ID), container (Container-ID),
 *   containerLabel, linkable (bool)
 */
interface SourceProviderInterface
{
    public function getId(): string;

    public function getLabel(): string;

    public function getIcon(): string;

    /**
     * Container, die der eingeloggte User sehen darf.
     *
     * addUrl (optional): Backend-URL zum Anlegen eines Eintrags (neues Fenster).
     *
     * @return list<array{id: string, label: string, icon: string, linkable: bool, addUrl?: string}>
     */
    public function getContainers(): array;

    /**
     * columns beschreibt die Tabellenspalten der Listenansicht (key, label,
     * sortable); jedes Item traegt dazu cells[key] => Anzeigewert.
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, pages: int, columns: list<array{key: string, label: string, sortable: bool}>, sort: string, dir: string}
     */
    public function browse(string $container, string $query, int $page, int $clang, string $sort = '', string $dir = 'asc'): array;

    /**
     * Item zu einem Link dieses Providers (fuer Widget-Anzeige), null wenn
     * unbekannt oder nicht erlaubt.
     *
     * @return array<string, mixed>|null
     */
    public function resolve(string $link, int $clang): ?array;

    /** Ob der Link zu diesem Provider gehoert. */
    public function ownsLink(string $link): bool;

    /**
     * Ob der eingeloggte User den Container sehen darf -- unabhaengig von
     * der Freigabe als Quelle (Relation-Felder adressieren Tabellen direkt).
     */
    public function hasContainerAccess(string $container): bool;
}
