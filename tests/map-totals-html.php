<?php
if (PHP_SAPI !== 'cli') exit(1);
$root = getenv('MKAUTH_MAP_TEST_SOURCE');
if (!$root) $root = __DIR__.'/../addons/mapa-clientes';
$source = file_get_contents($root.'/maps.hhvm');
$start = strpos($source, '<div class="counters">');
$end = strpos($source, '<div class="tools">', $start);
$template = substr($source, $start, $end-$start);
foreach (array(false, true) as $mapCanViewTotals) {
    ob_start(); eval('?>'.$template); $html = ob_get_clean();
    $count = preg_match_all('/id="count-(total|online|offline|unmapped)"/', $html);
    if ($count !== ($mapCanViewTotals ? 4 : 0)) throw new RuntimeException('Counter elements leaked or absent');
    foreach (array('all','online','offline','unmapped') as $filter) {
        if (strpos($html, 'data-filter="'.$filter.'"') === false) throw new RuntimeException('Status filter lost');
    }
}
echo "PASS HTML: no counter elements without permission; status filters preserved.\n";
