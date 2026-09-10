<?php

/**
 * Ersetzt structure/pages/linkmap.php (siehe boot.php PAGES_PREPARED). Wird
 * die Seite trotz JS-Weiche noch als echtes Popup geoeffnet (fremde Addons,
 * Deep-Links), oeffnet hier das Overlay im Vollbild und bildet bei Auswahl den
 * klassischen Opener-Vertrag nach: rex:selectLink auf opener.jQuery(window),
 * danach REX_LINK_<id>/_NAME bzw. REX_LINKLIST_SELECT_<id> im Opener
 * befuellen (vgl. insertLink() in structure/pages/linkmap.php).
 */

$openerInputField = rex_request('opener_input_field', 'string');
$openerInputFieldName = rex_request('opener_input_field_name', 'string');
$categoryId = rex_request('category_id', 'int');
$categoryId = rex_category::get($categoryId) ? $categoryId : 0;
$clang = rex_request('clang', 'int');
$clang = rex_clang::exists($clang) ? $clang : rex_clang::getStartId();

$pattern = '/[^a-z0-9_-]/i';
if (preg_match($pattern, $openerInputField, $match)) {
    throw new InvalidArgumentException(sprintf('Invalid character "%s" in opener_input_field.', $match[0]));
}
if (preg_match($pattern, $openerInputFieldName, $match)) {
    throw new InvalidArgumentException(sprintf('Invalid character "%s" in opener_input_field_name.', $match[0]));
}
if ('' !== $openerInputField && '' === $openerInputFieldName) {
    $openerInputFieldName = $openerInputField . '_NAME';
}

$isLinklist = str_starts_with($openerInputField, 'REX_LINKLIST_');
$closeHref = rex_url::backendPage('structure', ['clang' => $clang, 'category_id' => $categoryId], false);

?>
<div id="lm-takeover" style="padding:80px 20px;text-align:center;color:#777;">
    <i class="fa-solid fa-spinner fa-spin" style="font-size:24px;"></i>
    <noscript><p><?= rex_escape(rex_i18n::msg('linkmap_takeover_noscript')) ?></p></noscript>
</div>
<script nonce="<?= rex_response::getNonce() ?>">
(function () {
    var openerField = <?= json_encode($openerInputField) ?>;
    var openerFieldName = <?= json_encode($openerInputFieldName) ?>;
    var isLinklist = <?= json_encode($isLinklist) ?>;
    var clang = <?= (int) $clang ?>;
    var categoryId = <?= (int) $categoryId ?>;
    var closeHref = <?= json_encode($closeHref) ?>;

    // Feld im Opener -- bei doppelten IDs ueber die Bridge des Openers (bevorzugt
    // das sichtbare Duplikat), sonst klassisch per getElementById().
    function findInOpener(id) {
        var opener = window.opener;
        if (!opener || !opener.document) return null;
        if (opener.rex5LinkmapBridge && typeof opener.rex5LinkmapBridge.findField === 'function') {
            return opener.rex5LinkmapBridge.findField(id, opener.document);
        }
        return opener.document.getElementById(id);
    }

    function finishSingle(link, name) {
        var opener = window.opener;
        var prevented = false;
        if (opener && opener.jQuery) {
            var event = opener.jQuery.Event('rex:selectLink');
            opener.jQuery(window).trigger(event, [link, name]);
            prevented = event.isDefaultPrevented();
        }
        if (!prevented && opener && opener.document) {
            var id = String(link).replace('redaxo://', '');
            var input = openerField ? findInOpener(openerField) : null;
            var nameInput = openerFieldName ? findInOpener(openerFieldName) : null;
            if (input) {
                input.value = id;
                if (opener.jQuery) opener.jQuery(input).trigger('change');
            }
            if (nameInput) nameInput.value = name + ' [' + id + ']';
        }
        window.close();
    }

    function finishMulti(items) {
        var opener = window.opener;
        if (opener && opener.document && openerField) {
            var listId = openerField.slice('REX_LINKLIST_'.length);
            var select = findInOpener('REX_LINKLIST_SELECT_' + listId);
            if (select) {
                items.forEach(function (item) {
                    var option = opener.document.createElement('OPTION');
                    option.text = item.name + ' [' + item.id + ']';
                    option.value = String(item.id);
                    select.options.add(option, select.options.length);
                });
                if (typeof opener.writeREXLinklist === 'function') {
                    opener.writeREXLinklist(listId);
                }
            }
        }
        window.close();
    }

    var tries = 0;
    function boot() {
        if (window.LM && typeof window.LM.open === 'function') {
            var options = { clang: clang, categoryId: categoryId, fullscreen: true };
            if (window.opener) {
                options.onClose = function () { window.close(); };
                if (isLinklist) {
                    options.multiple = true;
                    window.LM.open(finishMulti, options);
                } else {
                    window.LM.open(finishSingle, options);
                }
            } else {
                options.closeHref = closeHref;
                window.LM.open(null, options);
            }
            return;
        }
        tries++;
        if (tries > 100) {
            var el = document.getElementById('lm-takeover');
            if (el) el.textContent = <?= json_encode(rex_i18n::msg('linkmap_takeover_failed')) ?>;
            return;
        }
        setTimeout(boot, 30);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
</script>
