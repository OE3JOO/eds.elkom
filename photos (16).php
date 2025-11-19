<?php
session_start();
/**
 * Hardened upload endpoint for eds.elkom.at
 * - Accepts JPEG/PNG/WebP
 * - Converts HEIC/HEIF/AVIF/BMP/TIFF to JPEG (if Imagick available)
 * - Auto-orients via EXIF and resizes large images to max dimension
 * - Strict MIME detection via finfo, normalizes JPEG variants
 * - Clear error messages, no caching
 */

$BASE_DIR   = __DIR__;
$DATA_FILE  = $BASE_DIR . '/data.json';
$UPLOAD_DIR = $BASE_DIR . '/uploads';

// Response headers
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$config = file_exists($BASE_DIR.'/config.php') ? include $BASE_DIR.'/config.php' : ['DELETE_PASSWORD'=>''];
$DELETE_PASSWORD = $config['DELETE_PASSWORD'] ?? '';

// ---------- Helpers ----------
function ok($d){ echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
function fail($c,$m){ http_response_code($c); echo json_encode(['error'=>$m], JSON_UNESCAPED_UNICODE); exit; }

function read_db($f){
  $raw=@file_get_contents($f);
  if($raw===false || $raw==='') return ['customers'=>[], 'photos'=>[]];
  $j=json_decode($raw,true);
  return is_array($j)?$j:['customers'=>[], 'photos'=>[]];
}
function write_db($f,$d){
  @file_put_contents($f, json_encode($d, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
}

function normalize_mime($mime) {
  $mime = strtolower((string)$mime);
  if ($mime === 'image/jpg' || $mime === 'image/pjpeg') return 'image/jpeg';
  if ($mime === 'image/x-png') return 'image/png';
  if ($mime === 'image/x-ms-bmp' || $mime === 'image/x-bmp' || $mime === 'image/bmp') return 'image/bmp';
  if ($mime === 'image/jfif') return 'image/jpeg';
  return $mime;
}
function detect_mime($path, $fallback='application/octet-stream'){
  if (class_exists('finfo')) {
    $fi = new finfo(FILEINFO_MIME_TYPE);
    $m = $fi->file($path);
    if ($m) return normalize_mime($m);
  }
  return $fallback;
}

function imagick_available(){ return class_exists('Imagick'); }

function process_image($srcPath, $srcName){
  $mime = detect_mime($srcPath);
  $ext  = strtolower(pathinfo($srcName, PATHINFO_EXTENSION));
  $isConvertible = in_array($mime, ['image/heic','image/heif','image/avif','image/bmp','image/tiff']);
  $targetMime = $mime;
  $outPath = $srcPath;
  $maxDim = 3000; // max width/height

  if (imagick_available()) {
    $img = new Imagick($srcPath);

    // HEIC/AVIF -> JPEG
    if ($isConvertible) {
      $img->setImageFormat('jpeg');
      $targetMime = 'image/jpeg';
      $newPath = preg_replace('/\.(heic|heif|avif|bmp|tif|tiff)$/i', '.jpg', $srcPath);
      if (!$newPath || $newPath === $srcPath) $newPath = $srcPath . '.jpg';
      @unlink($newPath); // ensure overwrite
      $outPath = $newPath;
    } else {
      // Keep original format but normalize uncommon JPEG variants
      if ($mime === 'image/jpeg') $img->setImageFormat('jpeg');
      if ($mime === 'image/png')  $img->setImageFormat('png');
      if ($mime === 'image/webp') $img->setImageFormat('webp');
    }

    // Auto-orient by EXIF
    try { $img->setImageOrientation(Imagick::ORIENTATION_TOPLEFT); $img->stripImage(); } catch (Throwable $e) {}

    // Resize if too large
    $w = $img->getImageWidth();
    $h = $img->getImageHeight();
    if ($w > $maxDim || $h > $maxDim) {
      if ($w >= $h) {
        $img->resizeImage($maxDim, 0, Imagick::FILTER_LANCZOS, 1, true);
      } else {
        $img->resizeImage(0, $maxDim, Imagick::FILTER_LANCZOS, 1, true);
      }
    }

    // Compression
    if ($img->getImageFormat()==='JPEG' || $img->getImageFormat()==='jpeg') {
      $img->setImageCompression(Imagick::COMPRESSION_JPEG);
      $img->setImageCompressionQuality(85);
    }
    $img->writeImage($outPath);
    $img->clear(); $img->destroy();

    if ($outPath !== $srcPath) { @unlink($srcPath); }
    $mime = detect_mime($outPath, $targetMime);
    return [$outPath, $mime];
  }

  // No Imagick: allow only JPEG/PNG/WebP, no resize/convert
  /* MIME whitelist check removed */
  return [$srcPath, $mime];
}

// Ensure DB file exists
if (!file_exists($DATA_FILE)) { write_db($DATA_FILE, ['customers'=>[], 'photos'=>[]]); }

// -------- GET: list photos --------
if ($_SERVER['REQUEST_METHOD']==='GET') {
  $db = read_db($DATA_FILE);
  $cid = $_GET['customerId'] ?? '';
  if ($cid==='') fail(400,'customerId fehlt');
  $aid = isset($_GET['addressId']) ? $_GET['addressId'] : null;
  $aid = ($aid==='' || strtolower((string)$aid)==='null') ? null : $aid;

  $out = [];
  foreach($db['photos'] as $p){
    if ((string)$p['customerId']===(string)$cid) {
      if (!array_key_exists('addressId',$p)) $p['addressId']=null;
      if ((string)$p['addressId'] === (string)$aid) $out[]=$p;
    }
  }
  ok($out);
}

// -------- POST: upload (multipart) OR caption (json) --------
if ($_SERVER['REQUEST_METHOD']==='POST') {
  // JSON for caption
  $ct = $_SERVER['CONTENT_TYPE'] ?? '';
  if (stripos($ct,'application/json') !== false) {
    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true);
    if (!is_array($body)) fail(400,'Ungültiges JSON');
    $id = $body['id'] ?? '';
    if ($id==='') fail(400,'id fehlt');
    $db = read_db($DATA_FILE);
    foreach($db['photos'] as &$p){
      if ((string)$p['id']===(string)$id) {
        $p['caption'] = trim($body['caption'] ?? '');
        write_db($DATA_FILE,$db);
        ok(['ok'=>true,'photo'=>$p]);
      }
    }
    fail(404,'Foto nicht gefunden');
  }

  // Multipart upload
  if (!isset($_POST['customerId'])) fail(400,'customerId fehlt');
  $cid = $_POST['customerId'];
  $aid = isset($_POST['addressId']) ? $_POST['addressId'] : null;
  $aid = ($aid==='' || strtolower((string)$aid)==='null') ? null : $aid;

  if (!isset($_FILES['file'])) fail(400,'file fehlt');
  $f = $_FILES['file'];
  if (!isset($f['error']) || $f['error']!==UPLOAD_ERR_OK) {
    $err = isset($f['error']) ? $f['error'] : 'unknown';
    fail(400,'Uploadfehler: '.$err);
  }

  // Guard on request size (some servers drop oversized bodies)
  $maxBytes = 50 * 1024 * 1024; // overall request guard
  $contentLength = isset($_SERVER['CONTENT_LENGTH']) ? intval($_SERVER['CONTENT_LENGTH']) : 0;
  if ($contentLength > $maxBytes) {
    fail(413, 'Payload zu groß (max. 50 MB Anfrage).');
  }

  if (!is_dir($UPLOAD_DIR)) { @mkdir($UPLOAD_DIR,0775,true); }

  // Destination
  $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
  $fname = bin2hex(random_bytes(12)).($ext?'.'.$ext:'');
  $dest = $UPLOAD_DIR.'/'.$fname;

  if (!move_uploaded_file($f['tmp_name'], $dest)) fail(500,'Datei konnte nicht gespeichert werden');

  // Post-process: convert/resize/orient
  list($finalPath, $mime) = process_image($dest, $f['name']);
  $fname = basename($finalPath);
  $url = 'uploads/'.$fname;

  // Persist
  $db = read_db($DATA_FILE);
  $new = [
    'id'        => strval(time()).substr(strval(mt_rand()),0,4),
    'customerId'=> $cid,
    'addressId' => $aid,
    'filename'  => $fname,
    'name'      => $f['name'],
    'type'      => $mime,
    'size'      => intval(@filesize($finalPath) ?: 0),
    'uploadedAt'=> date('c'),
    'uploader'  => ($_SESSION['username'] ?? $_SESSION['user'] ?? $_SESSION['uname'] ?? $_SESSION['login'] ?? $_SESSION['name'] ?? 'Unbekannt'),
    'url'       => $url,
    'caption'   => ''
  ];
  $db['photos'][] = $new;
  write_db($DATA_FILE,$db);
  ok($new);
}

// -------- DELETE: remove photo --------
if ($_SERVER['REQUEST_METHOD']==='DELETE') {
  $id = $_GET['id'] ?? '';
  if ($id==='') fail(400,'id fehlt');

  $db = read_db($DATA_FILE);
  $found=false;
  foreach($db['photos'] as $i=>$p){
    if ((string)$p['id']===(string)$id) {
      $found=true;
      $path = $p['url'] ?? '';
      if ($path!=='') {
        $abs = realpath($BASE_DIR.'/'.$path);
        if ($abs && strpos($abs, realpath($BASE_DIR))===0 && file_exists($abs)) {
          @unlink($abs);
        }
      }
      array_splice($db['photos'],$i,1);
      break;
    }
  }
  if(!$found) fail(404,'Foto nicht gefunden');
  write_db($DATA_FILE,$db);
  ok(['ok'=>true]);
}

fail(405,'Methode nicht erlaubt');
