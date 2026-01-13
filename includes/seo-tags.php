<?php
/**
 * SEOタグ出力ヘルパー
 * Google Analytics / Search Console のタグを出力
 */

/**
 * Google Search Console と Google Analytics のタグを出力
 * @param PDO $db データベース接続
 */
function outputSeoTags($db) {
    // Google Search Console 認証
    $searchConsole = getSiteSetting($db, 'google_search_console', '');
    if (!empty($searchConsole)) {
        echo '<meta name="google-site-verification" content="' . htmlspecialchars($searchConsole) . '">' . "\n";
    }
    
    // Google Analytics（遅延読み込み）
    $gaId = getSiteSetting($db, 'google_analytics_id', '');
    if (!empty($gaId)) {
        echo '<script>' . "\n";
        echo '(function(){' . "\n";
        echo '    var loaded = false;' . "\n";
        echo '    function loadGA() {' . "\n";
        echo '        if (loaded) return;' . "\n";
        echo '        loaded = true;' . "\n";
        echo '        var s = document.createElement("script");' . "\n";
        echo '        s.async = true;' . "\n";
        echo '        s.src = "https://www.googletagmanager.com/gtag/js?id=' . htmlspecialchars($gaId) . '";' . "\n";
        echo '        document.head.appendChild(s);' . "\n";
        echo '        window.dataLayer = window.dataLayer || [];' . "\n";
        echo '        function gtag(){dataLayer.push(arguments);}' . "\n";
        echo '        window.gtag = gtag;' . "\n";
        echo "        gtag('js', new Date());" . "\n";
        echo "        gtag('config', '" . htmlspecialchars($gaId) . "');" . "\n";
        echo '    }' . "\n";
        echo '    ["scroll", "click", "touchstart", "mousemove"].forEach(function(e) {' . "\n";
        echo '        document.addEventListener(e, loadGA, {once: true, passive: true});' . "\n";
        echo '    });' . "\n";
        echo '    setTimeout(loadGA, 3000);' . "\n";
        echo '})();' . "\n";
        echo '</script>' . "\n";
    }
}
