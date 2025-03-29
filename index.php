<?php
// ==================================================
// 版本号: 0.0.3
// 更新内容: 
// 1. 图片切换动画控制
// 2. 幻灯片播放模式
// 3. EXIF信息显示
// 4. 用户配置持久化
// 5. 缓存系统优化
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
    foreach (@scandir(BASE_DIR) ?: [] as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = BASE_DIR . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) $subdirs[] = $item;
    }
    return $subdirs;
}

// 带缓存的图片获取
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
        $url = str_replace(realpath($_SERVER['DOCUMENT_ROOT']), '', $selected);
        $url = str_replace('\\', '/', $url);

        // 读取EXIF信息
        $exif = [];
        if (function_exists('exif_read_data') && in_array(strtolower(pathinfo($selected, PATHINFO_EXTENSION)), ['jpg','jpeg'])) {
            if ($exifData = @exif_read_data($selected)) {
                $exif = array_filter([
                    'Camera' => ($exifData['Make'] ?? '') . ' ' . ($exifData['Model'] ?? ''),
                    'Date' => $exifData['DateTimeOriginal'] ?? '',
                    'Aperture' => $exifData['COMPUTED']['ApertureFNumber'] ?? '',
                    'Exposure' => $exifData['ExposureTime'] ?? '',
                    'ISO' => $exifData['ISOSpeedRatings'] ?? ''
                ]);
            }
        }

        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'url' => $url, 'exif' => $exif]);
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
    <title>智能图片浏览器</title>
    <style>
        /* 基础样式 */
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; }
        .folder-list { display: flex; flex-wrap: wrap; gap: 10px; padding: 20px; }
        .folder-btn { padding: 12px 24px; background: #4CAF50; color: white; 
            border: none; border-radius: 4px; cursor: pointer; transition: background 0.3s; }
        .folder-btn:hover { background: #45a049; }
        .image-container { max-width: 100%; margin: 20px; text-align: center; position: relative; }
        .preview-image { max-width: 90%; max-height: 70vh; border: 3px solid #ddd; 
            border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        .loading { display: none; color: #666; font-size: 18px; margin: 20px; }
        
        /* 配置面板 */
        .config-panel {
            position: fixed; top: 20px; right: 20px; background: rgba(255,255,255,0.95);
            padding: 15px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            z-index: 1000; backdrop-filter: blur(5px);
        }
        .config-item { margin: 10px 0; display: flex; align-items: center; gap: 8px; }
        .config-item input[type="number"] { width: 60px; padding: 4px; }
        
        /* EXIF显示 */
        .exif-badge { position: absolute; top: 10px; left: 10px; background: rgba(0,0,0,0.7);
            color: white; padding: 2px 6px; border-radius: 3px; font-size: 12px; z-index: 100; }
        .exif-overlay {
            position: absolute; top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.8); color: white; padding: 20px;
            opacity: 0; transition: opacity 0.3s; pointer-events: none;
            overflow-y: auto; font-size: 14px;
        }
        .preview-image:hover + .exif-overlay, .exif-overlay:hover { opacity: 1; }
        
        /* 动画效果 */
        .fade-animation { transition: opacity 0.5s ease-in-out; }
    </style>
</head>
<body>
    <!-- 配置面板 -->
    <div class="config-panel">
        <div class="config-item">
            <button id="toggleAnimation">切换动画：开启</button>
        </div>
        <div class="config-item">
            <button id="toggleSlideshow">幻灯片：关闭</button>
            <input type="number" id="slideshowInterval" value="5" min="1"> 秒
        </div>
    </div>

    <!-- 文件夹列表 -->
    <div class="folder-list">
        <button class="folder-btn" data-folder="all">全部图片</button>
        <?php foreach (getFirstLevelSubdirs() as $folder): ?>
            <button class="folder-btn" data-folder="<?= htmlspecialchars($folder) ?>">
                <?= htmlspecialchars($folder) ?>
            </button>
        <?php endforeach; ?>
    </div>

    <!-- 图片显示区域 -->
    <div class="loading">正在加载图片...</div>
    <div class="image-container">
        <div style="position:relative;">
            <img class="preview-image" src="" alt="随机图片">
            <div class="exif-overlay"></div>
        </div>
        <div class="auto-load-notice">页面加载时自动显示随机图片</div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const buttons = document.querySelectorAll('.folder-btn');
            const preview = document.querySelector('.preview-image');
            const loading = document.querySelector('.loading');
            const exifOverlay = document.querySelector('.exif-overlay');
            let currentFolder = 'all';
            let slideshowTimer = null;
            
            // 用户配置
            const config = {
                animation: true,
                slideshow: false,
                interval: 5,
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
                    document.getElementById('toggleAnimation').textContent = 
                        `切换动画：${this.animation ? '开启' : '关闭'}`;
                    document.getElementById('toggleSlideshow').textContent = 
                        `幻灯片：${this.slideshow ? '开启' : '关闭'}`;
                    preview.classList.toggle('fade-animation', this.animation);
                    if (this.slideshow) this.startSlideshow();
                    else this.stopSlideshow();
                },
                startSlideshow() {
                    this.stopSlideshow();
                    slideshowTimer = setInterval(() => {
                        loadRandomImage(currentFolder);
                    }, this.interval * 1000);
                },
                stopSlideshow() {
                    if (slideshowTimer) clearInterval(slideshowTimer);
                }
            };

            // 图片加载函数
            const loadRandomImage = async (folder = 'all') => {
                try {
                    config.stopSlideshow();
                    currentFolder = folder;
                    if (config.animation) preview.style.opacity = 0;
                    
                    loading.style.display = 'block';
                    const response = await fetch(`?action=get_image&folder=${encodeURIComponent(folder)}`);
                    const data = await response.json();
                    
                    if (!data.success) throw new Error(data.error);
                    
                    // 更新EXIF显示
                    exifOverlay.innerHTML = data.exif.length ? 
                        `<div class="exif-badge">EXIF</div>` + 
                        Object.entries(data.exif).map(([k,v]) => `<b>${k}:</b> ${v}`).join('<br>') : '';
                    
                    preview.onload = () => {
                        loading.style.display = 'none';
                        if (config.animation) preview.style.opacity = 1;
                        if (config.slideshow) config.startSlideshow();
                    };
                    preview.src = data.url;
                } catch (error) {
                    loading.style.display = 'none';
                    alert(`错误：${error.message}`);
                }
            };

            // 事件绑定
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

            buttons.forEach(btn => btn.addEventListener('click', () => 
                loadRandomImage(btn.dataset.folder))
            );

            // 初始化
            config.loadConfig();
            loadRandomImage();
        });
    </script>
</body>
</html>
