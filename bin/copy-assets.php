<?php

function copyAssets(): void {
    $source = 'vendor/twbs/bootstrap/dist/css/bootstrap.min.css';
    $dest = 'public/vendor/bootstrap/dist/css/bootstrap.min.css';
    
    @mkdir(dirname($dest), 0755, true);
    if (!copy($source, $dest)) {
        throw new RuntimeException("Failed to copy $source to $dest");
    }
    
    $source = 'vendor/twbs/bootstrap/dist/js/bootstrap.bundle.min.js';
    $dest = 'public/vendor/bootstrap/dist/js/bootstrap.bundle.min.js';
    
    @mkdir(dirname($dest), 0755, true);
    if (!copy($source, $dest)) {
        throw new RuntimeException("Failed to copy $source to $dest");
    }
    
    $srcDir = 'vendor/twbs/bootstrap-icons/font';
    $destDir = 'public/vendor/bootstrap-icons/font';
    
    @mkdir($destDir, 0755, true);
    
    $files = glob($srcDir . '/*');
    foreach ($files as $file) {
        if (is_file($file)) {
            if (!copy($file, $destDir . '/' . basename($file))) {
                throw new RuntimeException("Failed to copy $file");
            }
        }
    }
}

try {
    copyAssets();
} catch (Exception $e) {
    fwrite(STDERR, "Asset copy failed: " . $e->getMessage() . PHP_EOL);
    exit(1);
}