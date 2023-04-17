<?php

$cfg = require __DIR__ . '/../vendor/mediawiki/mediawiki-phan-config/src/config-library.php';

// T303790 - Can be removed when extension doesn't need to support < PHP 8.1
$cfg['suppress_issue_types'][] = 'PhanImpossibleTypeComparison';

return $cfg;
