<?php

/**
 * @var rex_yform_value_linkmap_relation $this
 * @psalm-scope-this rex_yform_value_linkmap_relation
 */

$name = $this->getFieldName();
$multiple = '1' == $this->getElement('multiple');
$max = trim((string) $this->getElement('max'));
$table = trim((string) $this->getElement('table'));

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
    <?php if ('' === $table): ?>
        <p class="text-warning"><?= rex_escape(rex_i18n::msg('linkmap_yform_relation_no_table')) ?></p>
    <?php else: ?>
        <?= \FriendsOfRedaxo\Linkmap\Widget::render($name, $this->getValue(), [
            'id' => $this->getFieldId(),
            'multiple' => $multiple,
            'max' => (int) $max,
            'table' => $table,
        ]) ?>
    <?php endif ?>
    <?= $notice ?>
</div>
