<?php
/**
 * モーダル共通インクルード
 * 使用ページ: index.php, creator.php
 * 
 * 使用方法:
 * 1. ページ末尾で include 'includes/modals.php';
 * 2. JSで ModalConfig を設定:
 *    window.ModalConfig = {
 *        works: <?= json_encode($works) ?>,
 *        collections: <?= json_encode($collections) ?>,
 *        creatorName: '<?= htmlspecialchars($creatorName) ?>'
 *    };
 */
?>

<!-- 共通CSS読み込み -->
<link rel="stylesheet" href="/assets/css/modals.css">

<!-- ステッカーグループモーダル -->
<div id="sticker-group-modal" class="fixed inset-0 bg-black/90 z-[100] hidden items-center justify-center sticker-modal-container" onclick="event.target === this && closeStickerGroupModal()">
    <div class="bg-white rounded-2xl sm:rounded-3xl max-w-4xl w-full overflow-hidden shadow-2xl relative flex flex-col sticker-modal-content" onclick="event.stopPropagation()">
        <!-- ヘッダー -->
        <div id="sticker-group-header" class="bg-sky-500 px-3 py-2 sm:px-6 sm:py-4 flex items-center justify-between flex-shrink-0">
            <div class="flex items-center gap-2 sm:gap-3 min-w-0">
                <div id="sticker-group-icon" class="w-7 h-7 sm:w-9 sm:h-9 rounded-full bg-white/20 backdrop-blur flex items-center justify-center flex-shrink-0">
                    <i class="fas fa-layer-group text-white text-sm sm:text-base"></i>
                </div>
                <h2 id="sticker-group-title" class="font-display text-base sm:text-xl text-white truncate">ステッカー</h2>
                <span id="sticker-group-count" class="bg-white/20 backdrop-blur text-white text-xs sm:text-sm font-bold px-2 py-0.5 sm:px-3 sm:py-1 rounded-full flex-shrink-0"></span>
            </div>
            <button onclick="closeStickerGroupModal()" class="w-8 h-8 sm:w-10 sm:h-10 rounded-full bg-white/10 hover:bg-white/20 backdrop-blur flex items-center justify-center transition-colors flex-shrink-0">
                <i class="fas fa-times text-white text-lg sm:text-xl"></i>
            </button>
        </div>
        
        <!-- ステッカーグリッド -->
        <div id="sticker-group-scroll" class="p-3 sm:p-6 overflow-y-auto flex-1 min-h-0">
            <div id="sticker-group-grid" class="grid gap-2 sm:gap-4"></div>
        </div>
    </div>
</div>

<!-- ステッカー詳細モーダル（第2階層） -->
<div id="sticker-detail-modal" class="fixed inset-0 bg-black/95 z-[110] hidden items-center justify-center sticker-detail-container" onclick="event.target === this && closeStickerDetailModal()">
    <div class="bg-white rounded-2xl sm:rounded-3xl max-w-2xl w-full overflow-y-auto shadow-2xl relative p-4 sm:p-6 sticker-detail-content" onclick="event.stopPropagation()">
        <button onclick="closeStickerDetailModal()" class="absolute top-3 right-3 sm:top-4 sm:right-4 w-8 h-8 sm:w-10 sm:h-10 rounded-full bg-gray-100 hover:bg-gray-200 flex items-center justify-center transition-colors z-10">
            <i class="fas fa-times text-gray-600 text-lg sm:text-xl"></i>
        </button>
        <div id="sticker-detail-container"></div>
    </div>
</div>

<!-- LINEスタンプビューアー -->
<div id="line-stamp-viewer" class="fixed inset-0 z-[120] hidden items-center justify-center line-stamp-bg-dark" onclick="closeLineStampViewer()">
    <button onclick="event.stopPropagation(); closeLineStampViewer()" class="absolute top-4 right-4 w-10 h-10 sm:w-12 sm:h-12 rounded-full bg-white/20 hover:bg-white/30 backdrop-blur flex items-center justify-center transition-colors z-20">
        <i class="fas fa-times text-xl line-stamp-close-icon"></i>
    </button>
    <button onclick="event.stopPropagation(); prevLineStamp()" id="line-stamp-prev" class="absolute left-2 sm:left-4 top-1/2 -translate-y-1/2 w-10 h-10 sm:w-12 sm:h-12 rounded-full bg-white/20 hover:bg-white/30 backdrop-blur flex items-center justify-center transition-colors z-20">
        <i class="fas fa-chevron-left text-lg sm:text-xl line-stamp-nav-icon"></i>
    </button>
    <button onclick="event.stopPropagation(); nextLineStamp()" id="line-stamp-next" class="absolute right-2 sm:right-4 top-1/2 -translate-y-1/2 w-10 h-10 sm:w-12 sm:h-12 rounded-full bg-white/20 hover:bg-white/30 backdrop-blur flex items-center justify-center transition-colors z-20">
        <i class="fas fa-chevron-right text-lg sm:text-xl line-stamp-nav-icon"></i>
    </button>
    <div id="line-stamp-container" class="w-[80vw] h-[80vw] max-w-[400px] max-h-[400px] flex items-center justify-center" onclick="event.stopPropagation()">
        <img id="line-stamp-image" src="" class="max-w-full max-h-full object-contain" alt="">
    </div>
    <div id="line-stamp-counter" class="absolute bottom-4 left-1/2 -translate-x-1/2 backdrop-blur text-sm px-4 py-2 rounded-full line-stamp-counter-style" onclick="event.stopPropagation()">
        <span id="line-stamp-current">1</span> / <span id="line-stamp-total">1</span>
    </div>
</div>

<!-- 共通JS読み込み -->
<script src="/assets/js/modals.js"></script>
