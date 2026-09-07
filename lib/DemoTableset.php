<?php

namespace FriendsOfRedaxo\Linkmap;

use FriendsOfRedaxo\VirtualUrl\VirtualUrlsHelper;
use rex;
use rex_addon;
use rex_article;
use rex_clang;
use rex_sql;
use rex_yform_manager_table;
use rex_yform_manager_table_api;
use Url\Cache;
use Url\Profile;
use Url\UrlManagerSql;

use function count;
use function in_array;

/**
 * Optionales YForm-Demo-Tableset fuer die Demo-Seite: eine Tabelle
 * "REDAXO-News" mit Beispielmeldungen rund um REDAXO. Wird nur auf Wunsch
 * installiert (Demo-Seite -> Button) und laesst sich dort wieder entfernen.
 * Das Addon selbst legt bei der Installation keine Tabellen an.
 *
 * Beim Installieren wird die Tabelle als Datensatz-Quelle freigegeben
 * (TableConfig). Die URL-Aufloesung waehlt der Admin beim Installieren:
 *   vu       zwei virtual_urls-Profile (Trigger "redaxo-news"/"redaxo-blog")
 *   url      zwei url-Addon-Profile (Namespaces "linkmap-demo-news"/"-blog")
 *   template nur ein URL-Template -- ohne url/virtual_urls die einzige Wahl,
 *            dann zeigt die Demo keine Schema-Auswahl
 * Zwei Profile, damit der Schema-Dialog bei mehreren URL-Profilen sichtbar
 * wird. Uninstall raeumt alles wieder weg.
 */
final class DemoTableset
{
    public const TABLE = 'rex_linkmap_demo';
    public const CATEGORY_TABLE = 'rex_linkmap_demo_category';
    public const CATEGORIES = ['Release', 'AddOn', 'Community', 'Tutorial'];
    public const TRIGGERS = ['redaxo-news', 'redaxo-blog'];
    public const URL_NAMESPACES = ['linkmap-demo-news', 'linkmap-demo-blog'];
    public const RESOLVERS = ['vu', 'url', 'template'];

    public static function isAvailable(): bool
    {
        return rex_addon::get('yform')->isAvailable() && class_exists(rex_yform_manager_table_api::class);
    }

    public static function isInstalled(): bool
    {
        return self::isAvailable() && null !== rex_yform_manager_table::get(self::TABLE);
    }

    /**
     * Verfuegbare Aufloesungen in Praeferenz-Reihenfolge (erste = Default).
     *
     * @return list<string>
     */
    public static function availableResolvers(): array
    {
        $resolvers = [];
        if (self::hasVirtualUrls()) {
            $resolvers[] = 'vu';
        }
        if (self::hasUrlAddon()) {
            $resolvers[] = 'url';
        }
        $resolvers[] = 'template';
        return $resolvers;
    }

    /** Aktive Aufloesung des installierten Tablesets ('vu', 'url', 'template' oder '' wenn nicht installiert). */
    public static function activeResolver(): string
    {
        if (!self::isInstalled()) {
            return '';
        }
        if (self::countVirtualUrlProfiles() > 0) {
            return 'vu';
        }
        if (self::countUrlProfiles() > 0) {
            return 'url';
        }
        return 'template';
    }

    public static function install(string $resolver = ''): void
    {
        if (!self::isAvailable() || self::isInstalled()) {
            return;
        }
        $available = self::availableResolvers();
        if (!in_array($resolver, $available, true)) {
            $resolver = $available[0];
        }

        rex_yform_manager_table_api::importTablesets((string) json_encode([[
            'table' => [
                'table_name' => self::CATEGORY_TABLE,
                'name' => 'Linkmap Demo: Rubriken',
                'description' => 'Rubriken der Demo-Tabelle des Linkmap-Addons (Relation).',
                'status' => 1,
                'list_amount' => 30,
                'list_sortfield' => 'name',
                'list_sortorder' => 'ASC',
                'search' => 0,
                'hidden' => 0,
                'export' => 1,
                'import' => 1,
                'mass_deletion' => 1,
                'mass_edit' => 0,
                'history' => 0,
                'schema_overwrite' => 1,
            ],
            'fields' => [
                ['type_id' => 'value', 'type_name' => 'text', 'name' => 'name', 'label' => 'Rubrik', 'db_type' => 'varchar(191)', 'list_hidden' => 0, 'search' => 1, 'prio' => 1],
            ],
        ], [
            'table' => [
                'table_name' => self::TABLE,
                'name' => 'Linkmap Demo: REDAXO-News',
                'description' => 'Demo-Tabelle des Linkmap-Addons. Kann unter System → Linkmap → Demo wieder entfernt werden.',
                'status' => 1,
                'list_amount' => 30,
                'list_sortfield' => 'date',
                'list_sortorder' => 'DESC',
                'search' => 1,
                'hidden' => 0,
                'export' => 1,
                'import' => 1,
                'mass_deletion' => 1,
                'mass_edit' => 0,
                'history' => 0,
                'schema_overwrite' => 1,
            ],
            'fields' => [
                ['type_id' => 'value', 'type_name' => 'text', 'name' => 'title', 'label' => 'Titel', 'db_type' => 'varchar(191)', 'list_hidden' => 0, 'search' => 1, 'prio' => 1],
                ['type_id' => 'value', 'type_name' => 'textarea', 'name' => 'teaser', 'label' => 'Teaser', 'db_type' => 'text', 'list_hidden' => 1, 'search' => 1, 'prio' => 2],
                // Relation auf die Rubriken-Tabelle: der Picker zeigt den Namen, nicht die ID
                ['type_id' => 'value', 'type_name' => 'be_manager_relation', 'name' => 'category', 'label' => 'Rubrik', 'table' => self::CATEGORY_TABLE, 'field' => 'name', 'type' => 0, 'empty_option' => 1, 'db_type' => 'int', 'list_hidden' => 0, 'search' => 0, 'prio' => 3],
                ['type_id' => 'value', 'type_name' => 'date', 'name' => 'date', 'label' => 'Datum', 'db_type' => 'date', 'list_hidden' => 0, 'search' => 0, 'prio' => 4],
                ['type_id' => 'value', 'type_name' => 'datetime', 'name' => 'published', 'label' => 'Veröffentlicht', 'db_type' => 'datetime', 'list_hidden' => 0, 'search' => 0, 'prio' => 5],
                ['type_id' => 'value', 'type_name' => 'choice', 'name' => 'status', 'label' => 'Status', 'choices' => '{"offline":0,"online":1}', 'db_type' => 'int', 'list_hidden' => 0, 'search' => 0, 'prio' => 6],
            ],
        ]]));
        rex_yform_manager_table::deleteCache();

        $categoryIds = [];
        foreach (self::CATEGORIES as $category) {
            $sql = rex_sql::factory()->setTable(self::CATEGORY_TABLE)->setValue('name', $category);
            $sql->insert();
            $categoryIds[$category] = (int) $sql->getLastId();
        }
        foreach (self::rows() as $row) {
            rex_sql::factory()->setTable(self::TABLE)
                ->setValue('title', $row[0])
                ->setValue('teaser', $row[1])
                ->setValue('category', $categoryIds[$row[2]] ?? 0)
                ->setValue('date', $row[3])
                ->setValue('published', $row[3] . ' ' . $row[5])
                ->setValue('status', $row[4])
                ->insert();
        }

        $config = TableConfig::all();
        $config[self::TABLE] = [
            'enabled' => true,
            'label' => '{title}',
            'search' => 'title,teaser',
            'columns' => 'category,date,published,status',
            'order' => 'date DESC',
            'filter' => '',
            'clang_field' => '',
            'url_template' => 'template' === $resolver ? '/redaxo-news/{id}' : '',
        ];
        TableConfig::save($config);

        if ('vu' === $resolver) {
            self::installVirtualUrlProfiles();
        } elseif ('url' === $resolver) {
            self::installUrlAddonProfiles();
        }
    }

    public static function uninstall(): void
    {
        if (!self::isAvailable()) {
            return;
        }
        foreach ([self::TABLE, self::CATEGORY_TABLE] as $tableName) {
            if (null !== rex_yform_manager_table::get($tableName)) {
                rex_yform_manager_table_api::removeTable($tableName);
            }
            rex_sql::factory()->setQuery('DROP TABLE IF EXISTS ' . rex_sql::factory()->escapeIdentifier($tableName));
        }
        rex_yform_manager_table::deleteCache();

        $config = TableConfig::all();
        unset($config[self::TABLE], $config[self::CATEGORY_TABLE]);
        TableConfig::save($config);

        if (self::hasVirtualUrls()) {
            rex_sql::factory()->setQuery('DELETE FROM ' . rex::getTable('virtual_urls_profiles') . ' WHERE table_name = ?', [self::TABLE]);
            VirtualUrlsHelper::clearCache();
        }
        if (self::hasUrlAddon()) {
            $sql = rex_sql::factory();
            $ids = array_map(static fn (array $row): int => (int) $row['id'], $sql->getArray('SELECT id FROM ' . rex::getTable(Profile::TABLE_NAME) . ' WHERE namespace IN (?, ?)', self::URL_NAMESPACES));
            if ([] !== $ids) {
                $sql->setQuery('DELETE FROM ' . rex::getTable(UrlManagerSql::TABLE_NAME) . ' WHERE profile_id IN (' . implode(',', $ids) . ')');
                $sql->setQuery('DELETE FROM ' . rex::getTable(Profile::TABLE_NAME) . ' WHERE id IN (' . implode(',', $ids) . ')');
            }
            Cache::deleteProfiles();
            Profile::reset();
        }
    }

    public static function hasVirtualUrls(): bool
    {
        return rex_addon::get('virtual_urls')->isAvailable() && class_exists(VirtualUrlsHelper::class);
    }

    public static function hasUrlAddon(): bool
    {
        return rex_addon::get('url')->isAvailable() && class_exists(Profile::class);
    }

    public static function countUrlProfiles(): int
    {
        if (!self::hasUrlAddon()) {
            return 0;
        }
        return count(rex_sql::factory()->getArray('SELECT id FROM ' . rex::getTable(Profile::TABLE_NAME) . ' WHERE namespace IN (?, ?)', self::URL_NAMESPACES));
    }

    /**
     * Zwei url-Addon-Profile: Segment = Titel, Ziel Start- bzw. 404-Artikel.
     * Aufbau der table_parameters wie vom Profil-Formular des url-Addons
     * gespeichert (siehe Url\Profile::normalize()).
     */
    private static function installUrlAddonProfiles(): void
    {
        if (self::countUrlProfiles() > 0) {
            return;
        }
        [$start, $second] = self::targetArticles();
        $parameters = [
            'column_id' => 'id', 'column_clang_id' => '',
            'restriction_1_column' => '', 'restriction_1_comparison_operator' => '=', 'restriction_1_value' => '',
            'restriction_2_logical_operator' => '', 'restriction_2_column' => '', 'restriction_2_comparison_operator' => '=', 'restriction_2_value' => '',
            'restriction_3_logical_operator' => '', 'restriction_3_column' => '', 'restriction_3_comparison_operator' => '=', 'restriction_3_value' => '',
            'column_segment_part_1' => 'title', 'column_segment_part_2_separator' => '/', 'column_segment_part_2' => '', 'column_segment_part_3_separator' => '/', 'column_segment_part_3' => '',
            'relation_1_column' => '', 'relation_1_position' => 'BEFORE', 'relation_2_column' => '', 'relation_2_position' => 'BEFORE', 'relation_3_column' => '', 'relation_3_position' => 'BEFORE',
            'append_user_paths' => '', 'append_structure_categories' => '0',
            'column_seo_title' => 'title', 'column_seo_description' => 'teaser', 'column_seo_image' => '',
            'sitemap_add' => '0', 'sitemap_frequency' => 'weekly', 'sitemap_priority' => '0.5', 'column_sitemap_lastmod' => '',
        ];
        $user = rex::getUser()?->getLogin() ?? 'linkmap';
        // Zweites Profil mit anderem URL-Aufbau (/datum/titel/): zeigen beide
        // auf denselben Artikel, waeren die URLs sonst identisch und das
        // url-Addon verwirft die zweite wegen des Unique-Keys auf url_hash.
        $blogParameters = ['column_segment_part_1' => 'date', 'column_segment_part_2' => 'title'] + $parameters;
        foreach ([[self::URL_NAMESPACES[0], $start, $parameters], [self::URL_NAMESPACES[1], $second, $blogParameters]] as [$namespace, $articleId, $profileParameters]) {
            rex_sql::factory()->setTable(rex::getTable(Profile::TABLE_NAME))
                ->setValue('namespace', $namespace)
                ->setValue('article_id', $articleId)
                // Startsprache statt "alle Sprachen" (0): ohne Sprachspalte in der
                // Tabelle wuerde das url-Addon clang_id = NULL speichern wollen und
                // die URL-Zeilen stillschweigend verwerfen.
                ->setValue('clang_id', rex_clang::getStartId())
                ->setValue('ep_pre_save_called', 0)
                ->setValue('table_name', '1_xxx_' . self::TABLE)
                ->setValue('table_parameters', json_encode($profileParameters))
                ->setValue('relation_1_table_name', '')->setValue('relation_1_table_parameters', '[]')
                ->setValue('relation_2_table_name', '')->setValue('relation_2_table_parameters', '[]')
                ->setValue('relation_3_table_name', '')->setValue('relation_3_table_parameters', '[]')
                ->setValue('createdate', date('Y-m-d H:i:s'))->setValue('createuser', $user)
                ->setValue('updatedate', date('Y-m-d H:i:s'))->setValue('updateuser', $user)
                ->insert();
        }
        // Url\Profile liest aus profiles.cache -- ohne Loeschen der Datei
        // bleiben per SQL angelegte Profile unsichtbar.
        Cache::deleteProfiles();
        Profile::reset();
        foreach (Profile::getAll() as $profile) {
            if (in_array($profile->getNamespace(), self::URL_NAMESPACES, true)) {
                $profile->buildUrls();
            }
        }
    }

    /**
     * Zwei unterschiedliche Renderer-Artikel: Startartikel und 404-Artikel,
     * oder zweimal der Startartikel, wenn es nur einen gibt.
     *
     * @return array{int, int}
     */
    private static function targetArticles(): array
    {
        $start = rex_article::getSiteStartArticleId();
        $second = rex_article::getNotfoundArticleId();
        if ($second <= 0 || null === rex_article::get($second)) {
            $second = $start;
        }
        return [$start, $second];
    }

    /** Anzahl der virtual_urls-Profile der Demo-Tabelle (fuer den Hinweis auf der Demo-Seite). */
    public static function countVirtualUrlProfiles(): int
    {
        if (!self::hasVirtualUrls()) {
            return 0;
        }
        return count(rex_sql::factory()->getArray('SELECT id FROM ' . rex::getTable('virtual_urls_profiles') . ' WHERE table_name = ?', [self::TABLE]));
    }

    private static function installVirtualUrlProfiles(): void
    {
        $sql = rex_sql::factory();
        $existing = (int) ($sql->getArray('SELECT COUNT(*) c FROM ' . rex::getTable('virtual_urls_profiles') . ' WHERE table_name = ?', [self::TABLE])[0]['c'] ?? 0);
        if ($existing > 0) {
            return;
        }

        [$start, $second] = self::targetArticles();
        foreach ([[self::TRIGGERS[0], $start], [self::TRIGGERS[1], $second]] as [$trigger, $articleId]) {
            rex_sql::factory()->setTable(rex::getTable('virtual_urls_profiles'))
                ->setValue('status', 1)
                ->setValue('clang_id', -1)
                ->setValue('domain', '')
                ->setValue('table_name', self::TABLE)
                ->setValue('trigger_segment', $trigger)
                ->setValue('url_field', 'title')
                ->setValue('article_id', $articleId)
                ->setValue('relation_field', '')
                ->setValue('relation_table', '')
                ->setValue('relation_slug_field', '')
                ->setValue('sitemap_filter', 'status = 1')
                ->setValue('sitemap_changefreq', 'weekly')
                ->setValue('sitemap_priority', '0.5')
                ->setValue('seo_title_field', 'title')
                ->setValue('seo_description_field', 'teaser')
                ->setValue('seo_image_field', '')
                ->insert();
        }
        VirtualUrlsHelper::clearCache();
    }

    /**
     * Beispielmeldungen rund um REDAXO (fiktive Daten).
     *
     * @return list<array{string, string, string, string, int, string}>
     */
    private static function rows(): array
    {
        return [
            ['REDAXO 5.21 veröffentlicht', 'Neue Core-Version mit PHP-8.4-Support und überarbeiteter Medienverwaltung.', 'Release', '2026-08-04', 1, '09:30:00'],
            ['YForm 5: Table Manager mit neuem Feldtyp linkmap', 'Artikel und Datensätze im Overlay auswählen, gespeichert wird wie bei be_link.', 'AddOn', '2026-08-11', 1, '11:15:00'],
            ['FriendsOfREDAXO-Treffen: Termine für den Herbst', 'Stammtische in Köln, Hamburg und Berlin, online dazu.', 'Community', '2026-08-18', 1, '14:00:00'],
            ['MediaPlace 2.0: Medienpool als Overlay', 'Drag-and-drop, Tags, Sammlungen und KI-Alt-Texte.', 'AddOn', '2026-08-25', 1, '10:45:00'],
            ['Tutorial: Multi-Domain mit YRewrite', 'Domains, Sprachen und Startartikel in zehn Minuten eingerichtet.', 'Tutorial', '2026-08-29', 1, '16:20:00'],
            ['Entwurf: Roadmap REDAXO 6', 'Interne Sammlung, noch nicht freigegeben.', 'Release', '2026-09-01', 0, '08:05:00'],
            ['CKEditor 5 nutzt jetzt die Linkmap für Datensätze', 'Ein Picker für Artikel und YForm-Tabellen, keine eigene Linklösung mehr.', 'AddOn', '2026-09-03', 1, '12:30:00'],
            ['Barrierefreiheit: Backend-Checkliste', 'Kontraste, Tastaturbedienung und Alt-Texte im Redaktionsalltag.', 'Tutorial', '2026-09-05', 1, '15:10:00'],
            ['Community-Umfrage 2026 gestartet', 'Welche AddOns nutzt ihr, was fehlt euch?', 'Community', '2026-09-08', 1, '09:00:00'],
            ['virtual_urls 1.2: rex_getUrl() für Datensätze', 'Trigger-Parameter und domainbewusste Profile.', 'AddOn', '2026-09-10', 1, '13:45:00'],
            ['Wartungsfenster redaxo.org am Wochenende', 'Downloads und Installer kurz nicht erreichbar.', 'Community', '2026-09-12', 0, '18:00:00'],
            ['REDAXO Camp: Call for Papers', 'Vorträge und Workshops für das Frühjahr gesucht.', 'Community', '2026-09-14', 1, '10:30:00'],
        ];
    }
}
