<?php
require('../../config.php');
require_login();
$context = context_system::instance();
require_capability('local/filebrowser:view', $context);

function getDirectorySize($dir) {
    $size = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
        $size += $file->getSize();
    }
    return $size;
}

function formatSize($bytes) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    for ($i = 0; $bytes >= 1024 && $i < count($units) - 1; $i++) {
        $bytes /= 1024;
    }
    return round($bytes, 2) . ' ' . $units[$i];
}

$moodledata_size = formatSize(getDirectorySize($CFG->dataroot));

$path = optional_param('path', $CFG->dirroot, PARAM_RAW);

if (!is_dir($path)) {
    print_error('Invalid directory.');
}

function zipFolder($folder, $zipFilePath) {
    $zip = new ZipArchive();
    if ($zip->open($zipFilePath, ZipArchive::CREATE) === TRUE) {
        $folder = realpath($folder);
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder));
        foreach ($iterator as $key => $value) {
            if (!in_array(substr($value, strrpos($value, DIRECTORY_SEPARATOR) + 1), ['.', '..'])) {
                $zip->addFile(realpath($key), substr(realpath($key), strlen($folder) + 1));
            }
        }
        $zip->close();
    } else {
        die('Failed to create zip.');
    }
}

if (optional_param('downloadzip', 0, PARAM_BOOL)) {
    $zipname = tempnam(sys_get_temp_dir(), 'zip');
    zipFolder($path, $zipname);

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="backup.zip"');
    header('Content-Length: ' . filesize($zipname));
    readfile($zipname);
    unlink($zipname);
    exit;
}

if (optional_param('downloaddb', 0, PARAM_BOOL)) {
    $mysqli = new mysqli($CFG->dbhost, $CFG->dbuser, $CFG->dbpass, $CFG->dbname);
    if ($mysqli->connect_error) {
        die('Database connection failed: ' . $mysqli->connect_error);
    }

    $dump = "";
    $tablesResult = $mysqli->query("SHOW TABLES");
    while ($row = $tablesResult->fetch_row()) {
        $table = $row[0];
        $createTableResult = $mysqli->query("SHOW CREATE TABLE `$table`");
        $createTableRow = $createTableResult->fetch_row();
        $dump .= "

" . $createTableRow[1] . ";

";

        $result = $mysqli->query("SELECT * FROM `$table`");
        while ($row = $result->fetch_assoc()) {
            $values = array_map([$mysqli, 'real_escape_string'], array_values($row));
            $values = array_map(function($value) {
                return "'" . $value . "'";
            }, $values);
            $dump .= "INSERT INTO `$table` VALUES (" . implode(", ", $values) . ");
";
        }
    }
    $mysqli->close();

    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="database_backup.sql"');
    echo $dump;
    exit;
}

if (optional_param('downloadmoodledata', 0, PARAM_BOOL)) {
    $moodledata = $CFG->dataroot;
    $zipname = tempnam(sys_get_temp_dir(), 'moodledata') . '.zip';
    zipFolder($moodledata, $zipname);

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="moodledata_backup.zip"');
    header('Content-Length: ' . filesize($zipname));
    readfile($zipname);
    unlink($zipname);
    exit;
}

echo $OUTPUT->header();
echo $OUTPUT->heading('Moodle File Browser');

$zipurl = new moodle_url('/local/filebrowser/index.php', ['path' => $path, 'downloadzip' => 1]);
$dburl = new moodle_url('/local/filebrowser/index.php', ['downloaddb' => 1]);
$moodledataurl = new moodle_url('/local/filebrowser/index.php', ['downloadmoodledata' => 1]);
echo '<p><a href="' . $zipurl . '">Download All as ZIP</a></p>';
echo '<p><a href="' . $dburl . '">Download Database Backup</a></p>';
echo '<p><a href="' . $moodledataurl . '">Download Moodledata Backup</a></p>';
echo '<p>Moodledata Directory Size: <strong>' . $moodledata_size . '</strong></p>';

echo '<ul>';
foreach (scandir($path) as $item) {
    if ($item === '.' || $item === '..') continue;
    $fullpath = $path . DIRECTORY_SEPARATOR . $item;
    if (is_dir($fullpath)) {
        $url = new moodle_url('/local/filebrowser/index.php', ['path' => $fullpath]);
        echo '<li>[DIR] <a href="' . $url . '">' . $item . '</a></li>';
    } else {
        $downloadurl = new moodle_url('/local/filebrowser/index.php', ['download' => $fullpath]);
        echo '<li>[FILE] <a href="' . $downloadurl . '">' . $item . '</a></li>';
    }
}
echo '</ul>';

if ($download = optional_param('download', '', PARAM_RAW)) {
    if (file_exists($download) && is_file($download)) {
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($download) . '"');
        header('Content-Length: ' . filesize($download));
        readfile($download);
        exit;
    } else {
        print_error('File not found.');
    }
}

echo $OUTPUT->footer();
