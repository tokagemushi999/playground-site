<?php
/**
 * 共通フォーマットヘルパー
 */

/**
 * 金額を表示用にフォーマット
 */
function formatPrice($price, $default = '-') {
    if ($price === null || $price === '') {
        return $default;
    }
    return '¥' . number_format((float) $price);
}

/**
 * 数値を表示用にフォーマット
 */
function formatNumber($value, $default = '-') {
    if ($value === null || $value === '') {
        return $default;
    }
    return number_format((float) $value);
}
