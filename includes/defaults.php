<?php
/**
 * デフォルト値定義ファイル
 * アプリケーション全体で使用するデフォルト値を一元管理
 */

// ストアのデフォルトカテゴリ
define('DEFAULT_PRODUCT_CATEGORIES', [
    ['slug' => 'manga', 'name' => '漫画', 'icon' => 'fa-book', 'color' => '#FF6B35', 'sort_order' => 1],
    ['slug' => 'illustration', 'name' => 'イラスト集', 'icon' => 'fa-images', 'color' => '#4CAF50', 'sort_order' => 2],
]);

// デフォルトFAQ
define('DEFAULT_STORE_FAQ', [
    [
        'category' => '購入について',
        'question' => 'デジタル商品はどこで見れますか？',
        'answer' => 'ご購入後、マイページの「本棚」からすぐにご覧いただけます。ログイン後、下部メニューの「本棚」をタップしてください。',
        'sort_order' => 1
    ],
    [
        'category' => '購入について',
        'question' => '支払い方法は何がありますか？',
        'answer' => 'クレジットカード（Visa、Mastercard、American Express、JCB）でお支払いいただけます。',
        'sort_order' => 2
    ],
    [
        'category' => '購入について',
        'question' => '領収書は発行できますか？',
        'answer' => 'マイページの注文履歴から、各注文の詳細画面で領収書をダウンロードできます。',
        'sort_order' => 3
    ],
    [
        'category' => '配送について',
        'question' => '配送にはどのくらいかかりますか？',
        'answer' => '通常、ご注文から3〜5営業日でお届けいたします。土日祝日は発送業務をお休みしております。',
        'sort_order' => 4
    ],
    [
        'category' => '配送について',
        'question' => '配送状況を確認できますか？',
        'answer' => '発送完了後、追跡番号をメールでお知らせいたします。追跡番号で配送状況をご確認いただけます。',
        'sort_order' => 5
    ],
    [
        'category' => '配送について',
        'question' => '届け先を変更できますか？',
        'answer' => '発送前であればマイページの「配送先住所」から変更可能です。発送後の変更はできませんので、お問い合わせください。',
        'sort_order' => 6
    ],
    [
        'category' => '返品・交換について',
        'question' => '返品・交換は可能ですか？',
        'answer' => '商品到着後7日以内であれば、未開封品に限り返品・交換を承ります。お問い合わせフォームよりご連絡ください。',
        'sort_order' => 7
    ],
    [
        'category' => '返品・交換について',
        'question' => 'デジタル商品の返品はできますか？',
        'answer' => 'デジタル商品は商品の性質上、ご購入後の返品・返金はお受けできません。',
        'sort_order' => 8
    ],
    [
        'category' => 'アカウントについて',
        'question' => 'パスワードを忘れました',
        'answer' => 'ログイン画面の「パスワードを忘れた」からリセット用のメールを送信できます。',
        'sort_order' => 9
    ],
    [
        'category' => 'アカウントについて',
        'question' => '退会したいのですが',
        'answer' => 'マイページの「アカウント設定」から退会手続きができます。なお、退会後は購入したデジタル商品も閲覧できなくなりますのでご注意ください。',
        'sort_order' => 10
    ],
]);

// FAQカテゴリのデフォルト
define('DEFAULT_FAQ_CATEGORIES', [
    '購入について',
    '配送について',
    '返品・交換について',
    'アカウントについて',
    'その他'
]);

// ストアのテーマカラー
define('STORE_PRIMARY_COLOR', '#FF6B35');
define('STORE_SECONDARY_COLOR', '#FBBF24');

/**
 * デフォルトカテゴリをDBに挿入するヘルパー関数
 */
function insertDefaultCategories($db) {
    $categories = DEFAULT_PRODUCT_CATEGORIES;
    foreach ($categories as $cat) {
        try {
            $stmt = $db->prepare("
                INSERT IGNORE INTO product_categories (name, slug, icon, color, sort_order, is_active)
                VALUES (?, ?, ?, ?, ?, 1)
            ");
            $stmt->execute([$cat['name'], $cat['slug'], $cat['icon'], $cat['color'], $cat['sort_order']]);
        } catch (PDOException $e) {
            // 重複エラーは無視
        }
    }
}

/**
 * デフォルトFAQをDBに挿入するヘルパー関数
 */
function insertDefaultFaq($db) {
    $faqs = DEFAULT_STORE_FAQ;
    foreach ($faqs as $faq) {
        try {
            $stmt = $db->prepare("
                INSERT IGNORE INTO store_faq (category, question, answer, sort_order, is_published)
                VALUES (?, ?, ?, ?, 1)
            ");
            $stmt->execute([$faq['category'], $faq['question'], $faq['answer'], $faq['sort_order']]);
        } catch (PDOException $e) {
            // 重複エラーは無視
        }
    }
}

/**
 * テーブル存在確認ヘルパー
 */
function tableExists($db, $tableName) {
    try {
        $db->query("SELECT 1 FROM `$tableName` LIMIT 1");
        return true;
    } catch (PDOException $e) {
        return false;
    }
}
