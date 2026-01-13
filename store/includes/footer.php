    </main>
    
    <!-- フッター -->
    <footer class="bg-gray-800 text-white mt-12 lg:pb-8 pb-24">
        <div class="max-w-6xl mx-auto px-4 py-8">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-6 mb-6 text-sm">
                <div>
                    <h3 class="font-bold mb-3">ストア</h3>
                    <ul class="space-y-2 text-gray-400">
                        <li><a href="/store/" class="hover:text-white">商品一覧</a></li>
                        <li><a href="/store/services/" class="hover:text-white">サービス</a></li>
                        <li><a href="/store/cart.php" class="hover:text-white">カート</a></li>
                    </ul>
                </div>
                <div>
                    <h3 class="font-bold mb-3">マイページ</h3>
                    <ul class="space-y-2 text-gray-400">
                        <?php if ($isLoggedInUser): ?>
                        <li><a href="/store/mypage.php" class="hover:text-white">マイページ</a></li>
                        <li><a href="/store/transactions/" class="hover:text-white">取引一覧</a></li>
                        <li><a href="/store/orders.php" class="hover:text-white">注文履歴</a></li>
                        <li><a href="/store/bookshelf.php" class="hover:text-white">本棚</a></li>
                        <?php else: ?>
                        <li><a href="/store/login.php" class="hover:text-white">ログイン</a></li>
                        <li><a href="/store/register.php" class="hover:text-white">会員登録</a></li>
                        <?php endif; ?>
                    </ul>
                </div>
                <div>
                    <h3 class="font-bold mb-3">ヘルプ</h3>
                    <ul class="space-y-2 text-gray-400">
                        <li><a href="/store/contact.php" class="hover:text-white">お問い合わせ</a></li>
                        <li><a href="/store/tokushoho.php" class="hover:text-white">特定商取引法</a></li>
                    </ul>
                </div>
                <div>
                    <h3 class="font-bold mb-3">規約</h3>
                    <ul class="space-y-2 text-gray-400">
                        <li><a href="/store/terms.php" class="hover:text-white">利用規約</a></li>
                        <li><a href="/store/privacy.php" class="hover:text-white">プライバシーポリシー</a></li>
                    </ul>
                </div>
            </div>
            <div class="border-t border-gray-700 pt-6 flex flex-col sm:flex-row items-center justify-between gap-3 text-sm text-gray-500">
                <p>&copy; <?= date('Y') ?> ぷれぐら！PLAYGROUND</p>
                <a href="/" class="text-gray-400 hover:text-white">
                    <i class="fas fa-external-link-alt mr-1"></i>ぷれぐら！トップへ
                </a>
            </div>
        </div>
    </footer>
    
    <!-- モバイル用下部固定ナビゲーション（index.phpと同じ構造） -->
    <nav id="store-bottom-nav" class="fixed bottom-0 left-0 right-0 z-50 bg-white/95 backdrop-blur-md border-t border-gray-200 pb-[env(safe-area-inset-bottom)] shadow-[0_-5px_20px_rgba(0,0,0,0.05)] lg:hidden">
        <div class="max-w-3xl mx-auto px-2">
            <div class="flex justify-around items-center h-16 md:h-20">
                <!-- ストア -->
                <a href="/store/" class="store-nav-item flex-1 flex flex-col items-center justify-center gap-1 py-1 <?= $currentPage === 'index' ? 'active' : '' ?>">
                    <div class="nav-icon-box relative p-1 rounded-xl transition-transform duration-300">
                        <i class="fas fa-store text-lg md:text-xl <?= $currentPage === 'index' ? 'text-store-primary' : 'text-gray-400' ?> transition-colors duration-300"></i>
                    </div>
                    <span class="text-[10px] md:text-xs font-bold <?= $currentPage === 'index' ? 'text-store-primary' : 'text-gray-400' ?> transition-colors duration-300">商品</span>
                </a>
                
                <!-- サービス -->
                <?php $isServicesPage = strpos($_SERVER['REQUEST_URI'], '/services') !== false; ?>
                <a href="/store/services/" class="store-nav-item flex-1 flex flex-col items-center justify-center gap-1 py-1 <?= $isServicesPage ? 'active' : '' ?>">
                    <div class="nav-icon-box relative p-1 rounded-xl transition-transform duration-300">
                        <i class="fas fa-paint-brush text-lg md:text-xl <?= $isServicesPage ? 'text-store-primary' : 'text-gray-400' ?> transition-colors duration-300"></i>
                    </div>
                    <span class="text-[10px] md:text-xs font-bold <?= $isServicesPage ? 'text-store-primary' : 'text-gray-400' ?> transition-colors duration-300">依頼</span>
                </a>
                
                <!-- 本棚 -->
                <a href="/store/bookshelf.php" class="store-nav-item flex-1 flex flex-col items-center justify-center gap-1 py-1 <?= $currentPage === 'bookshelf' ? 'active' : '' ?>">
                    <div class="nav-icon-box relative p-1 rounded-xl transition-transform duration-300">
                        <i class="fas fa-book text-lg md:text-xl <?= $currentPage === 'bookshelf' ? 'text-store-primary' : 'text-gray-400' ?> transition-colors duration-300"></i>
                    </div>
                    <span class="text-[10px] md:text-xs font-bold <?= $currentPage === 'bookshelf' ? 'text-store-primary' : 'text-gray-400' ?> transition-colors duration-300">本棚</span>
                </a>
                
                <!-- カート -->
                <a href="/store/cart.php" class="store-nav-item flex-1 flex flex-col items-center justify-center gap-1 py-1 <?= $currentPage === 'cart' ? 'active' : '' ?>">
                    <div class="nav-icon-box relative p-1 rounded-xl transition-transform duration-300">
                        <i class="fas fa-shopping-cart text-lg md:text-xl <?= $currentPage === 'cart' ? 'text-store-primary' : 'text-gray-400' ?> transition-colors duration-300"></i>
                        <?php if ($cartCount > 0): ?>
                        <span class="absolute -top-1 -right-1 bg-red-500 text-white text-[10px] min-w-[18px] h-[18px] rounded-full flex items-center justify-center font-bold"><?= min($cartCount, 99) ?></span>
                        <?php endif; ?>
                    </div>
                    <span class="text-[10px] md:text-xs font-bold <?= $currentPage === 'cart' ? 'text-store-primary' : 'text-gray-400' ?> transition-colors duration-300">カート</span>
                </a>
                
                <!-- 注文履歴 -->
                <a href="/store/orders.php" class="store-nav-item flex-1 flex flex-col items-center justify-center gap-1 py-1 <?= in_array($currentPage, ['orders', 'order']) ? 'active' : '' ?>">
                    <div class="nav-icon-box relative p-1 rounded-xl transition-transform duration-300">
                        <i class="fas fa-receipt text-xl md:text-2xl <?= in_array($currentPage, ['orders', 'order']) ? 'text-store-primary' : 'text-gray-400' ?> transition-colors duration-300"></i>
                    </div>
                    <span class="text-[10px] md:text-xs font-bold <?= in_array($currentPage, ['orders', 'order']) ? 'text-store-primary' : 'text-gray-400' ?> transition-colors duration-300 tracking-wide">ORDER</span>
                </a>
                
                <!-- マイページ/ログイン -->
                <?php if ($isLoggedInUser): ?>
                <a href="/store/mypage.php" class="store-nav-item flex-1 flex flex-col items-center justify-center gap-1 py-1 <?= in_array($currentPage, ['mypage', 'profile', 'address', 'favorites']) ? 'active' : '' ?>">
                    <div class="nav-icon-box relative p-1 rounded-xl transition-transform duration-300">
                        <i class="fas fa-user text-xl md:text-2xl <?= in_array($currentPage, ['mypage', 'profile', 'address', 'favorites']) ? 'text-store-primary' : 'text-gray-400' ?> transition-colors duration-300"></i>
                    </div>
                    <span class="text-[10px] md:text-xs font-bold <?= in_array($currentPage, ['mypage', 'profile', 'address', 'favorites']) ? 'text-store-primary' : 'text-gray-400' ?> transition-colors duration-300 tracking-wide">MY</span>
                </a>
                <?php else: ?>
                <a href="/store/login.php" class="store-nav-item flex-1 flex flex-col items-center justify-center gap-1 py-1 <?= $currentPage === 'login' ? 'active' : '' ?>">
                    <div class="nav-icon-box relative p-1 rounded-xl transition-transform duration-300">
                        <i class="fas fa-sign-in-alt text-xl md:text-2xl <?= $currentPage === 'login' ? 'text-store-primary' : 'text-gray-400' ?> transition-colors duration-300"></i>
                    </div>
                    <span class="text-[10px] md:text-xs font-bold <?= $currentPage === 'login' ? 'text-store-primary' : 'text-gray-400' ?> transition-colors duration-300 tracking-wide">LOGIN</span>
                </a>
                <?php endif; ?>
            </div>
        </div>
    </nav>
</body>
</html>
