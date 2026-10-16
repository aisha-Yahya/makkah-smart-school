<?php
/**
 * توحيد ومطابقة أسماء الفصول والشعب بين نظام سكول برو والمنصة
 */

function formatPlatformClassName($className, $branchName) {
    $c = trim($className);
    $b = trim($branchName);
    if (empty($b)) $b = 'أ';

    if ($c === 'الاول ثانوي' || $c === 'الأول ثانوي') {
        return "الصف الأول الثانوي - " . $b;
    }
    if ($c === 'الثاني ثانوي علمي') {
        return "الصف الثاني الثانوي علمي - " . $b;
    }
    if ($c === 'الثالث ثانوي علمي') {
        return "الصف الثالث الثانوي علمي - " . $b;
    }
    if ($c === 'الثاني ثانوي ادبي' || $c === 'الثاني ثانوي أدبي') {
        return "الصف الثاني الثانوي أدبي - " . $b;
    }
    if ($c === 'الثالث ثانوي ادبي' || $c === 'الثالث ثانوي أدبي') {
        return "الصف الثالث الثانوي أدبي - " . $b;
    }
    if ($c === 'التمهيدي') {
        return "التمهيدي - " . $b;
    }
    if (str_starts_with($c, 'الصف ')) {
        return $c . ' - ' . $b;
    }
    return "الصف " . $c . " - " . $b;
}

function parsePlatformClassName($str) {
    $str = trim($str);
    $parts = explode('-', $str);
    $branch = 'أ';
    if (count($parts) > 1) {
        $branch = trim(end($parts));
        array_pop($parts);
    }
    $main = trim(implode('-', $parts));
    $main = preg_replace('/^الصف\s+/', '', $main);
    
    if ($main === 'الأول الثانوي' || $main === 'الاول الثانوي') $main = 'الاول ثانوي';
    if ($main === 'الثاني الثانوي علمي' || $main === 'الثاني ثانوي علمي') $main = 'الثاني ثانوي علمي';
    if ($main === 'الثالث الثانوي علمي' || $main === 'الثالث ثانوي علمي') $main = 'الثالث ثانوي علمي';
    if ($main === 'الثاني الثانوي أدبي' || $main === 'الثاني ثانوي ادبي') $main = 'الثاني ثانوي ادبي';
    if ($main === 'الثالث الثانوي أدبي' || $main === 'الثالث ثانوي ادبي') $main = 'الثالث ثانوي ادبي';

    return ['class_name' => $main, 'branch_name' => $branch];
}
