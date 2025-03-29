<?php
// ==================================================
// 版本号: 0.0.7
// 更新内容:
// 1. 视窗自适应布局
// 2. 动态尺寸调整
// 3. 无滚动条界面
// ==================================================



define('BASE_DIR', realpath(__DIR__ . '/images'));
define('CACHE_DIR', __DIR__ . '/image_cache');
define('CACHE_TTL', 72000);

// 安全验证
if (!is_dir(BASE_DIR)) die('<h2 style="padding:2rem">图片目录不存在，请创建 '.BASE_DIR.'</h2>');
if (!file_exists(CACHE_DIR) && !mkdir(CACHE_DIR, 0755, true)) die('<h2 style="padding:2rem">无法创建缓存目录</h2>');

// 获取首层子目录
function getFirstLevelSubdirs() {
    $subdirs = [];
    $items = @scandir(BASE_DIR) ?: [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = BASE_DIR . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) $subdirs[] = $item;
    }
    return $subdirs;
}

// 获取图片统计
function getImageCounts() {
    $cacheFile = CACHE_DIR . '/counts.json';
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < CACHE_TTL) {
        return json_decode(file_get_contents($cacheFile), true);
    }

    $counts = ['total' => 0];
    $folders = array_merge(['all'], getFirstLevelSubdirs());
    
    foreach ($folders as $folder) {
        $dir = ($folder === 'all') ? BASE_DIR : BASE_DIR . DIRECTORY_SEPARATOR . $folder;
        $counts[$folder] = count(getAllImages($dir));
        if ($folder !== 'all') $counts['total'] += $counts[$folder];
    }

    file_put_contents($cacheFile, json_encode($counts));
    return $counts;
}

// 获取图片列表
function getAllImages($dir) {
    $cacheKey = md5(realpath($dir));
    $cacheFile = CACHE_DIR . '/' . $cacheKey . '.json';
    
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < CACHE_TTL) {
        return json_decode(file_get_contents($cacheFile), true);
    }

    $images = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && in_array(strtolower($file->getExtension()), ['jpg','jpeg','png','gif','webp'])) {
            $images[] = $file->getPathname();
        }
    }

    file_put_contents($cacheFile, json_encode($images));
    return $images;
}

// 处理AJAX请求
if (isset($_GET['action']) && $_GET['action'] === 'get_image') {
    try {
        $folder = $_GET['folder'] ?? 'all';
        $validFolders = getFirstLevelSubdirs();
        
        $targetDir = ($folder === 'all') ? BASE_DIR : (
            in_array($folder, $validFolders) ? BASE_DIR . DIRECTORY_SEPARATOR . $folder : null
        );
        if (!$targetDir) throw new Exception('无效目录');

        $images = getAllImages($targetDir);
        if (empty($images)) throw new Exception('未找到图片');

        $selected = $images[array_rand($images)];
        
        // 生成安全URL
        $docRoot = realpath($_SERVER['DOCUMENT_ROOT']);
        $filePath = realpath($selected);
        
        if (strpos($filePath, $docRoot) !== 0) {
            throw new Exception('文件路径超出允许范围');
        }
        
        $relativePath = str_replace(
            str_replace('\\', '/', $docRoot),
            '',
            str_replace('\\', '/', $filePath)
        );
        
        $scheme = isset($_SERVER['HTTPS']) ? 'https' : 'http';
        $url = $scheme . '://' . $_SERVER['HTTP_HOST'] . $relativePath;

        // 元数据
        $meta = [
            'name' => basename($selected),
            'size' => round(filesize($selected) / 1024, 1) . ' KB',
            'created' => date("Y-m-d H:i", filectime($selected)),
            'dimensions' => getimagesize($selected)[0] . 'x' . getimagesize($selected)[1]
        ];

        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'url' => $url, 'meta' => $meta]);
        exit;
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <link href="https://fonts.font.im/css?family=Lobster|Monoton|Philosopher" rel="stylesheet"> 
            <link rel="icon" href="data:image/png;base64,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" type="image/x-icon" />
    <title>图片浏览器(O_o)</title>
    <style>
        :root {
            --bg-primary: #f8f9fa;
            --bg-card: #ffffff;
            --text-primary: #2d3436;
            --accent-1: #6c5ce7;
            --accent-2: #a8a5e6;
            --radius-lg: 10px;
            --radius-sm: 4px;
            --shadow: 0 8px 32px rgba(0,0,0,0.1);
            --header-height: 60px;
        }

        .dark-mode {
            --bg-primary: #0f0f0f;
            --bg-card: #1a1a1a;
            --text-primary: #f8f9fa;
            --accent-1: #8476f2;
            --accent-2: #6965d1;
            --shadow: 0 8px 32px rgba(0,0,0,0.3);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html, body {
            height: 100%;
            overflow: hidden;
        }

        body {
            --font-family: 'Inter', system-ui, sans-serif;
            font-family:'Philosopher',Comic Sans MS,Helvetica Neue,Microsoft Yahei,-apple-system,sans-serif!important;
            background: var(--bg-primary);
            color: var(--text-primary);
            display: flex;
            flex-direction: column;
        }

        /* 顶部控制栏 */
        .control-bar {
            height: var(--header-height);
            display: flex;
            gap: 1rem;
            padding: 0 2rem;
            align-items: center;
            background: var(--bg-card);
            box-shadow: var(--shadow);
            flex-shrink: 0;
        }

        /* 主内容区 */
        .main-content {
            flex: 1;
            display: grid;
            grid-template-columns: 240px 1fr 280px;
            gap: 2rem;
            padding: 2rem;
            overflow: hidden;
        }

        /* 左侧导航 */
        .side-nav {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            padding: 1.5rem;
            display: flex;
            flex-direction: column;
            gap: 1rem;
            overflow-y: auto;
        }

        /* 图片显示区 */
        .image-container {
            position: relative;
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            overflow: hidden;
        }

        /* 右侧统计 */
        .stats-panel {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            padding: 1.5rem;
            overflow-y: auto;
            
            display: flex;
            flex-direction: column;
            gap: 1rem;
            
            
        }

        /* 按钮样式 */
        .btn {
            padding: 0.8rem 1.2rem;
            border: none;
            border-radius: var(--radius-sm);
            background: var(--accent-1);
            color: white;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 0.6rem;
            white-space: nowrap;
        }

        .btn:hover {
            background: var(--accent-2);
            transform: translateY(-1px);
        }

        /* 图片元素 */
        .preview-image {
            width: 100%;
            height: 100%;
            object-fit: contain;
            transition: opacity 0.3s;
        }
        
        
        
        

        /* 进度条 */
        .slideshow-progress {
            position: absolute;
            top: 0;
            left: 0;
            height: 3px;
            background: var(--accent-1);
            width: 0%;
            transition: width linear;
            z-index: 10;
        }

        /* 元数据层 */
        .meta-layer {
            position: absolute;
            bottom: 1rem;
            left: 1rem;
            right: 1rem;
            background: rgba(255,255,255,0.9);
            padding: 1rem;
            border-radius: var(--radius-sm);
            backdrop-filter: blur(10px);
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            opacity: 0.7;
        }
        
        .preview-image:hover + .meta-layer {
             --display: none;
             opacity: 0.1;
             transition: opacity 0.5s;
              
        }       
        
        
        

        .dark-mode .meta-layer {
            background: rgba(26,26,26,0.9);
        }
		
		.image-container {
    transition: height 0.3s ease, width 0.3s ease;
    will-change: height, width;
}

        @media (max-width: 900px) {
            .--main-content {
                grid-template-columns: 1fr;
                padding: 1rem;
            }

            .--side-nav, .stats-panel {
                display: none;
            }
        }
        
        @media (max-width: 700px) {
            .main-content {
                grid-template-columns: 90px  1fr;
                padding: 1rem;
            }
            
			.side-nav   button.btn {
				font-size :8px;
				padding :8px;
            }
            .side-nav span {
				display: none;
            }
        }
        
          @media (max-width: 500px) {
            .main-content {
                grid-template-columns: 1fr;
                padding: 1rem;
            }
             .side-nav   {  
					height: 36px; 
					padding: 4px;
					display: block;
					
            }
             .side-nav  button.btn {
			
				   display: inline-block;
				   width: 42px;
				   float: left;
				   margin: 0px;
            }
			
			
        }
              
        
        
        
    </style>
</head>
<body>
    <!-- 顶部控制栏 -->
    <div class="control-bar">
        <button class="btn" id="toggleDarkMode">
            <span id="themeIcon">🌙</span>
            <span id="themeText">暗黑模式</span>
        </button>
        <div style="display: flex; gap: 0.5rem; flex: 1;">
            <button class="btn" id="toggleSlideshow">
                <span id="slideshowIcon">▶️</span>
                <span id="slideshowText">幻灯片</span>
            </button>
            <input type="number" class="btn" 
                   id="slideshowInterval" placeholder="秒" 
                   style="background: var(--bg-card); color: var(--text-primary); width: 80px;">
        </div>
    </div>

    <!-- 主内容区 -->
    <div class="main-content">
        <!-- 左侧导航 -->
        <nav class="side-nav">
            <button class="btn active" data-folder="all">全部图片</button>
            <?php $counts = getImageCounts(); ?>
            <?php foreach (getFirstLevelSubdirs() as $folder): ?>
                <button class="btn" data-folder="<?= htmlspecialchars($folder) ?>">
                    <?= htmlspecialchars($folder) ?>
                    <span style="margin-left: auto"><?= $counts[$folder] ?></span>
                </button>
            <?php endforeach; ?>
        </nav>

        <!-- 图片显示区 -->
        <div class="image-container">
            <div class="slideshow-progress"></div>
            <img class="preview-image" src="" alt="当前图片">
            <div class="meta-layer">
                <div class="meta-item">
                    <div class="meta-label">尺寸</div>
                    <div class="meta-value" id="meta-dimensions">-</div>
                </div>
                <div class="meta-item">
                    <div class="meta-label">文件大小</div>
                    <div class="meta-value" id="meta-size">-</div>
                </div>
                <div class="meta-item">
                    <div class="meta-label">创建时间</div>
                    <div class="meta-value" id="meta-created">-</div>
                </div>
                <div class="meta-item">
                    <div class="meta-label">当前分类</div>
                    <div class="meta-value" id="current-category">全部</div>
                </div>
            </div>
        </div>

        <!-- 右侧统计 -->
        <aside class="stats-panel">
            <h3 style="margin-bottom: 1rem;">统计信息</h3>
            <div class="stats-item">
                <div>总图片数</div>
                <div><?= number_format($counts['total']) ?></div>
            </div>
            <div class="stats-item">
                <div>分类数量</div>
                <div><?= count(getFirstLevelSubdirs()) ?></div>
            </div>
            <div class="stats-item">
                <div>程序版本</div>
                <div>v0.0.7</div>
            </div>
        </aside>
    </div>

    <script>
const config = {
    darkMode: false,
    slideshow: false,
    interval: 5,
    currentFolder: 'all',
    timer: null,
    progressInterval: null,

    // 初始化程序
    init() {
        this.initLayout();
        this.loadConfig();
        this.bindEvents();
        this.loadImage('all');
        // 优化resize监听，添加防抖
        window.addEventListener('resize', () => this.handleResize());
    },

	
	// 新增：处理窗口resize事件
    handleResize() {
        this.throttle(() => {
            this.adjustLayout();
            // 如果正在幻灯片播放，重新计算进度条
            if (this.slideshow) {
                this.stopSlideshow();
                this.startSlideshow();
            }
        }, 100)();
    },
	
	
	    // 优化后的布局调整方法
    adjustLayout() {
        const controlBar = document.querySelector('.control-bar');
        const mainContent = document.querySelector('.main-content');
        const imageContainer = document.querySelector('.image-container');
        
        // 精确计算可用高度
        const controlBarHeight = controlBar.offsetHeight;
        const windowHeight = window.innerHeight;
        const paddingTotal = 40; // 上下各20px的padding
        
        // 主内容区高度 = 窗口高度 - 控制栏高度 - 总padding
        const mainContentHeight = windowHeight - controlBarHeight - paddingTotal;
        mainContent.style.height = `${mainContentHeight}px`;
        
        // 图片容器高度 = 主内容区高度 - 内部padding (2rem = 32px)
        const imageContainerHeight = mainContentHeight - 32;
        imageContainer.style.height = `${imageContainerHeight}px`;
        
        // 动态调整图片容器宽度
        const sideNavWidth = document.querySelector('.side-nav').offsetWidth;
        const statsPanelWidth = document.querySelector('.stats-panel').offsetWidth;
        const availableWidth = window.innerWidth - sideNavWidth - statsPanelWidth - 100; // 100为边距
        imageContainer.style.width = `${Math.max(availableWidth, 400)}px`; // 最小宽度400px
    },

    // 优化后的节流函数
    throttle(fn, delay) {
        let throttleTimer = null;
        return function(...args) {
            if (!throttleTimer) {
                throttleTimer = setTimeout(() => {
                    fn.apply(this, args);
                    throttleTimer = null;
                }, delay);
            }
        };
    },
	
	
    // 布局初始化
    initLayout() {
        document.documentElement.style.overflow = 'hidden';
        document.body.style.overflow = 'hidden';
        this.adjustLayout();
    },





    // 加载配置
    loadConfig() {
        const saved = localStorage.getItem('viewerConfig');
        if (saved) {
            try {
                const data = JSON.parse(saved);
                this.darkMode = data.darkMode ?? false;
                this.slideshow = data.slideshow ?? false;
                this.interval = data.interval ?? 5;
                this.applyConfig();
            } catch(e) {
                console.error('配置加载失败:', e);
            }
        }
    },

    // 保存配置
    saveConfig() {
        localStorage.setItem('viewerConfig', JSON.stringify({
            darkMode: this.darkMode,
            slideshow: this.slideshow,
            interval: this.interval
        }));
    },

    // 应用配置
    applyConfig() {
        // 主题设置
        document.body.classList.toggle('dark-mode', this.darkMode);
        document.getElementById('themeIcon').textContent = this.darkMode ? '☀️' : '🌙';
        document.getElementById('themeText').textContent = this.darkMode ? '明亮模式' : '暗黑模式';

        // 幻灯片控制
        const progressBar = document.querySelector('.slideshow-progress');
        progressBar.style.display = this.slideshow ? 'block' : 'none';
        document.getElementById('slideshowIcon').textContent = this.slideshow ? '⏸️' : '▶️';
        document.getElementById('slideshowText').textContent = this.slideshow ? '停止播放' : '开始播放';

        // 输入框同步
        document.getElementById('slideshowInterval').value = this.interval;

        if (this.slideshow) {
            this.startSlideshow();
        } else {
            this.stopSlideshow();
        }
    },

    // 启动幻灯片
    startSlideshow() {
        this.stopSlideshow();
        const progressBar = document.querySelector('.slideshow-progress');
        
        const updateProgress = () => {
            progressBar.style.transition = `width ${this.interval}s linear`;
            progressBar.style.width = '100%';
            
            this.timer = setTimeout(() => {
                this.loadImage(this.currentFolder);
                progressBar.style.transition = 'none';
                progressBar.style.width = '0%';
                setTimeout(updateProgress, 10);
            }, this.interval * 1000);
        };

        updateProgress();
    },

    // 停止幻灯片
    stopSlideshow() {
        clearTimeout(this.timer);
        const progressBar = document.querySelector('.slideshow-progress');
        progressBar.style.width = '0%';
        progressBar.style.transition = 'none';
    },

    // 事件绑定
    bindEvents() {
        // 主题切换
        document.getElementById('toggleDarkMode').addEventListener('click', () => {
            this.darkMode = !this.darkMode;
            this.saveConfig();
            this.applyConfig();
        });

        // 幻灯片控制
        document.getElementById('toggleSlideshow').addEventListener('click', () => {
            this.slideshow = !this.slideshow;
            this.saveConfig();
            this.applyConfig();
        });

        // 间隔时间设置
        document.getElementById('slideshowInterval').addEventListener('change', (e) => {
            const value = Math.max(1, Math.min(60, parseInt(e.target.value) || 5));
            this.interval = value;
            this.saveConfig();
            if (this.slideshow) this.startSlideshow();
        });

        // 文件夹导航
        document.querySelectorAll('.side-nav .btn').forEach(btn => {
            btn.addEventListener('click', function() {
                document.querySelectorAll('.side-nav .btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                config.currentFolder = this.dataset.folder;
                config.loadImage(config.currentFolder);
            });
        });

        // 键盘导航
        document.addEventListener('keydown', (e) => {
            if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') {
                this.loadImage(this.currentFolder);
            }
        });
    },

    // 加载图片
    async loadImage(folder) {
        try {
            const img = document.querySelector('.preview-image');
            img.style.opacity = 0;

            const response = await fetch(`?action=get_image&folder=${encodeURIComponent(folder)}`);
            if (!response.ok) throw new Error(`HTTP错误: ${response.status}`);
            
            const data = await response.json();
            if (!data.success) throw new Error(data.error);

            await new Promise((resolve) => {
                img.onload = () => {
                    img.style.opacity = 1;
                    resolve();
                };
                img.src = data.url;
            });

            // 更新元数据
            document.getElementById('meta-dimensions').textContent = data.meta.dimensions;
            document.getElementById('meta-size').textContent = data.meta.size;
            document.getElementById('meta-created').textContent = data.meta.created;
            document.getElementById('current-category').textContent = 
                this.currentFolder === 'all' ? '全部' : this.currentFolder;

        } catch (error) {
            console.error('图片加载失败:', error);
            document.querySelector('.preview-image').style.opacity = 1;
        }
    }
};

// 初始化应用
document.addEventListener('DOMContentLoaded', () => config.init());

</script>
</body>
</html>