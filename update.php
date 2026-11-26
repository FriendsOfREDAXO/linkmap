<?php

$addon = rex_addon::get('linkmap');

// 1.1.0: "Medium auswaehlen" im Link-Dialog -- bestehende Installationen
// bekommen den Default (an), damit Einstellungsseite und Verhalten passen.
if (!$addon->hasConfig('media_links')) {
    $addon->setConfig('media_links', true);
}

$addon->setConfig('version', $addon->getVersion());
