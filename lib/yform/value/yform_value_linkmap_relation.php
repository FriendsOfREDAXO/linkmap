<?php

use FriendsOfRedaxo\Linkmap\Source\YFormSourceProvider;

/**
 * YForm-Werttyp "linkmap_relation": Datensatz-Auswahl aus EINER festgelegten
 * YForm-Tabelle ueber das Linkmap-Overlay (Relation-Modus). Speichert die
 * Datensatz-ID, bei Mehrfachauswahl kommasepariert -- dasselbe Format wie
 * be_manager_relation (Typ 0/1), ein Feld laesst sich also umstellen. Keine
 * Struktur, keine URL-Aufloesung; die Tabelle muss nicht als Quelle
 * freigegeben sein.
 */
class rex_yform_value_linkmap_relation extends rex_yform_value_abstract
{
    public function enterObject(): void
    {
        $value = $this->getValue();
        if (('' === $value || null === $value) && !$this->params['send']) {
            $value = (string) $this->getElement('default');
        }
        $table = trim((string) $this->getElement('table'));

        $ids = [];
        foreach (explode(',', (string) $value) as $part) {
            $id = (int) trim($part);
            if ($id > 0 && !in_array($id, $ids, true) && '' !== $table && null !== rex_yform_manager_table::get($table) && null !== rex_yform_manager_dataset::get($id, $table)) {
                $ids[] = $id;
            }
        }
        if (1 != $this->getElement('multiple')) {
            $ids = array_slice($ids, 0, 1);
        }
        $this->setValue(implode(',', $ids));

        if ($this->needsOutput() && $this->isViewable()) {
            if (!$this->isEditable()) {
                $this->params['form_output'][$this->getId()] = $this->parse('value.view.tpl.php', ['value' => self::getListValue(['value' => $this->getValue(), 'field' => $this])]);
            } else {
                $this->params['form_output'][$this->getId()] = $this->parse('value.linkmap_relation.tpl.php');
            }
        }

        $this->params['value_pool']['email'][$this->getName()] = $this->getValue();
        if ($this->saveInDB()) {
            $this->params['value_pool']['sql'][$this->getName()] = $this->getValue();
        }
    }

    /** @return array<string, mixed> */
    public function getDefinitions(): array
    {
        return [
            'type' => 'value',
            'name' => 'linkmap_relation',
            'values' => [
                'name' => ['type' => 'name', 'label' => rex_i18n::msg('yform_values_defaults_name')],
                'label' => ['type' => 'text', 'label' => rex_i18n::msg('yform_values_defaults_label')],
                'table' => ['type' => 'table', 'label' => rex_i18n::msg('linkmap_yform_relation_table')],
                'multiple' => ['type' => 'checkbox', 'label' => rex_i18n::msg('linkmap_yform_multiple')],
                'max' => ['type' => 'text', 'label' => rex_i18n::msg('linkmap_yform_max')],
                'default' => ['type' => 'text', 'label' => rex_i18n::msg('linkmap_yform_relation_default')],
                'notice' => ['type' => 'text', 'label' => rex_i18n::msg('yform_values_defaults_notice')],
            ],
            'description' => rex_i18n::msg('linkmap_yform_relation_description'),
            'formbuilder' => false,
            'db_type' => ['int', 'text', 'varchar(191)'],
        ];
    }

    /** @param array<string, mixed> $params */
    public static function getListValue($params): string
    {
        $raw = (string) ($params['value'] ?? $params['subject'] ?? '');
        $field = $params['field'] ?? null;
        $table = $field instanceof rex_yform_manager_field || $field instanceof rex_yform_value_abstract ? trim((string) $field->getElement('table')) : '';
        if ('' === trim($raw)) {
            return '-';
        }
        $provider = new YFormSourceProvider();
        $names = [];
        foreach (explode(',', $raw) as $part) {
            $id = (int) trim($part);
            if ($id <= 0) {
                continue;
            }
            $item = '' !== $table ? $provider->resolve('yform://' . $table . '/' . $id, rex_clang::getCurrentId()) : null;
            $names[] = null !== $item
                ? rex_escape((string) $item['name']) . ' <small class="text-muted">[' . $id . ']</small>'
                : '#' . $id;
        }
        if ([] === $names) {
            return '-';
        }
        if (count($names) > 4) {
            $names = array_slice($names, 0, 4);
            $names[] = '...';
        }
        return implode('<br />', $names);
    }

    /** @param array<string, mixed> $params */
    public static function getSearchField($params): void
    {
        $params['searchForm']->setValueField('text', [
            'name' => $params['field']->getName(),
            'label' => $params['field']->getLabel(),
        ]);
    }

    /**
     * @param array<string, mixed> $params
     * @return rex_yform_manager_query<rex_yform_manager_dataset>
     */
    public static function getSearchFilter($params): rex_yform_manager_query
    {
        $value = trim((string) $params['value']);
        /** @var rex_yform_manager_query<rex_yform_manager_dataset> $query */
        $query = $params['query'];
        $field = $query->getTableAlias() . '.' . $params['field']->getName();
        return '' === $value ? $query : $query->whereListContains($field, $value);
    }
}
