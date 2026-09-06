<?php

/**
 * YForm-Werttyp "linkmap": Artikelauswahl per Linkmap-Overlay ueber das
 * lm-widget (linkmap_widget.js), analog zu yform/lib/Field/value/be_link.php.
 * Speichert Artikel-ID(s), bei Mehrfachauswahl kommasepariert -- dasselbe
 * Format wie be_link, ein Feld laesst sich also 1:1 umstellen.
 *
 * Klasse wird von YForm per Namenskonvention gefunden (rex_yform_value_<name>),
 * das Template-Verzeichnis registriert boot.php.
 */
class rex_yform_value_linkmap extends rex_yform_value_abstract
{
    public function enterObject(): void
    {
        $value = $this->getValue();
        // Vorbelegung wie bei rex_yform_value_text: nur solange nichts abgeschickt wurde.
        if (('' === $value || null === $value) && !$this->params['send']) {
            $value = (string) $this->getElement('default');
        }
        if (!is_string($value) && !is_int($value)) {
            $value = '';
        }

        if ($this->isLinkFormat()) {
            // Link-Format: redaxo://ID (nur existierende Artikel) oder Datensatz-Links
            $links = [];
            foreach (explode(',', \FriendsOfRedaxo\Linkmap\Widget::normalizeLinks((string) $value, true)) as $link) {
                if ('' === $link || in_array($link, $links, true)) {
                    continue;
                }
                if (preg_match('~^redaxo://(\d+)$~', $link, $m) && !rex_article::get((int) $m[1])) {
                    continue;
                }
                $links[] = $link;
            }
            if (1 != $this->getElement('multiple')) {
                $links = array_slice($links, 0, 1);
            }
            $this->setValue(implode(',', $links));
        } else {
            // Nur existierende Artikel-IDs behalten (Reihenfolge bleibt erhalten).
            $ids = [];
            foreach (explode(',', (string) $value) as $part) {
                $id = (int) trim($part);
                if ($id > 0 && !in_array($id, $ids, true) && rex_article::get($id)) {
                    $ids[] = $id;
                }
            }
            if (1 != $this->getElement('multiple')) {
                $ids = array_slice($ids, 0, 1);
            }
            $this->setValue(implode(',', $ids));
        }

        if ($this->needsOutput() && $this->isViewable()) {
            if (!$this->isEditable()) {
                $this->params['form_output'][$this->getId()] = $this->parse('value.view.tpl.php', ['value' => self::getListValue(['value' => $this->getValue(), 'subject' => $this->getValue()])]);
            } else {
                $this->params['form_output'][$this->getId()] = $this->parse('value.linkmap.tpl.php');
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
            'name' => 'linkmap',
            'values' => [
                'name' => ['type' => 'name', 'label' => rex_i18n::msg('yform_values_defaults_name')],
                'label' => ['type' => 'text', 'label' => rex_i18n::msg('yform_values_defaults_label')],
                'multiple' => ['type' => 'checkbox', 'label' => rex_i18n::msg('linkmap_yform_multiple')],
                'max' => ['type' => 'text', 'label' => rex_i18n::msg('linkmap_yform_max')],
                'category' => ['type' => 'text', 'label' => rex_i18n::msg('linkmap_yform_category'), 'notice' => rex_i18n::msg('linkmap_yform_category_notice')],
                'domain' => ['type' => 'text', 'label' => rex_i18n::msg('linkmap_yform_domain'), 'notice' => rex_i18n::msg('linkmap_yform_domain_notice')],
                'default' => ['type' => 'text', 'label' => rex_i18n::msg('linkmap_yform_default')],
                'notice' => ['type' => 'text', 'label' => rex_i18n::msg('yform_values_defaults_notice')],
                'format' => ['type' => 'choice', 'label' => rex_i18n::msg('linkmap_yform_format'), 'choices' => ['id' => rex_i18n::msg('linkmap_yform_format_id'), 'link' => rex_i18n::msg('linkmap_yform_format_link')], 'default' => 'id', 'notice' => rex_i18n::msg('linkmap_yform_format_notice')],
                'sources' => ['type' => 'text', 'label' => rex_i18n::msg('linkmap_yform_sources'), 'notice' => rex_i18n::msg('linkmap_yform_sources_notice')],
            ],
            'description' => rex_i18n::msg('linkmap_yform_description'),
            'formbuilder' => false,
            'db_type' => ['text', 'varchar(191)', 'int', 'int(10) unsigned'],
        ];
    }

    private function isLinkFormat(): bool
    {
        return 'link' === (string) $this->getElement('format');
    }

    /** @param array<string, mixed> $params */
    public static function getListValue($params): string
    {
        $raw = (string) ($params['value'] ?? $params['subject'] ?? '');
        if ('' === trim($raw)) {
            return '-';
        }

        $names = [];
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            if (preg_match('~^redaxo://(\d+)$~', $part, $m)) {
                $part = $m[1];
            }
            if (ctype_digit($part)) {
                $article = rex_article::get((int) $part);
                if ($article) {
                    $names[] = rex_escape($article->getName()) . ' <small class="text-muted">[' . (int) $part . ']</small>';
                }
                continue;
            }
            if ('' !== $part) {
                $names[] = '<code>' . rex_escape($part) . '</code>';
            }
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

    /**
     * Haengt sich an YForms eigene Loeschsperre (be_link::isArticleInUse) an:
     * der EP liefert die Liste der zu pruefenden Felder, linkmap-Felder
     * werden ergaenzt -- damit warnt REDAXO auch bei unseren Feldern, wenn
     * ein verlinkter Artikel oder dessen Kategorie geloescht werden soll.
     *
     * @param rex_extension_point<array<int, array<string, mixed>>> $ep
     * @return array<int, array<string, mixed>>
     */
    public static function addFieldsToArticleInUse(rex_extension_point $ep): array
    {
        $fields = $ep->getSubject();
        $sql = rex_sql::factory();
        $sql->setQuery('SELECT * FROM `' . rex_yform_manager_field::table() . '` LIMIT 0');
        $select = in_array('multiple', $sql->getFieldnames(), true) ? ', `multiple`' : '';
        $own = $sql->getArray('SELECT `table_name`, `name`' . $select . ' FROM `' . rex_yform_manager_field::table() . '` WHERE `type_id` = "value" AND `type_name` = "linkmap"');
        foreach ($own as $field) {
            $fields[] = $field;
        }
        return $fields;
    }

    /** @param array<string, mixed> $params */
    public static function getSearchField($params): void
    {
        $params['searchForm']->setValueField('be_link', [
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
