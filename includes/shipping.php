<?php
/**
 * 配送関連の共通ヘルパー
 */

/**
 * 配送業者名を取得
 */
function getShippingCarrierName($carrierCode, $fallback = '未設定') {
    $carrierNames = [
        'yamato' => 'ヤマト運輸',
        'sagawa' => '佐川急便',
        'japanpost' => '日本郵便',
        'japanpost_yu' => 'ゆうパック',
        'clickpost' => 'クリックポスト',
        'nekopos' => 'ネコポス',
        'yupacket' => 'ゆうパケット',
        'other' => 'その他',
    ];

    if (!$carrierCode) {
        return $fallback;
    }

    return $carrierNames[$carrierCode] ?? $fallback;
}

/**
 * 追跡URLを取得
 */
function getTrackingUrl($carrierCode, $trackingNumber) {
    $trackingNumber = trim((string) $trackingNumber);
    if ($trackingNumber === '') {
        return '';
    }

    $encodedNumber = urlencode($trackingNumber);
    switch ($carrierCode) {
        case 'yamato':
        case 'nekopos':
            return "https://toi.kuronekoyamato.co.jp/cgi-bin/tneko?number={$encodedNumber}";
        case 'sagawa':
            return "https://k2k.sagawa-exp.co.jp/p/web/okurijosearch.do?okurijoNo={$encodedNumber}";
        case 'japanpost':
        case 'japanpost_yu':
        case 'clickpost':
        case 'yupacket':
            return "https://trackings.post.japanpost.jp/services/srv/search/?requestNo1={$encodedNumber}";
        default:
            return '';
    }
}
