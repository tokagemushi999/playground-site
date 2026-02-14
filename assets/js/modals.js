/**
 * モーダル共通JavaScript
 * 使用ページ: index.php, creator.php
 * 
 * 使用前に ModalConfig を設定してください:
 * window.ModalConfig = {
 *     works: [],           // 作品データ配列
 *     collections: [],     // コレクションデータ配列
 *     creatorName: '',     // クリエイター名（ステッカー詳細用）
 *     baseImagePath: '/'   // 画像パスのベース
 * };
 */

(function() {
    'use strict';

    // ========================================
    // グローバル変数
    // ========================================
    
    let currentCollection = null;
    let currentLineStampIndex = 0;
    window.currentLineStamps = [];
    window.currentViewerBg = 'dark';

    // ========================================
    // ユーティリティ関数
    // ========================================

    /**
     * 画像パスを正規化
     */
    function getImagePath(path) {
        if (!path) return '';
        const base = (window.ModalConfig && window.ModalConfig.baseImagePath) || '/';
        if (path.startsWith('http://') || path.startsWith('https://') || path.startsWith('/')) {
            return path;
        }
        return base + path;
    }

    /**
     * 画像/動画パスから適切なメディア要素を返す
     */
    function getMediaElement(img, className = '', style = '', alt = '', webmExists = false) {
        if (!img) return `<img src="/placeholder.svg" class="${className}" style="${style}" alt="${alt}">`;
        
        const path = getImagePath(img);
        const isWebm = path.toLowerCase().endsWith('.webm');
        const isGif = path.toLowerCase().endsWith('.gif');
        
        if (isWebm || (isGif && webmExists)) {
            const webmPath = isWebm ? path : path.replace(/\.gif$/i, '.webm');
            const classAttr = className ? ` class="${className}"` : '';
            const styleAttr = style ? ` style="${style}"` : '';
            return `<video autoplay loop muted playsinline${classAttr}${styleAttr} onloadedmetadata="this.play()" onended="this.currentTime=0;this.play()"><source src="${webmPath}" type="video/webm"><img src="${path}" alt="${alt}"></video>`;
        }
        
        return `<img src="${path}" class="${className}" style="${style}" alt="${alt}" loading="lazy">`;
    }

    /**
     * ステッカーグリッドの列数を計算
     */
    function calcStickerGridCols(count, isLineStamp) {
        const n = Math.max(1, parseInt(count, 10) || 1);
        const isMobile = window.innerWidth < 640;
        
        // LINEスタンプは常に4列以上
        if (isLineStamp) {
            return isMobile ? 4 : 5;
        }
        
        // 通常のステッカー
        if (isMobile) {
            return Math.min(2, n);
        }
        
        if (n <= 1) return 1;
        if (n <= 4) return 2;
        if (n <= 9) return 3;
        if (n <= 16) return 4;
        if (n <= 25) return 5;
        return 6;
    }

    /**
     * ステッカーグリッドのレイアウトを適用
     */
    function applyStickerGridLayout(stickerCount, isLineStamp) {
        const grid = document.getElementById('sticker-group-grid');
        if (!grid) return;
        const cols = calcStickerGridCols(stickerCount, isLineStamp);
        grid.style.gridTemplateColumns = `repeat(${cols}, minmax(0, 1fr))`;
    }

    // ========================================
    // LINEスタンプビューアー
    // ========================================

    /**
     * LINEスタンプビューアーを開く
     */
    function openLineStampViewer(index) {
        if (!window.currentLineStamps || window.currentLineStamps.length === 0) return;
        
        currentLineStampIndex = index;
        const viewer = document.getElementById('line-stamp-viewer');
        if (!viewer) return;
        
        // 背景色を適用
        viewer.classList.remove('line-stamp-bg-dark', 'line-stamp-bg-light', 'line-stamp-bg-check', 'line-stamp-bg-green');
        viewer.classList.add('line-stamp-bg-' + (window.currentViewerBg || 'dark'));
        
        updateLineStampDisplay();
        
        viewer.classList.remove('hidden');
        viewer.classList.add('flex');
        document.body.style.overflow = 'hidden';
    }

    /**
     * LINEスタンプビューアーを閉じる
     */
    function closeLineStampViewer() {
        const viewer = document.getElementById('line-stamp-viewer');
        if (!viewer) return;
        
        viewer.classList.add('hidden');
        viewer.classList.remove('flex');
        document.body.style.overflow = '';
    }

    /**
     * LINEスタンプ表示を更新
     */
    function updateLineStampDisplay() {
        const stamps = window.currentLineStamps;
        if (!stamps || stamps.length === 0) return;
        
        const stamp = stamps[currentLineStampIndex];
        if (!stamp) return;
        
        const img = document.getElementById('line-stamp-image');
        const current = document.getElementById('line-stamp-current');
        const total = document.getElementById('line-stamp-total');
        const prevBtn = document.getElementById('line-stamp-prev');
        const nextBtn = document.getElementById('line-stamp-next');
        
        if (img) img.src = getImagePath(stamp.image);
        if (current) current.textContent = currentLineStampIndex + 1;
        if (total) total.textContent = stamps.length;
        
        // ナビゲーションボタンの表示制御
        if (prevBtn) prevBtn.style.display = currentLineStampIndex > 0 ? '' : 'none';
        if (nextBtn) nextBtn.style.display = currentLineStampIndex < stamps.length - 1 ? '' : 'none';
    }

    /**
     * 前のスタンプへ
     */
    function prevLineStamp() {
        if (currentLineStampIndex > 0) {
            currentLineStampIndex--;
            updateLineStampDisplay();
        }
    }

    /**
     * 次のスタンプへ
     */
    function nextLineStamp() {
        if (currentLineStampIndex < window.currentLineStamps.length - 1) {
            currentLineStampIndex++;
            updateLineStampDisplay();
        }
    }

    // ========================================
    // ステッカーグループモーダル
    // ========================================

    // コレクションデータプリロード用の変数
    let pendingCollectionData = null;
    let isCollectionAnimating = false;

    /**
     * アニメーション付きでステッカーグループモーダルを開く
     * クリック時にアニメーションを開始し、同時にデータを準備
     */
    function openStickerGroupModalWithAnimation(element, groupId) {
        if (isCollectionAnimating) return;
        isCollectionAnimating = true;
        
        // コレクションカードにタップアニメーションを追加
        const card = element.querySelector('.collection-card') || element;
        card.style.transition = 'transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1)';
        card.style.transform = 'scale(0.92)';
        
        // データを事前に準備
        const collections = (window.ModalConfig && window.ModalConfig.collections) || [];
        const numGroupId = Number(groupId);
        pendingCollectionData = collections.find(g => Number(g.id) === numGroupId);
        
        // アニメーション中間（跳ね返り）
        setTimeout(() => {
            card.style.transform = 'scale(1.03)';
        }, 150);
        
        // アニメーション完了後にモーダルを開く
        setTimeout(() => {
            card.style.transform = '';
            card.style.transition = '';
            
            // 必ずモーダルを開く
            openStickerGroupModal(groupId);
            
            isCollectionAnimating = false;
            pendingCollectionData = null;
        }, 300);
    }

    /**
     * ステッカーグループモーダルを開く
     */
    function openStickerGroupModal(groupId) {
        const collections = (window.ModalConfig && window.ModalConfig.collections) || [];
        const numGroupId = Number(groupId);
        const group = collections.find(g => Number(g.id) === numGroupId);
        if (!group) {
            console.error('Collection not found:', groupId, 'Available:', collections.map(c => c.id));
            return;
        }

        currentCollection = group;
        window.currentViewerBg = group.viewer_bg || 'dark';
        
        const modal = document.getElementById('sticker-group-modal');
        const title = document.getElementById('sticker-group-title');
        const grid = document.getElementById('sticker-group-grid');
        const count = document.getElementById('sticker-group-count');
        const modalHeader = document.getElementById('sticker-group-header');
        const modalIcon = document.getElementById('sticker-group-icon');

        if (title) title.textContent = group.title || 'コレクション';

        const rawStickers = group.stickers || [];
        const stickers = Array.isArray(rawStickers) ? rawStickers : Object.values(rawStickers || {});
        const stickerCount = group.sticker_count || stickers.length || 0;
        
        if (count) count.textContent = stickerCount + '枚';

        // LINEスタンプかどうかチェック
        const hasLineStamp = stickers.some(s => s.work_type === 'line_stamp');
        
        // ヘッダーの色を変更
        if (modalHeader && modalIcon) {
            if (hasLineStamp) {
                modalHeader.className = 'bg-[#06C755] px-3 py-2 sm:px-6 sm:py-4 flex items-center justify-between flex-shrink-0';
                modalIcon.innerHTML = '<i class="fab fa-line text-white text-sm sm:text-base"></i>';
            } else {
                modalHeader.className = 'bg-sky-500 px-3 py-2 sm:px-6 sm:py-4 flex items-center justify-between flex-shrink-0';
                modalIcon.innerHTML = '<i class="fas fa-layer-group text-white text-sm sm:text-base"></i>';
            }
        }

        // ストアボタンの処理
        const existingBtn = document.getElementById('collection-store-btn');
        if (existingBtn) existingBtn.remove();

        if (group.store_url) {
            const scrollArea = document.getElementById('sticker-group-scroll');
            const btnColor = hasLineStamp 
                ? 'bg-[#06C755] hover:bg-[#05b34c]' 
                : 'bg-gradient-to-r from-green-500 to-emerald-500 hover:from-green-600 hover:to-emerald-600';
            const btnHtml = `
                <div id="collection-store-btn" class="w-full px-4 py-3 bg-white/80 backdrop-blur border-b border-gray-100 flex justify-center flex-shrink-0 z-10">
                    <a href="${group.store_url}" target="_blank" rel="noopener noreferrer" 
                       class="${btnColor} text-white px-6 py-3 rounded-full font-bold shadow-lg hover:shadow-xl hover:-translate-y-0.5 transition-all flex items-center gap-2">
                        <i class="${hasLineStamp ? 'fab fa-line' : 'fas fa-external-link-alt'}"></i>
                        <span>${group.store_text || 'ストアで見る'}</span>
                    </a>
                </div>
            `;
            if (scrollArea) scrollArea.insertAdjacentHTML('beforebegin', btnHtml);
        }

        // グリッドレイアウト適用
        applyStickerGridLayout(stickers.length, hasLineStamp);

        // LINEスタンプ用のインデックスを保持
        const lineStamps = stickers.filter(s => s.work_type === 'line_stamp');
        window.currentLineStamps = lineStamps;

        // ステッカーHTML生成
        const stickersHtml = stickers.map((sticker, index) => {
            const cropPosition = sticker.crop_position || 'center';
            const isLineStamp = sticker.work_type === 'line_stamp';
            
            // LINEスタンプ（超軽量表示）
            if (isLineStamp) {
                const stampIndex = lineStamps.findIndex(s => s.id === sticker.id);
                return `
                    <div class="line-stamp-item cursor-pointer" onclick="openLineStampViewer(${stampIndex})">
                        <img data-src="${getImagePath(sticker.image)}" 
                            class="line-stamp-thumb w-full h-full object-contain"
                            alt="">
                    </div>
                `;
            }
            
            // 通常のステッカー
            return `
                <div class="group cursor-pointer" onclick="openStickerDetailModal(${sticker.id})">
                    <div class="aspect-square overflow-hidden group-hover:scale-105 transition-transform duration-200 relative">
                        <img src="${getImagePath(sticker.image)}" 
                            class="w-full h-full object-contain" 
                            style="object-position: ${cropPosition}" 
                            loading="lazy" alt="">
                        <div class="absolute inset-0 bg-gradient-to-t from-black/60 to-transparent opacity-0 group-hover:opacity-100 transition-opacity flex items-end p-2">
                            <span class="text-white text-xs font-bold truncate">${sticker.title || ''}</span>
                        </div>
                    </div>
                </div>
            `;
        }).join('');

        if (grid) grid.innerHTML = stickersHtml;
        
        // LINEスタンプの遅延読み込み
        if (hasLineStamp && grid) {
            const lazyImages = grid.querySelectorAll('img[data-src]');
            const scrollContainer = document.getElementById('sticker-group-scroll');
            const imageObserver = new IntersectionObserver((entries, observer) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        const img = entry.target;
                        img.src = img.dataset.src;
                        img.removeAttribute('data-src');
                        observer.unobserve(img);
                    }
                });
            }, { root: scrollContainer, rootMargin: '100px' });
            lazyImages.forEach(img => imageObserver.observe(img));
        }

        // スクロール位置を先頭へ
        const scroller = document.getElementById('sticker-group-scroll');
        if (scroller) scroller.scrollTop = 0;

        // モーダル表示
        if (modal) {
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            document.body.style.overflow = 'hidden';
        }
    }

    /**
     * ステッカーグループモーダルを閉じる
     */
    function closeStickerGroupModal() {
        const modal = document.getElementById('sticker-group-modal');
        if (!modal) return;
        
        modal.classList.add('hidden');
        modal.classList.remove('flex');
        document.body.style.overflow = '';
        currentCollection = null;
    }

    // ========================================
    // ステッカー詳細モーダル
    // ========================================

    /**
     * ステッカー詳細モーダルを開く
     */
    function openStickerDetailModal(stickerId) {
        const works = (window.ModalConfig && window.ModalConfig.works) || [];
        const sticker = works.find(w => w.id === stickerId);
        if (!sticker) return;
        
        const modal = document.getElementById('sticker-detail-modal');
        const container = document.getElementById('sticker-detail-container');
        if (!modal || !container) return;
        
        const hasBackImage = sticker.back_image && sticker.back_image.trim() !== '';
        const creatorName = (window.ModalConfig && window.ModalConfig.creatorName) || '';
        
        const html = `
            <div class="flex flex-col items-center">
                <div class="relative mb-4" id="sticker-card-container">
                    <div class="sticker-card w-64 h-64 sm:w-80 sm:h-80 md:w-96 md:h-96 relative" id="sticker-card">
                        <div class="sticker-card-front absolute inset-0 rounded-2xl overflow-hidden shadow-2xl">
                            <img src="${getImagePath(sticker.image)}" class="w-full h-full object-contain bg-white">
                        </div>
                        ${hasBackImage ? `
                        <div class="sticker-card-back absolute inset-0 rounded-2xl overflow-hidden shadow-2xl">
                            <img src="${getImagePath(sticker.back_image)}" class="w-full h-full object-contain bg-white">
                        </div>
                        ` : ''}
                    </div>
                </div>
                <h2 class="text-xl md:text-2xl font-bold text-gray-800 mb-2 text-center">${sticker.title || 'ステッカー'}</h2>
                <p class="text-sm text-gray-600 mb-4"><i class="fas fa-user mr-1"></i>${creatorName}</p>
                ${sticker.description ? `<p class="text-sm text-gray-600 text-center mb-4 max-w-md">${sticker.description}</p>` : ''}
                ${hasBackImage ? `
                <button onclick="flipStickerCard()" class="bg-gradient-to-r from-purple-500 to-pink-500 hover:from-purple-600 hover:to-pink-600 text-white px-6 py-2 rounded-full font-bold transition-all hover:scale-105 shadow-lg">
                    <i class="fas fa-sync-alt mr-2"></i>裏面を見る
                </button>
                ` : ''}
            </div>
        `;
        
        container.innerHTML = html;
        
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }

    /**
     * ステッカー詳細モーダルを閉じる
     */
    function closeStickerDetailModal() {
        const modal = document.getElementById('sticker-detail-modal');
        if (!modal) return;
        
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }

    /**
     * ステッカーカードを裏返す
     */
    function flipStickerCard() {
        const card = document.getElementById('sticker-card');
        if (card) {
            card.classList.toggle('flipped');
        }
    }

    // ========================================
    // イベントリスナー
    // ========================================

    // リサイズ時にグリッドレイアウトを再計算
    window.addEventListener('resize', function() {
        if (!currentCollection) return;
        const rawStickers = currentCollection.stickers || [];
        const stickers = Array.isArray(rawStickers) ? rawStickers : Object.values(rawStickers || {});
        const hasLineStamp = stickers.some(s => s.work_type === 'line_stamp');
        applyStickerGridLayout(stickers.length, hasLineStamp);
    });

    // LINEスタンプビューアーのキーボード操作
    document.addEventListener('keydown', function(e) {
        const viewer = document.getElementById('line-stamp-viewer');
        if (!viewer || viewer.classList.contains('hidden')) return;
        
        if (e.key === 'Escape') {
            closeLineStampViewer();
        } else if (e.key === 'ArrowLeft') {
            prevLineStamp();
        } else if (e.key === 'ArrowRight') {
            nextLineStamp();
        }
    });

    // LINEスタンプビューアーのスワイプ操作
    (function() {
        const viewer = document.getElementById('line-stamp-viewer');
        if (!viewer) return;
        
        let touchStartX = 0;
        let touchEndX = 0;
        
        viewer.addEventListener('touchstart', function(e) {
            touchStartX = e.changedTouches[0].screenX;
        }, { passive: true });
        
        viewer.addEventListener('touchend', function(e) {
            touchEndX = e.changedTouches[0].screenX;
            const diff = touchStartX - touchEndX;
            
            if (Math.abs(diff) > 50) {
                if (diff > 0) {
                    nextLineStamp();
                } else {
                    prevLineStamp();
                }
            }
        }, { passive: true });
    })();

    // ========================================
    // グローバル公開
    // ========================================
    
    window.openLineStampViewer = openLineStampViewer;
    window.closeLineStampViewer = closeLineStampViewer;
    window.updateLineStampDisplay = updateLineStampDisplay;
    window.prevLineStamp = prevLineStamp;
    window.nextLineStamp = nextLineStamp;
    
    window.openStickerGroupModal = openStickerGroupModal;
    window.openStickerGroupModalWithAnimation = openStickerGroupModalWithAnimation;
    window.closeStickerGroupModal = closeStickerGroupModal;
    window.openStickerDetailModal = openStickerDetailModal;
    window.closeStickerDetailModal = closeStickerDetailModal;
    window.flipStickerCard = flipStickerCard;
    
    window.calcStickerGridCols = calcStickerGridCols;
    window.applyStickerGridLayout = applyStickerGridLayout;
    window.getImagePath = getImagePath;
    window.getMediaElement = getMediaElement;

})();
