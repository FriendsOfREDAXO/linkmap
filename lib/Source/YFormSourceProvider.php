<?php

namespace FriendsOfRedaxo\Linkmap\Source;

use FriendsOfRedaxo\Linkmap\Link\LinkResolver;
use FriendsOfRedaxo\Linkmap\TableConfig;
use rex;
use rex_addon;
use rex_csrf_token;
use rex_i18n;
use rex_sql;
use rex_url;
use rex_yform_manager_dataset;
use rex_yform_manager_query;
use rex_yform_manager_table;
use rex_yform_manager_table_perm_edit;
use rex_yform_manager_table_perm_view;

use function count;
use function in_array;
use function is_string;

/**
 * YForm-Tabellen als Datensatz-Quelle. Welche Tabellen und wie (Label,
 * Suchspalten, Sortierung, Filter, Sprachspalte) steht in TableConfig
 * (Einstellungsseite). Rechte: yform_manager_table_view bzw. _edit.
 */
final class YFormSourceProvider implements SourceProviderInterface
{
    public const ID = 'yform';
    private const PER_PAGE = 50;

    public static function isAvailable(): bool
    {
        return rex_addon::get('yform')->isAvailable() && class_exists(rex_yform_manager_table::class);
    }

    public function getId(): string
    {
        return self::ID;
    }

    public function getLabel(): string
    {
        return rex_i18n::msg('linkmap_source_yform');
    }

    public function getIcon(): string
    {
        return 'fa-solid fa-database';
    }

    public function ownsLink(string $link): bool
    {
        return LinkResolver::isLink($link);
    }

    public function hasContainerAccess(string $container): bool
    {
        return null !== rex_yform_manager_table::get($container) && $this->hasTablePerm($container);
    }

    public function getContainers(): array
    {
        $containers = [];
        foreach (TableConfig::enabledTables() as $tableName) {
            $table = rex_yform_manager_table::get($tableName);
            if (null === $table || !$this->hasTablePerm($tableName)) {
                continue;
            }
            $containers[] = [
                'id' => $tableName,
                'label' => rex_i18n::translate($table->getName()),
                'icon' => 'fa-solid fa-table-list',
                'linkable' => LinkResolver::tableIsLinkable($tableName),
                'addUrl' => $this->addUrl($table),
            ];
        }
        return $containers;
    }

    public function browse(string $container, string $query, int $page, int $clang, string $sort = '', string $dir = 'asc'): array
    {
        // Freigabe (TableConfig) steuert nur die Sichtbarkeit in der Sidebar
        // (getContainers()); Relation-Felder adressieren Tabellen direkt.
        $table = rex_yform_manager_table::get($container);
        if (null === $table || !$this->hasTablePerm($container)) {
            return ['items' => [], 'total' => 0, 'page' => 1, 'pages' => 0, 'columns' => [], 'sort' => '', 'dir' => 'asc'];
        }
        $config = TableConfig::get($container) ?? [];
        $columns = $this->columns($container);
        $listColumns = $this->listColumns($table, $config, $columns);
        $dir = 'desc' === strtolower($dir) ? 'DESC' : 'ASC';
        $sortColumn = $this->sortColumn($sort, $listColumns, $config, $columns);

        $q = rex_yform_manager_query::get($container);
        $alias = $q->getTableAlias();

        $filter = trim((string) ($config['filter'] ?? ''));
        if ('' !== $filter && $this->isSafeSql($filter)) {
            $q->whereRaw($filter);
        }

        $clangField = trim((string) ($config['clang_field'] ?? ''));
        if ('' !== $clangField && in_array($clangField, $columns, true)) {
            $q->where($clangField, $clang);
        }

        $query = trim($query);
        if ('' !== $query) {
            $searchColumns = $this->searchColumns($table, $config, $columns);
            $conditions = [];
            $params = [];
            foreach ($searchColumns as $i => $column) {
                $conditions[] = $alias . '.' . rex_sql::factory()->escapeIdentifier($column) . ' LIKE :lm_q' . $i;
                $params['lm_q' . $i] = '%' . rex_sql::factory()->escapeLikeWildcards($query) . '%';
            }
            if (ctype_digit($query)) {
                $conditions[] = $alias . '.id = :lm_id';
                $params['lm_id'] = (int) $query;
            }
            if ([] !== $conditions) {
                $q->whereRaw(implode(' OR ', $conditions), $params);
            }
        }

        $total = (clone $q)->count();
        $pages = (int) ceil($total / self::PER_PAGE);
        $page = max(1, min($page, max(1, $pages)));

        $order = trim((string) ($config['order'] ?? ''));
        if (null !== $sortColumn) {
            $q->orderBy($sortColumn, $dir);
        } elseif ('' !== $order && preg_match('~^[a-z0-9_]+(\s+(asc|desc))?(\s*,\s*[a-z0-9_]+(\s+(asc|desc))?)*$~i', $order)) {
            foreach (explode(',', $order) as $part) {
                $bits = explode(' ', preg_replace('~\s+~', ' ', trim($part)) ?? '');
                $q->orderBy($bits[0], 'DESC' === strtoupper($bits[1] ?? 'ASC') ? 'DESC' : 'ASC');
            }
        } else {
            $q->orderBy('id', 'DESC');
        }

        $q->limit(($page - 1) * self::PER_PAGE, self::PER_PAGE);

        $items = [];
        foreach ($q->find() as $dataset) {
            if ($dataset instanceof rex_yform_manager_dataset) {
                $item = $this->item($dataset, $table, $config, $columns);
                $item['cells'] = $this->cells($dataset, $listColumns);
                $items[] = $item;
            }
        }

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'columns' => $listColumns,
            'sort' => null !== $sortColumn ? $sort : '',
            'dir' => strtolower($dir),
            'addUrl' => $this->addUrl($table),
            'containerLabel' => rex_i18n::translate($table->getName()),
        ];
    }

    /**
     * URL zum Anlegen eines Datensatzes im YForm Table Manager (neues
     * Fenster). rex_yform_manager::url() haengt das CSRF-Token nur bei
     * func=edit an, YForm verlangt es aber auch fuer func=add -- deshalb
     * selbst gebaut; unescaped (kein &amp;), landet in JSON.
     */
    private function addUrl(rex_yform_manager_table $table): string
    {
        if (!$this->canEdit($table)) {
            return '';
        }
        $params = ['table_name' => $table->getTableName(), 'func' => 'add', 'rex_yform_manager_popup' => 0]
            + rex_csrf_token::factory($table->getCSRFKey())->getUrlParams();
        return rex_url::backendPage('yform/manager/data_edit', $params, false);
    }

    /**
     * Spalten der Listenansicht: "label" (Bezeichnung aus dem Label-Template)
     * plus die in der Konfiguration genannten Spalten, sonst die im YForm
     * Table Manager sichtbaren Felder (max. 5, ohne Textareas/Medien).
     *
     * @param array<string, mixed> $config
     * @param list<string> $columns
     * @return list<array{key: string, label: string, sortable: bool}>
     */
    private function listColumns(rex_yform_manager_table $table, array $config, array $columns): array
    {
        $labelColumn = $this->labelColumn($config, $columns);
        $result = [['key' => 'label', 'label' => rex_i18n::msg('linkmap_col_label'), 'sortable' => null !== $labelColumn]];

        $configured = array_values(array_filter(array_map('trim', explode(',', (string) ($config['columns'] ?? ''))), static fn (string $c): bool => '' !== $c));
        $fieldLabels = [];
        foreach ($table->getValueFields() as $field) {
            $fieldLabels[$field->getName()] = ['label' => rex_i18n::translate($field->getLabel()), 'type' => $field->getTypeName(), 'hidden' => $field->isHiddenInList()];
        }

        $keys = [];
        if ([] !== $configured) {
            $keys = $configured;
        } else {
            foreach ($fieldLabels as $name => $info) {
                if (!$info['hidden'] && !in_array($info['type'], ['textarea', 'be_media', 'be_medialist', 'html', 'php', 'upload'], true)) {
                    $keys[] = $name;
                }
            }
        }
        foreach ($keys as $key) {
            if ($key === $labelColumn || 'id' === $key || !in_array($key, $columns, true)) {
                continue;
            }
            if (count($result) >= 6) {
                break;
            }
            $result[] = ['key' => $key, 'label' => $fieldLabels[$key]['label'] ?? $key, 'sortable' => true];
        }
        return $result;
    }

    /**
     * @param list<array{key: string, label: string, sortable: bool}> $listColumns
     * @param array<string, mixed> $config
     * @param list<string> $columns
     */
    private function sortColumn(string $sort, array $listColumns, array $config, array $columns): ?string
    {
        if ('' === $sort) {
            return null;
        }
        if ('id' === $sort) {
            return 'id';
        }
        if ('label' === $sort) {
            return $this->labelColumn($config, $columns);
        }
        foreach ($listColumns as $column) {
            if ($column['key'] === $sort && $column['sortable'] && in_array($sort, $columns, true)) {
                return $sort;
            }
        }
        return null;
    }

    /**
     * DB-Spalte hinter der Bezeichnung (fuer die Sortierung): erste Spalte
     * des Label-Templates, sonst die Fallback-Spalte (name/title/...).
     *
     * @param array<string, mixed> $config
     * @param list<string> $columns
     */
    private function labelColumn(array $config, array $columns): ?string
    {
        $template = trim((string) ($config['label'] ?? ''));
        if ('' !== $template && preg_match('~\{([a-z0-9_]+)\}~i', $template, $m) && in_array($m[1], $columns, true)) {
            return $m[1];
        }
        foreach (['name', 'title', 'titel', 'label', 'headline', 'subject'] as $candidate) {
            if (in_array($candidate, $columns, true)) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * @param list<array{key: string, label: string, sortable: bool}> $listColumns
     * @return array<string, string>
     */
    private function cells(rex_yform_manager_dataset $dataset, array $listColumns): array
    {
        $cells = [];
        foreach ($listColumns as $column) {
            if ('label' === $column['key']) {
                continue;
            }
            $value = $dataset->getValue($column['key']);
            $value = is_scalar($value) ? trim(strip_tags((string) $value)) : '';
            $cells[$column['key']] = mb_strlen($value) > 60 ? mb_substr($value, 0, 57) . '…' : $value;
        }
        return $cells;
    }

    public function resolve(string $link, int $clang): ?array
    {
        $parsed = LinkResolver::parse($link);
        if (null === $parsed) {
            return null;
        }
        $table = rex_yform_manager_table::get($parsed['table']);
        if (null === $table || !$this->hasTablePerm($parsed['table'])) {
            return null;
        }
        $dataset = rex_yform_manager_dataset::get($parsed['id'], $parsed['table']);
        if (null === $dataset) {
            return null;
        }
        $item = $this->item($dataset, $table, TableConfig::get($parsed['table']) ?? [], $this->columns($parsed['table']));
        $item['link'] = $link;
        $item['scheme'] = $parsed['scheme'];
        return $item;
    }

    /**
     * @param array<string, mixed> $config
     * @param list<string> $columns
     * @return array<string, mixed>
     */
    private function item(rex_yform_manager_dataset $dataset, rex_yform_manager_table $table, array $config, array $columns): array
    {
        $tableName = $table->getTableName();
        $name = $this->label($dataset, $config, $columns);
        $status = in_array('status', $columns, true) ? (int) $dataset->getValue('status') : null;
        $meta = [];
        foreach (['updatedate', 'createdate'] as $dateColumn) {
            if (in_array($dateColumn, $columns, true) && '' !== (string) $dataset->getValue($dateColumn)) {
                $meta[] = (string) $dataset->getValue($dateColumn);
                break;
            }
        }

        return [
            'id' => $dataset->getId(),
            'link' => LinkResolver::build($tableName, $dataset->getId()),
            'name' => $name,
            'label' => $name,
            'meta' => implode(' · ', $meta),
            'online' => null === $status || 1 === $status,
            'status' => $status,
            'source' => self::ID,
            'container' => $tableName,
            'containerLabel' => rex_i18n::translate($table->getName()),
            'linkable' => LinkResolver::tableIsLinkable($tableName),
            'editUrl' => $this->editUrl($table, $dataset->getId()),
        ];
    }

    /** Bearbeiten-URL (YForm-Formular, neues Fenster) -- nur mit Bearbeitungsrecht. */
    private function editUrl(rex_yform_manager_table $table, int $id): string
    {
        if (!$this->canEdit($table)) {
            return '';
        }
        $params = ['table_name' => $table->getTableName(), 'data_id' => $id, 'func' => 'edit', 'rex_yform_manager_popup' => 0]
            + rex_csrf_token::factory($table->getCSRFKey())->getUrlParams();
        return rex_url::backendPage('yform/manager/data_edit', $params, false);
    }

    private function canEdit(rex_yform_manager_table $table): bool
    {
        $user = rex::getUser();
        if (null === $user) {
            return false;
        }
        $edit = $user->getComplexPerm('yform_manager_table_edit');
        return $user->isAdmin() || ($edit instanceof rex_yform_manager_table_perm_edit && $edit->hasPerm($table->getTableName()));
    }

    /**
     * Label aus Template "{spalte} ..." oder Fallback (name/title/erste Textspalte/id).
     *
     * @param array<string, mixed> $config
     * @param list<string> $columns
     */
    private function label(rex_yform_manager_dataset $dataset, array $config, array $columns): string
    {
        $template = trim((string) ($config['label'] ?? ''));
        if ('' !== $template) {
            $label = preg_replace_callback('~\{([a-z0-9_]+)\}~i', static function (array $m) use ($dataset): string {
                return $dataset->hasValue($m[1]) ? trim((string) $dataset->getValue($m[1])) : '';
            }, $template);
            $label = trim((string) $label);
            if ('' !== $label) {
                return $label;
            }
        }
        foreach (['name', 'title', 'titel', 'label', 'headline', 'subject'] as $candidate) {
            if (in_array($candidate, $columns, true) && '' !== trim((string) $dataset->getValue($candidate))) {
                return trim((string) $dataset->getValue($candidate));
            }
        }
        foreach ($columns as $column) {
            if (in_array($column, ['id', 'status', 'prio', 'createdate', 'updatedate', 'createuser', 'updateuser'], true)) {
                continue;
            }
            $value = $dataset->getValue($column);
            if (is_string($value) && '' !== trim($value) && !is_numeric($value)) {
                return mb_substr(trim(strip_tags($value)), 0, 80);
            }
        }
        return '#' . $dataset->getId();
    }

    /**
     * @param array<string, mixed> $config
     * @param list<string> $columns
     * @return list<string>
     */
    private function searchColumns(rex_yform_manager_table $table, array $config, array $columns): array
    {
        $configured = array_values(array_filter(array_map('trim', explode(',', (string) ($config['search'] ?? ''))), static fn (string $c): bool => '' !== $c));
        $configured = array_values(array_filter($configured, static fn (string $c): bool => in_array($c, $columns, true)));

        if ([] !== $configured) {
            return $configured;
        }

        $result = [];
        foreach ($table->getValueFields() as $field) {
            $name = $field->getName();
            if (!in_array($name, $columns, true)) {
                continue;
            }
            if ($field->isSearchable() || in_array($field->getTypeName(), ['text', 'textarea'], true)) {
                $result[] = $name;
            }
        }
        if ([] === $result) {
            foreach (['name', 'title', 'titel', 'label'] as $candidate) {
                if (in_array($candidate, $columns, true)) {
                    $result[] = $candidate;
                }
            }
        }
        return array_values(array_unique($result));
    }

    /** @return list<string> */
    private function columns(string $table): array
    {
        static $cache = [];
        if (!isset($cache[$table])) {
            $sql = rex_sql::factory();
            $sql->setQuery('SELECT * FROM ' . $sql->escapeIdentifier($table) . ' LIMIT 0');
            $cache[$table] = $sql->getFieldnames();
        }
        return $cache[$table];
    }

    private function hasTablePerm(string $table): bool
    {
        $user = rex::getUser();
        if (null === $user) {
            return false;
        }
        if ($user->isAdmin()) {
            return true;
        }
        $view = $user->getComplexPerm('yform_manager_table_view');
        $edit = $user->getComplexPerm('yform_manager_table_edit');
        return ($view instanceof rex_yform_manager_table_perm_view && $view->hasPerm($table))
            || ($edit instanceof rex_yform_manager_table_perm_edit && $edit->hasPerm($table));
    }

    /** Filter-Klauseln aus der Konfiguration: keine Statement-Trenner, keine Kommentare. */
    private function isSafeSql(string $sql): bool
    {
        return !preg_match('~;|--|/\*|\*/|\bunion\b|\binto\b|\bload_file\b~i', $sql) && count(explode(';', $sql)) === 1;
    }
}
