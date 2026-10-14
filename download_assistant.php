<?php
// download_assistant.php - Auto packs extension files into zip for instant download
$zip_filename = 'wubachat-smart-dispatcher.zip';
$source_dir   = __DIR__ . '/extension_files';

if (!extension_loaded('zip')) {
    exit('ZIP extension PHP me enable nahi hai.');
}

$zip = new ZipArchive();
$temp_file = tempnam(sys_get_temp_dir(), 'zip');

if ($zip->open($temp_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
    $files = ['manifest.json', 'content.js', 'Install_Assistant.bat'];
    foreach ($files as $file) {
        $filePath = $source_dir . '/' . $file;
        if (file_exists($filePath)) {
            $zip->addFile($filePath, $file);
        }
    }
    $zip->close();

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $zip_filename . '"');
    header('Content-Length: ' . filesize($temp_file));
    header('Pragma: no-cache');
    readfile($temp_file);
    unlink($temp_file);
    exit;
} else {
    exit('Zip creation failed.');
}