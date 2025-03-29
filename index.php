<?php
// ==================================================
// 版本号: 0.0.5
// 更新内容:
// 1. 文件夹按钮高亮优化
// 2. 控制面板状态显示优化
// 3. 统计信息增加分类数量
// 4. 图片预加载与进度条
// 5. 元数据增加文件大小
// ==================================================

define('BASE_DIR', realpath(__DIR__ . '/images'));
define('CACHE_DIR', __DIR__ . '/image_cache');
define('CACHE_TTL', 72000);

// 安全检查
if (!is_dir(BASE_DIR)) die("图片目录不存在");
if (!file_exists(CACHE_DIR) && !mkdir(CACHE_DIR, 0755, true)) die("无法创建缓存目录");

// 获取首层目录
function getFirstLevelSubdirs() {
    $subdirs = [];
    foreach (scandir(BASE_DIR) as $item) {
        if (in_array($item, ['.', '..'])) continue;
        $path = BASE_DIR . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) $subdirs[] = $item;
    }
    return $subdirs;
}

// 获取图片统计
function getImageCounts() {
    $cacheFile = CACHE_DIR . '/counts.json';
    if (file_exists($cacheFile)) return json_decode(file_get_contents($cacheFile), true);
    
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

// 获取图片（带缓存）
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

// AJAX处理
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    try {
        if ($_GET['action'] === 'get_image') {
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

            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                throw new Exception('生成的URL无效: ' . $url);
            }

            // 元数据
            $meta = [
                'name' => basename($selected),
                'size' => formatFileSize(filesize($selected)),
                'created' => date("Y-m-d H:i", filectime($selected)),
                'dimensions' => getimagesize($selected)
            ];

            echo json_encode([
                'success' => true,
                'url' => $url,
                'meta' => $meta
            ]);
        }
        elseif ($_GET['action'] === 'get_counts') {
            echo json_encode(getImageCounts());
        }
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// 格式化文件大小
function formatFileSize($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 1) . ' KB';
    } elseif ($bytes > 1) {
        return $bytes . ' bytes';
    } else {
        return '1 byte';
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bento图片浏览器</title>
    <style>
        :root {
            --bg-primary: #f8f9fa;
            --bg-card: #ffffff;
            --text-primary: #2d3436;
            --accent-1: #6c5ce7;
            --accent-2: #a8a5e6;
            --radius-lg: 28px;
            --radius-sm: 14px;
            --shadow: 0 12px 40px rgba(0,0,0,0.08);
        }

        .dark-mode {
            --bg-primary: #0f0f0f;
            --bg-card: #1a1a1a;
            --text-primary: #f8f9fa;
            --accent-1: #8476f2;
            --accent-2: #6965d1;
            --shadow: 0 12px 40px rgba(0,0,0,0.25);
        }

        body {
            font-family: 'Inter', system-ui, sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            margin: 0;
            padding: 2rem;
            min-height: 100vh;
        }

        .bento-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1.5rem;
            max-width: 1400px;
            margin: 0 auto;
        }

        .bento-cell {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            padding: 1.5rem;
            transition: transform 0.2s, box-shadow 0.2s;
            box-shadow: var(--shadow);
        }

        .control-panel {
            grid-column: 1 / 2;
            grid-row: 1 / 2;
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .folder-nav {
            grid-column: 2 / 5;
            grid-row: 1 / 2;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 1rem;
        }

        .image-display {
            grid-column: 1 / 4;
            grid-row: 2 / 4;
            min-height: 600px;
            position: relative;
            overflow: hidden;
        }

        .stats-panel {
            grid-column: 4 / 5;
            grid-row: 2 / 3;
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .bento-btn {
            padding: 1rem 1.5rem;
            border: none;
            border-radius: var(--radius-sm);
            background: var(--accent-1);
            color: white;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 0.8rem;
            font-size: 0.95rem;
            position: relative;
        }

        .bento-btn:hover {
            background: var(--accent-2);
            transform: translateY(-2px);
        }

        .bento-btn.active {
            background: linear-gradient(135deg, var(--accent-1), var(--accent-2));
            box-shadow: 0 6px 24px rgba(108,92,231,0.2);
        }

        .preview-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: var(--radius-sm);
            background: linear-gradient(45deg, #f3f3f3, #e8e8e8);
            transition: opacity 0.3s;
        }

        .meta-layer {
            position: absolute;
            bottom: 1.5rem;
            left: 1.5rem;
            right: 1.5rem;
            background: rgba(255,255,255,0.92);
            padding: 1.5rem;
            border-radius: var(--radius-sm);
            backdrop-filter: blur(12px);
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1.5rem;
        }

        .dark-mode .meta-layer {
            background: rgba(26,26,26,0.92);
        }

        .meta-item {
            display: flex;
            flex-direction: column;
            gap: 0.3rem;
        }

        .meta-label {
            font-size: 0.9rem;
            opacity: 0.7;
        }

        .meta-value {
            font-weight: 600;
            font-size: 1.1rem;
        }

        /* 新增：加载进度条 */
        .loading-bar {
            position: absolute;
            bottom: 0;
            left: 0;
            height: 4px;
            background: var(--accent-1);
            width: 0%;
            transition: width 0.1s linear;
            z-index: 10;
        }

        /* 新增：幻灯片控制区域 */
        .slideshow-control {
            display: none;
            margin-top: 1rem;
            transition: all 0.3s;
        }

        .slideshow-active .slideshow-control {
            display: block;
        }

        @media (max-width: 1200px) {
            .bento-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .image-display {
                grid-column: 1 / 3;
            }
        }

        @media (max-width: 768px) {
            body {
                padding: 1rem;
            }
            
            .bento-grid {
                grid-template-columns: 1fr;
            }
            
            .folder-nav {
                grid-template-columns: repeat(2, 1fr);
            }
        }
    </style>
</head>
<body>
    <div class="bento-grid">
        <!-- 控制面板 -->
        <div class="bento-cell control-panel">
            <button class="bento-btn" id="toggleDarkMode">
                <span id="themeIcon">🌙</span>
                <span id="themeText">暗黑模式</span>
            </button>
            
            <div id="slideshowContainer">
                <button class="bento-btn" id="toggleSlideshow">
                    <span id="slideshowIcon">⏸️</span>
                    <span id="slideshowText">幻灯片</span>
                </button>
                <div class="slideshow-control">
                    <input type="number" class="bento-btn" 
                           id="slideshowInterval" value="5" min="1" max="60"
                           style="background: var(--bg-card); color: var(--text-primary);">
                    
                </div>
            </div>
        </div>

        <!-- 文件夹导航 -->
        <div class="bento-cell folder-nav">
            <button class="bento-btn active" data-folder="all">全部图片</button>
            <?php 
            $counts = getImageCounts();
            foreach (getFirstLevelSubdirs() as $folder): ?>
                <button class="bento-btn" data-folder="<?= htmlspecialchars($folder) ?>">
                    <?= htmlspecialchars($folder) ?> 
                    <span style="font-size:0.8em;opacity:0.8;">(<?= $counts[$folder] ?>)</span>
                </button>
            <?php endforeach; ?>
        </div>

        <!-- 图片显示区域 -->
        <div class="bento-cell image-display">
            <div class="loading-bar"></div>
            <img class="preview-image" src="" alt="当前图片">
            <div class="meta-layer">
                <div class="meta-item">
                    <div class="meta-label">名称</div>
                    <div class="meta-value" id="meta-name">-</div>
                </div>
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
            </div>
        </div>

        <!-- 统计信息 -->
        <div class="bento-cell stats-panel">
            <div class="meta-item">
                <div class="meta-label">总图片数</div>
                <div class="meta-value"><?= number_format($counts['total']) ?></div>
            </div>
            <div class="meta-item">
                <div class="meta-label">当前分类</div>
                <div class="meta-value" id="current-category">全部</div>
            </div>
            <div class="meta-item">
                <div class="meta-label">分类数量</div>
                <div class="meta-value"><?= count(getFirstLevelSubdirs()) ?></div>
            </div>
            <div class="meta-item">
                <div class="meta-label">版本</div>
                <div class="meta-value">BENTO 0.5</div>
            </div>
        </div>
    </div>

    <script>
        const config = {
            darkMode: false,
            slideshow: false,
            interval: 5,
            currentFolder: 'all',
            timer: null,

            init() {
                this.loadConfig();
                this.bindEvents();
                this.loadImage('all');
            },

            loadConfig() {
                const saved = localStorage.getItem('bentoConfig');
                if (saved) {
                    const data = JSON.parse(saved);
                    this.darkMode = data.darkMode || false;
                    this.slideshow = data.slideshow || false;
                    this.interval = data.interval || 5;
                    this.applyConfig();
                }
            },

            saveConfig() {
                localStorage.setItem('bentoConfig', JSON.stringify({
                    darkMode: this.darkMode,
                    slideshow: this.slideshow,
                    interval: this.interval
                }));
            },

            applyConfig() {
                // 主题设置
                document.body.classList.toggle('dark-mode', this.darkMode);
                document.getElementById('themeIcon').textContent = this.darkMode ? '☀️' : '🌙';
                document.getElementById('themeText').textContent = this.darkMode ? '明亮模式' : '暗黑模式';

                // 幻灯片设置
                document.getElementById('slideshowIcon').textContent = this.slideshow ? '▶️' : '⏸️';
                document.getElementById('slideshowText').textContent = this.slideshow ? '幻灯片中' : '幻灯片';
                document.getElementById('slideshowContainer').classList.toggle('slideshow-active', this.slideshow);
                
                if (this.slideshow) {
                    this.startSlideshow();
                } else {
                    this.stopSlideshow();
                }
            },

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
                    this.interval = Math.max(1, Math.min(60, parseInt(e.target.value) || 5));
                    this.saveConfig();
                    if (this.slideshow) this.startSlideshow();
                });

                // 文件夹按钮
                document.querySelectorAll('.folder-nav .bento-btn').forEach(btn => {
                    btn.addEventListener('click', () => {
                        document.querySelectorAll('.folder-nav .bento-btn').forEach(b => {
                            b.classList.remove('active');
                        });
                        btn.classList.add('active');
                        this.currentFolder = btn.dataset.folder;
                        this.loadImage(btn.dataset.folder);
                    });
                });

                // 键盘导航
                document.addEventListener('keydown', (e) => {
                    if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') {
                        this.loadImage(this.currentFolder);
                    }
                });
            },

            async loadImage(folder) {
                try {
                    const xhr = new XMLHttpRequest();
                    xhr.open('GET', `?action=get_image&folder=${encodeURIComponent(folder)}`);
                    xhr.responseType = 'json';
                    
                    // 显示加载进度
                    const loadingBar = document.querySelector('.loading-bar');
                    loadingBar.style.width = '0%';
                    
                    xhr.addEventListener('progress', (e) => {
                        if (e.lengthComputable) {
                            const percent = Math.round((e.loaded / e.total) * 100);
                            loadingBar.style.width = `${percent}%`;
                        }
                    });

                    const response = await new Promise((resolve, reject) => {
                        xhr.onload = () => resolve(xhr.response);
                        xhr.onerror = () => reject(new Error('网络错误'));
                        xhr.send();
                    });

                    if (response.success) {
                        const img = document.querySelector('.preview-image');
                        img.style.opacity = 0;
                        
                        await new Promise((resolve) => {
                            img.onload = () => {
                                img.style.opacity = 1;
                                loadingBar.style.width = '100%';
                                setTimeout(() => loadingBar.style.width = '0%', 300);
                                resolve();
                            };
                            img.src = response.url;
                        });

                        // 更新元数据
                        document.getElementById('meta-name').textContent = response.meta.name;
                        document.getElementById('meta-dimensions').textContent = 
                            `${response.meta.dimensions[0]}x${response.meta.dimensions[1]}`;
                        document.getElementById('meta-size').textContent = response.meta.size;
                        document.getElementById('meta-created').textContent = response.meta.created;
                        document.getElementById('current-category').textContent = 
                            this.currentFolder === 'all' ? '全部' : this.currentFolder;
                    }
                } catch (error) {
                    console.error('加载失败:', error);
                    document.querySelector('.loading-bar').style.width = '0%';
                }
            },

            startSlideshow() {
                this.stopSlideshow();
                this.timer = setInterval(() => {
                    this.loadImage(this.currentFolder);
                }, this.interval * 1000);
            },

            stopSlideshow() {
                if (this.timer) {
                    clearInterval(this.timer);
                    this.timer = null;
                }
            }
        };

        document.addEventListener('DOMContentLoaded', () => config.init());
    </script>
</body>
</html>
