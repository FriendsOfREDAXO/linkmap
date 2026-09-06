<?php

$addon = rex_addon::get('linkmap');
$addon->setConfig('version', $addon->getVersion());

// Default-Config-Werte kommen aus package.yml (default_config:), nicht von hier.
