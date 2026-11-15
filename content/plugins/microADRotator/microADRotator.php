<?php
/**
 * Micro AD Rotator for DiCimes
 */
function getRandomAD(int $ADnumber = 1): string {
    $adFile = __DIR__ . '/adlist.txt';
    if (!is_file($adFile)) {
        return '';
    }
    $adList = array_values(array_filter(array_map('trim', file($adFile) ?: []), static fn($l) => $l !== ''));
    $numberOfADs = count($adList);
    if ($numberOfADs === 0) {
        return '';
    }
    $out = '';
    for ($i = 0; $i < max(1, $ADnumber); $i++) {
        $actAD = random_int(0, $numberOfADs - 1);
        $out .= $adList[$actAD] . '<br/>';
    }
    return $out;
}

function showRandomAD(int $ADnumber = 1): void {
    echo getRandomAD($ADnumber);
}
