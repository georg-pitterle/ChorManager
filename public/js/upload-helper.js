/**
 * Image Upload Compression Helper
 * 
 * Provides client-side image compression using Canvas API.
 * Iteratively reduces quality and dimensions until file meets size target.
 * 
 * Usage:
 *   // Compress single image
 *   const compressedFile = await window.uploadHelper.processFile(imageFile, 2097152); // 2MB
 *   
 *   // Setup automatic compression on form
 *   window.uploadHelper.setupFormCompression(formElement);
 */

window.uploadHelper = (() => {
    // Constants
    const TARGET_SIZE = 2 * 1024 * 1024; // 2 MB
    const MIN_WIDTH = 320; // Minimum dimension
    const MIN_HEIGHT = 240;

    /**
     * Load image file into a Canvas element
     * @param {File} file - Image file to load
     * @returns {Promise<{canvas: HTMLCanvasElement, ctx: CanvasRenderingContext2D, width: number, height: number}>}
     */
    const loadImageAsCanvas = async (file) => {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();

            reader.onload = (e) => {
                const img = new Image();
                img.onload = () => {
                    const canvas = document.createElement('canvas');
                    canvas.width = img.width;
                    canvas.height = img.height;

                    const ctx = canvas.getContext('2d');
                    if (!ctx) {
                        reject(new Error('Unable to get canvas context'));
                        return;
                    }

                    ctx.drawImage(img, 0, 0);
                    resolve({
                        canvas: canvas,
                        ctx: ctx,
                        width: img.width,
                        height: img.height
                    });
                };

                img.onerror = () => reject(new Error('Failed to load image'));
                img.src = e.target.result;
            };

            reader.onerror = () => reject(new Error('Failed to read file'));
            reader.readAsDataURL(file);
        });
    };

    /**
     * Compress canvas to target file size by reducing quality and dimensions
     * @param {HTMLCanvasElement} canvas - Canvas with image
     * @param {string} originalName - Original file name (used to build output name)
     * @param {number} targetSize - Target file size in bytes (default: 2 MB)
     * @returns {Promise<File>} - Compressed image file
     */
    const compressCanvasToTarget = async (canvas, originalName, targetSize = TARGET_SIZE) => {
        const baseName = originalName.replace(/\.[^.]+$/, '');
        const outputName = baseName + '_compressed.jpg';
        let quality = 0.9;
        let width = canvas.width;
        let height = canvas.height;
        let blob;

        // Iteratively compress by reducing quality (0.1 steps from 0.9 to 0.1)
        for (quality = 0.9; quality >= 0.1; quality -= 0.1) {
            blob = await new Promise(resolve => {
                canvas.toBlob(resolve, 'image/jpeg', quality);
            });

            if (blob.size <= targetSize) {
                return new File([blob], outputName, { type: 'image/jpeg' });
            }
        }

        // If quality reduction not enough, reduce dimensions
        while ((width > MIN_WIDTH || height > MIN_HEIGHT) && blob.size > targetSize) {
            width = Math.max(Math.floor(width * 0.8), MIN_WIDTH);
            height = Math.max(Math.floor(height * 0.8), MIN_HEIGHT);

            const resizeCanvas = document.createElement('canvas');
            resizeCanvas.width = width;
            resizeCanvas.height = height;

            const ctx = resizeCanvas.getContext('2d');
            if (!ctx) break;

            ctx.drawImage(canvas, 0, 0, width, height);

            // Try compression at lowest quality
            blob = await new Promise(resolve => {
                resizeCanvas.toBlob(resolve, 'image/jpeg', 0.7);
            });

            if (blob.size <= targetSize) {
                return new File([blob], outputName, { type: 'image/jpeg' });
            }
        }

        // Return best effort result
        return new File([blob], outputName, { type: 'image/jpeg' });
    };

    /**
     * Process a single image file: compress if needed, return File object
     * @param {File} file - Image file to process
     * @param {number} targetSize - Target size in bytes (default: 2 MB)
     * @returns {Promise<File>} - Processed file (compressed if image, or original)
     */
    const processFile = async (file, targetSize = TARGET_SIZE) => {
        // Check if file is an image
        if (!file.type.startsWith('image/')) {
            return file; // Return non-images unchanged
        }

        // If already small enough, return unchanged
        if (file.size <= targetSize) {
            return file;
        }

        try {
            const { canvas } = await loadImageAsCanvas(file);
            return await compressCanvasToTarget(canvas, file.name, targetSize);
        } catch (err) {
            console.warn('Image compression failed:', err);
            return file; // Return original on error
        }
    };

    /**
     * Batch process multiple files
     * @param {FileList|File[]} files - Files to process
     * @param {number} targetSize - Target size in bytes
     * @returns {Promise<File[]>} - Array of processed files
     */
    const batchProcess = async (files, targetSize = TARGET_SIZE) => {
        const results = [];
        for (const file of files) {
            try {
                results.push(await processFile(file, targetSize));
            } catch (err) {
                console.warn(`Failed to process file ${file.name}:`, err);
                results.push(file); // Add original on error
            }
        }
        return results;
    };

    /**
     * Setup automatic compression on form with file inputs
     * Intercepts submit to compress images before sending
     * @param {HTMLFormElement} form - Form to monitor
     * @param {number} targetSize - Target size in bytes
     */
    const setupFormCompression = (form, targetSize = TARGET_SIZE) => {
        form.addEventListener('submit', async (e) => {
            e.preventDefault();

            // Find all file inputs in the form
            const fileInputs = form.querySelectorAll('input[type="file"]');

            if (fileInputs.length === 0) {
                form.submit();
                return;
            }

            // Process files in all inputs
            for (const input of fileInputs) {
                if (input.files.length > 0) {
                    try {
                        const processedFiles = await batchProcess(input.files, targetSize);

                        // Replace FileList with processed files (via DataTransfer)
                        const dt = new DataTransfer();
                        processedFiles.forEach(file => dt.items.add(file));
                        input.files = dt.files;
                    } catch (err) {
                        console.error('Form compression setup error:', err);
                        // Continue with original files on error
                    }
                }
            }

            // Submit form with processed files
            form.submit();
        });
    };

    // Public API
    return {
        processFile,
        batchProcess,
        setupFormCompression,
        TARGET_SIZE,
        MIN_WIDTH,
        MIN_HEIGHT
    };
})();
