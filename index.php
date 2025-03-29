<?php
// ==================================================
// 版本号: 0.0.2
// 更新内容: 
// 1. 添加图片路径缓存机制
// 2. 增加缓存自动刷新功能
// 3. 优化大目录下的性能表现
// ==================================================

// 配置区域
define('BASE_DIR', realpath(__DIR__ . '/images'));
define('CACHE_DIR', __DIR__ . '/image_cache');  // 缓存目录
define('CACHE_TTL', 720000);                     // 缓存有效期（秒）

// 安全验证：确保基础目录有效
if (!is_dir(BASE_DIR)) {
    die("无效的图片库目录");
}

// 创建缓存目录（如果不存在）
if (!file_exists(CACHE_DIR) && !mkdir(CACHE_DIR, 0755, true)) {
    die("无法创建缓存目录");
}

// 获取第一层子目录列表
function getFirstLevelSubdirs() {
    $subdirs = [];
    $items = @scandir(BASE_DIR) ?: [];
    
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = BASE_DIR . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            $subdirs[] = $item;
        }
    }
    return $subdirs;
}

// 递归获取所有图片文件（带缓存机制）
function getAllImages($dir) {
    // 生成唯一缓存文件名
    $cacheKey = md5(realpath($dir));
    $cacheFile = CACHE_DIR . '/' . $cacheKey . '.json';
    
    // 尝试读取有效缓存
    if (file_exists($cacheFile) && 
        (time() - filemtime($cacheFile)) < CACHE_TTL) {
        return json_decode(file_get_contents($cacheFile), true);
    }

    // 没有有效缓存时扫描目录
    $images = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && in_array(strtolower($file->getExtension()), ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
            $images[] = $file->getPathname();
        }
    }

    // 保存到缓存文件
    if (!empty($images)) {
        file_put_contents($cacheFile, json_encode($images));
    }

    return $images;
}

// 处理AJAX请求
if (isset($_GET['action']) && $_GET['action'] === 'get_image') {
    try {
        if (!isset($_GET['folder'])) throw new Exception('缺少文件夹参数');
        
        $folder = $_GET['folder'];
        $validFolders = getFirstLevelSubdirs();
        
        // 特殊处理根目录请求
        if ($folder === 'all') {
            $targetDir = BASE_DIR;
        } else {
            if (!in_array($folder, $validFolders)) {
                throw new Exception('无效的文件夹名称');
            }
            $targetDir = BASE_DIR . DIRECTORY_SEPARATOR . $folder;
        }

        $images = getAllImages($targetDir);
        
        if (empty($images)) {
            throw new Exception('该目录下没有找到图片');
        }

        $selected = $images[array_rand($images)];
        $url = str_replace(realpath($_SERVER['DOCUMENT_ROOT']), '', $selected);
        $url = str_replace('\\', '/', $url);

        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'url' => $url]);
        exit;
    } catch (Exception $e) {
        header('Content-Type: application/json');
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
    <title>图片浏览器</title>
    <style>
        .folder-list {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            padding: 20px;
        }
        .folder-btn {
            padding: 12px 24px;
            background: #4CAF50;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            transition: background 0.3s;
        }
        .folder-btn:hover {
            background: #45a049;
        }
        .image-container {
            max-width: 100%;
            margin: 20px;
            text-align: center;
        }
        .preview-image {
            max-width: 90%;
            max-height: 70vh;
            border: 3px solid #ddd;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .loading {
            display: none;
            color: #666;
            font-size: 18px;
            margin: 20px;
        }
        .auto-load-notice {
            margin: 15px;
            color: #666;
            font-style: italic;
        }
        .cache-info {
            position: fixed;
            bottom: 10px;
            right: 10px;
            background: rgba(0,0,0,0.7);
            color: #fff;
            padding: 5px 10px;
            border-radius: 3px;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="folder-list">
        <button class="folder-btn" data-folder="all">
            全部图片
        </button>
        <?php foreach (getFirstLevelSubdirs() as $folder): ?>
            <button class="folder-btn" data-folder="<?= htmlspecialchars($folder) ?>">
                <?= htmlspecialchars($folder) ?>
            </button>
        <?php endforeach; ?>
    </div>
    
    <div class="loading">正在加载图片...</div>
    <div class="image-container">
        <img class="preview-image" src="" alt="随机图片">
        <div class="auto-load-notice">页面加载时自动显示随机图片</div>
    </div>
    <div class="cache-info">缓存版本: 0.0.2</div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const buttons = document.querySelectorAll('.folder-btn');
            const preview = document.querySelector('.preview-image');
            const loading = document.querySelector('.loading');

            // 自动加载初始图片
            const loadRandomImage = async (folder = 'all') => {
                try {
                    loading.style.display = 'block';
                    preview.style.display = 'none';
                    
                    const response = await fetch(`?action=get_image&folder=${encodeURIComponent(folder)}`);
                    const data = await response.json();
                    
                    if (!data.success) throw new Error(data.error);
                    
                    preview.src = data.url;
                    preview.style.display = 'block';
                    preview.onload = () => loading.style.display = 'none';
                } catch (error) {
                    loading.style.display = 'none';
                    alert(`错误：${error.message}`);
                }
            };

            // 页面加载完成时自动获取随机图片
            loadRandomImage();

            // 按钮点击事件
            buttons.forEach(btn => {
                btn.addEventListener('click', () => {
                    loadRandomImage(btn.dataset.folder);
                });
            });

            // 自动刷新逻辑
            setInterval(() => {
                if(preview.src) {
                    preview.src = preview.src + '?t=' + Date.now();
                }
            }, 300000); // 每5分钟刷新一次图片
        });
    </script>
</body>
</html>
