<?php

/**
 * @var rex_yform_value_linkmap $this
 * @psalm-scope-this rex_yform_value_linkmap
 */

$name = $this->getFieldName();
$multiple = '1' == $this->getElement('multiple');
$max = trim((string) $this->getElement('max'));
$category = (int) $this->getElement('category');
$domain = trim((string) $this->getElement('domain'));
$format = 'link' === (string) $this->getElement('format') ? 'link' : 'id';
$sources = trim((string) $this->getElement('sources'));
$categories = '1' == $this->getElement('categories');

$class_group = trim('form-group ' . $this->getHTMLClass() . ' ' . $this->getWarningClass());

$notice = [];
if ('' != $this->getElement('notice')) {
    $notice[] = rex_i18n::translate($this->getElement('notice'), false);
}
if (isset($this->params['warning_messages'][$this->getId()]) && !$this->params['hide_field_warning_messages']) {
    $notice[] = '<span class="text-warning">' . rex_i18n::translate($this->params['warning_messages'][$this->getId()], false) . '</span>';
}
$notice = count($notice) > 0 ? '<p class="help-block small">' . implode('<br />', $notice) . '</p>' : '';

?>
<div class="<?= $class_group ?>" id="<?= $this->getHTMLId() ?>">
    <label class="control-label" for="<?= $this->getFieldId() ?>"><?= $this->getLabel() ?></label>
    <?= \FriendsOfRedaxo\Linkmap\Widget::render($name, $this->getValue(), [
        'id' => $this->getFieldId(),
        'multiple' => $multiple,
        'max' => (int) $max,
        'category' => $category,
        'domain' => $domain,
        'format' => $format,
        'sources' => '' !== $sources ? $sources : 'article',
        'categories' => $categories,
    ]) ?>
    <?= $notice ?>
</div>
