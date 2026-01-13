<?php
/**
 * HTMLサニタイズヘルパー
 * 本番環境ではHTMLPurifierの使用を推奨
 */

/**
 * HTMLコンテンツを安全化
 * 注: 現在は基本的なサニタイズのみ。本番環境ではHTMLPurifierを使用してください。
 * 
 * @param string $html サニタイズするHTML
 * @param bool $allowBasicHtml 基本的なHTMLタグを許可するか
 * @return string サニタイズされたHTML
 */
function sanitizeHtml($html, $allowBasicHtml = true) {
    if (empty($html)) {
        return '';
    }
    
    // HTMLPurifierが利用可能な場合
    if (class_exists('HTMLPurifier')) {
        $config = HTMLPurifier_Config::createDefault();
        
        if ($allowBasicHtml) {
            // 基本的なHTMLタグを許可
            $config->set('HTML.Allowed', 'p,br,strong,em,u,a[href],ul,ol,li,h1,h2,h3,h4,h5,h6,blockquote,img[src|alt|width|height]');
            $config->set('AutoFormat.RemoveEmpty', true);
        } else {
            // すべてのHTMLタグを削除
            $config->set('HTML.Allowed', '');
        }
        
        $purifier = new HTMLPurifier($config);
        return $purifier->purify($html);
    }
    
    // HTMLPurifierが利用できない場合の基本的なサニタイズ
    if (!$allowBasicHtml) {
        return htmlspecialchars($html, ENT_QUOTES, 'UTF-8');
    }
    
    // 危険なタグとスクリプトを除去
    $html = preg_replace('/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/i', '', $html);
    $html = preg_replace('/<iframe\b[^<]*(?:(?!<\/iframe>)<[^<]*)*<\/iframe>/i', '', $html);
    $html = preg_replace('/on\w+\s*=\s*["\'][^"\']*["\']/i', '', $html); // onclickなどのイベントハンドラを除去
    $html = preg_replace('/javascript:/i', '', $html);
    
    return $html;
}

/**
 * 記事コンテンツ用のサニタイズ
 * 
 * @param string $content 記事コンテンツ
 * @return string サニタイズされたコンテンツ
 */
function sanitizeArticleContent($content) {
    $html = sanitizeHtml($content, true);
    
    // 空の段落（<p><br></p> や <p></p>）を軽量な改行に変換
    $html = preg_replace('/<p>\s*<br\s*\/?>\s*<\/p>/i', '<p class="empty-line"></p>', $html);
    $html = preg_replace('/<p>\s*<\/p>/i', '<p class="empty-line"></p>', $html);
    
    // 連続する空行を1つにまとめる
    $html = preg_replace('/(<p class="empty-line"><\/p>\s*){2,}/', '<p class="empty-line"></p>', $html);
    
    return $html;
}
