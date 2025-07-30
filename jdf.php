<?php
function gregorian_to_jalali($gy, $gm, $gd)
{
    $g_d_m = array(0, 31,28,31,30,31,30,31,31,30,31,30,31);
    $jy = ($gy <= 1600) ? 0 : 979;
    $gy -= ($gy <= 1600) ? 621 : 1600;
    $gm--;
    $days = (365 * $gy) + intval(($gy + 3) / 4) - intval(($gy + 99) / 100) + intval(($gy + 399) / 400);
    for ($i = 0; $i < $gm; ++$i)
        $days += $g_d_m[$i];
    if ($gm > 1 && (($gy % 4 == 0 && $gy % 100 != 0) || ($gy % 400 == 0)))
        ++$days;
    $days += $gd - 1;
    $jy += 33 * intval($days / 12053); $days %= 12053;
    $jy += 4 * intval($days / 1461); $days %= 1461;
    if ($days > 365) {
        $jy += intval(($days - 1) / 365);
        $days = ($days - 1) % 365;
    }
    if ($days < 186) {
        $jm = 1 + intval($days / 31); $jd = 1 + ($days % 31);
    } else {
        $jm = 7 + intval(($days - 186) / 30); $jd = 1 + (($days - 186) % 30);
    }
    return [$jy, $jm, $jd];
}

function to_jalali($datetime)
{
    $timestamp = strtotime($datetime);
    list($gy, $gm, $gd) = explode('-', date('Y-m-d', $timestamp));
    list($jy, $jm, $jd) = gregorian_to_jalali($gy, $gm, $gd);
    return sprintf('%04d/%02d/%02d %s', $jy, $jm, $jd, date('H:i', $timestamp));
}
function jalali_to_gregorian($jy, $jm, $jd)
{
    $g_d_m = array(0, 31,28,31,30,31,30,31,31,30,31,30,31);
    $gy = ($jy > 979) ? 1600 : 621;
    $jy -= ($jy > 979) ? 979 : 0;
    $days = (365 * $jy) + intval($jy / 33) * 8 + intval(($jy % 33 + 3) / 4);
    for ($i = 0; $i < $jm - 1; ++$i)
        $days += ($i < 6) ? 31 : 30;
    $days += $jd - 1;
    $gy += 400 * intval($days / 146097); $days %= 146097;
    if ($days > 36524) {
        $gy += 100 * intval(--$days / 36524); $days %= 36524;
        if ($days >= 365) $days++;
    }
    $gy += 4 * intval($days / 1461); $days %= 1461;
    if ($days > 365) {
        $gy += intval(($days - 1) / 365);
        $days = ($days - 1) % 365;
    }
    for ($i = 0; $days >= $g_d_m[$i] + (($i == 1 && (($gy % 4 == 0 && $gy % 100 != 0) || ($gy % 400 == 0))) ? 1 : 0); $i++)
        $days -= $g_d_m[$i] + (($i == 1 && (($gy % 4 == 0 && $gy % 100 != 0) || ($gy % 400 == 0))) ? 1 : 0);
    $gm = $i + 1;
    $gd = $days + 1;
    return [$gy, $gm, $gd];
}
