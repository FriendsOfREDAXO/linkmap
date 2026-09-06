<?php

$addon = rex_addon::get('linkmap');
$addon->removeConfig('version');

// Favoriten (favorites_<userId>) bleiben stehen -- rex_config wird beim
// Deinstallieren des Pakets vom Core komplett entfernt.
