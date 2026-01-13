<?php
/**
 * 共通フォーマットヘルパー
 */

/**
 * 金額を表示用にフォーマット
 */
function formatPrice($price) {
    if ($price === null || $price === '') return '-';
    return '¥' . number_format($price);
}
