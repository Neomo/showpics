<?php
// ==================================================
// 版本号: 0.0.4
// 更新内容:
// 1. 移除EXIF相关功能
// 2. 增加下载和URL显示
// 3. 添加夜间模式
// 4. 添加统计信息面板
// 5. 优化布局和交互
// ==================================================

// 配置区域
define('BASE_DIR', realpath(__DIR__ . '/images'));
define('CACHE_DIR', __DIR__ . '/image_cache');
define('CACHE_TTL', 720000);

// 安全验证
if (!is_dir(BASE_DIR)) die("无效的图片库目录");
if (!file_exists(CACHE_DIR) && !mkdir(CACHE_DIR, 0755, true)) die("无法创建缓存目录");

// 获取第一层子目录列表
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

// 获取图片数量统计
function getImageCounts() {
    $cacheFile = CACHE_DIR . '/counts.json';
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < CACHE_TTL) {
        return json_decode(file_get_contents($cacheFile), true);
    }

    $counts = ['total' => 0];
    $folders = array_merge(['all'], getFirstLevelSubdirs());
    
    foreach ($folders as $folder) {
        $dir = ($folder === 'all') ? BASE_DIR : BASE_DIR . DIRECTORY_SEPARATOR . $folder;
        $images = getAllImages($dir);
        $count = count($images);
        $counts[$folder] = $count;
        if ($folder !== 'all') $counts['total'] += $count;
    }

    file_put_contents($cacheFile, json_encode($counts));
    return $counts;
}

// 递归获取图片（带缓存）
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

    if (!empty($images)) file_put_contents($cacheFile, json_encode($images));
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
        if (!$targetDir) throw new Exception('无效的文件夹名称');

        $images = getAllImages($targetDir);
        if (empty($images)) throw new Exception('没有找到图片');

        $selected = $images[array_rand($images)];
        $url = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . 
               str_replace('\\', '/', str_replace(realpath($_SERVER['DOCUMENT_ROOT']), '', $selected));

        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'url' => $url]);
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
    <title>专业图片浏览器</title>
    <style>
        :root {
            --bg-color: #ffffff;
            --text-color: #333333;
            --button-bg: #4CAF50;
            --button-hover: #45a049;
            --border-color: #dddddd;
            --stats-bg: rgba(0,0,0,0.8);
            --stats-text: #ffffff;
        }

        .dark-mode {
            --bg-color: #1a1a1a;
            --text-color: #ffffff;
            --button-bg: #2c3e50;
            --button-hover: #34495e;
            --border-color: #555555;
            --stats-bg: rgba(255,255,255,0.9);
            --stats-text: #333333;
        }

        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
            background: var(--bg-color);
            color: var(--text-color);
            transition: all 0.3s;
        }

        .main-container {
            max-width: 960px;
            margin: 0 auto;
            padding: 20px;
        }

        .folder-list {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            padding: 20px 0;
            justify-content: center;
        }

        .folder-btn {
            padding: 12px 24px;
            background: var(--button-bg);
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.3s;
        }

        .folder-btn:hover {
            background: var(--button-hover);
        }

        .folder-btn.active {
            background: #e67e22;
            box-shadow: 0 0 8px rgba(230, 126, 34, 0.5);
        }

        .image-container {
            text-align: center;
            margin: 20px 0;
            padding: 20px;
            border: 2px solid var(--border-color);
            border-radius: 8px;
        }

        .preview-image {
            max-width: 100%;
            max-height: 70vh;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        .download-area {
            margin-top: 20px;
            display: flex;
            gap: 15px;
            justify-content: center;
            align-items: center;
        }

        .url-display {
            max-width: 60%;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            padding: 8px;
            background: rgba(0,0,0,0.05);
            border-radius: 4px;
        }

        .config-panel {
            position: fixed;
            top: 20px;
            right: 20px;
            background: var(--stats-bg);
            color: var(--stats-text);
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            z-index: 1000;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .stats-panel {
            position: fixed;
            bottom: 10px;
            right: 10px;
            background: var(--stats-bg);
            color: var(--stats-text);
            padding: 10px 15px;
            border-radius: 5px;
            font-size: 12px;
            backdrop-filter: blur(5px);
        }

        .loading {
            display: none;
            text-align: center;
            padding: 20px;
            color: var(--text-color);
        }

        @media (max-width: 768px) {
            .main-container {
                padding: 10px;
            }
            .folder-btn {
                padding: 8px 16px;
            }
        }
    </style>
</head>
<body>
    <div class="config-panel">
        <button id="toggleDarkMode">夜间模式</button>
        <button id="toggleAnimation">切换动画</button>
        <div>
            <button id="toggleSlideshow">幻灯片</button>
            <input type="number" id="slideshowInterval" value="5" min="1" style="width:60px;">秒
        </div>
    </div>

    <div class="main-container">
        <div class="folder-list">
            <button class="folder-btn active" data-folder="all">全部图片</button>
            <?php foreach (getFirstLevelSubdirs() as $folder): ?>
                <button class="folder-btn" data-folder="<?= htmlspecialchars($folder) ?>">
                    <?= htmlspecialchars($folder) ?>
                </button>
            <?php endforeach; ?>
        </div>

        <div class="loading">正在加载图片...</div>
        <div class="image-container">
            <img class="preview-image" src="" alt="随机图片">
            <div class="download-area">
                <div class="url-display"></div>
                <a class="folder-btn download-btn" target="_blank">下载</a>
            </div>
        </div>
    </div>

    <div class="stats-panel">
        <div>版本: 0.0.4</div>
        <div>总数: <?= number_format(getImageCounts()['total']) ?></div>
        <?php foreach (getImageCounts() as $folder => $count): ?>
            <?php if($folder !== 'total' && $folder !== 'all'): ?>
                <div><?= htmlspecialchars($folder) ?>: <?= number_format($count) ?></div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const config = {
                darkMode: false,
                animation: true,
                slideshow: false,
                interval: 5,
                currentFolder: 'all',
                loadConfig() {
                    const saved = localStorage.getItem('imageViewerConfig');
                    if (saved) Object.assign(this, JSON.parse(saved));
                    this.updateUI();
                },
                saveConfig() {
                    localStorage.setItem('imageViewerConfig', JSON.stringify(this));
                    this.updateUI();
                },
                updateUI() {
                    document.body.classList.toggle('dark-mode', this.darkMode);
                    document.getElementById('toggleAnimation').textContent = 
                        `动画: ${this.animation ? '开' : '关'}`;
                    document.getElementById('toggleSlideshow').textContent = 
                        `幻灯片: ${this.slideshow ? '开' : '关'}`;
                    document.getElementById('toggleDarkMode').textContent = 
                        `夜间模式: ${this.darkMode ? '开' : '关'}`;
                    preview.classList.toggle('fade-animation', this.animation);
                    
                    if (this.slideshow) this.startSlideshow();
                    else this.stopSlideshow();
                },
                startSlideshow() {
                    this.stopSlideshow();
                    this.slideshowTimer = setInterval(() => {
                        loadRandomImage(this.currentFolder);
                    }, this.interval * 1000);
                },
                stopSlideshow() {
                    if (this.slideshowTimer) clearInterval(this.slideshowTimer);
                }
            };

            const preview = document.querySelector('.preview-image');
            const loading = document.querySelector('.loading');
            const buttons = document.querySelectorAll('.folder-btn');

            const loadRandomImage = async (folder = 'all') => {
                try {
                    config.stopSlideshow();
                    config.currentFolder = folder;
                    loading.style.display = 'block';
                    if (config.animation) preview.style.opacity = 0;
                    
                    const response = await fetch(`?action=get_image&folder=${encodeURIComponent(folder)}`);
                    const data = await response.json();
                    
                    if (!data.success) throw new Error(data.error);
                    
                    preview.onload = () => {
                        loading.style.display = 'none';
                        document.querySelector('.url-display').textContent = data.url;
                        document.querySelector('.download-btn').href = data.url;
                        if (config.animation) preview.style.opacity = 1;
                        if (config.slideshow) config.startSlideshow();
                    };
                    preview.src = data.url;
                } catch (error) {
                    loading.style.display = 'none';
                    alert(`错误: ${error.message}`);
                }
            };

            // 事件监听
            document.getElementById('toggleDarkMode').addEventListener('click', () => {
                config.darkMode = !config.darkMode;
                config.saveConfig();
            });

            document.getElementById('toggleAnimation').addEventListener('click', () => {
                config.animation = !config.animation;
                config.saveConfig();
            });

            document.getElementById('toggleSlideshow').addEventListener('click', () => {
                config.slideshow = !config.slideshow;
                config.saveConfig();
            });

            document.getElementById('slideshowInterval').addEventListener('change', (e) => {
                config.interval = Math.max(1, parseInt(e.target.value) || 5);
                config.saveConfig();
            });

            buttons.forEach(btn => {
                btn.addEventListener('click', function() {
                    buttons.forEach(b => b.classList.remove('active'));
                    this.classList.add('active');
                    loadRandomImage(this.dataset.folder);
                });
            });

            // 初始化
            config.loadConfig();
            loadRandomImage();
        });
    </script>
</body>
</html>
