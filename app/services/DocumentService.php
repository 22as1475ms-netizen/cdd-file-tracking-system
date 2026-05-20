<?php
require_once __DIR__ . "/../models/Document.php";
require_once __DIR__ . "/../models/Version.php";
require_once __DIR__ . "/../models/AuditLog.php";
require_once __DIR__ . "/StorageService.php";

class DocumentService {
  private const WORD_PAGE_BREAK_MARKER = '[[CDD-File-Tracking-System_WORD_PAGE_BREAK]]';
  private const EDITABLE_EXTENSIONS = ['docx', 'xlsx'];
  private const ALLOWED_UPLOAD_MIME_TYPES = [
    'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
    'pdf' => ['application/pdf'],
    'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
  ];

  public static function upload(
    PDO $pdo,
    array $file,
    int $ownerId,
    ?int $folderId,
    ?int $actorId = null,
    string $storageArea = 'PRIVATE',
    ?int $divisionId = null,
    array $metadata = []
  ): int {
    if (!isset($file['tmp_name']) || !self::isAcceptedUploadSource((string)$file['tmp_name'])) {
      throw new RuntimeException("Invalid upload");
    }

    $original = self::sanitizeFilename((string)($file['name'] ?? 'file'));
    self::assertUploadConstraints($file, $actorId ?? $ownerId);

    $actorId = $actorId ?? $ownerId;
    $storageArea = Document::normalizeStorageArea($storageArea);
    $docId = Document::create($pdo, $ownerId, $folderId, $original, $storageArea, $divisionId, $metadata);
    self::storeUploadedVersion($pdo, $docId, $file, $actorId);
    AuditLog::add($pdo, $actorId, "Uploaded document", $docId, $original);

    return $docId;
  }

  public static function uploadNewVersion(PDO $pdo, int $docId, array $file, int $userId): int {
    return self::uploadNewVersionWithNote($pdo, $docId, $file, $userId, null);
  }

  public static function uploadNewVersionWithNote(PDO $pdo, int $docId, array $file, int $userId, ?string $note = null): int {
    $doc = Document::get($pdo, $docId);
    if (!$doc) {
      throw new RuntimeException("Document not found");
    }

    $version = self::storeUploadedVersion($pdo, $docId, $file, $userId);
    $noteText = trim((string)$note);
    AuditLog::add(
      $pdo,
      $userId,
      "Uploaded new version",
      $docId,
      $noteText !== '' ? ("version=" . $version . ", " . $noteText) : ("version=" . $version)
    );

    return $version;
  }

  public static function restoreVersion(PDO $pdo, int $docId, int $versionId, int $userId): int {
    $doc = Document::get($pdo, $docId);
    $source = Version::get($pdo, $versionId);
    if (!$doc || !$source || (int)$source['document_id'] !== $docId) {
      throw new RuntimeException("Version not found");
    }

    $sourcePath = (string)$source['file_path'];
    if (!StorageService::exists($pdo, $sourcePath)) {
      throw new RuntimeException("Source file missing");
    }

    $next = Version::nextNumber($pdo, $docId);
    $safeName = self::buildStoredFilename($docId, $next, $doc['name']);
    $storageArea = (string)($doc['storage_area'] ?? 'PRIVATE');
    $targetPath = self::relativePath((int)$doc['owner_id'], $safeName, $storageArea);
    self::assertWithinQuota((int)$doc['owner_id'], StorageService::size($pdo, $sourcePath), $storageArea);

    if (!StorageService::copy($pdo, $sourcePath, $targetPath, [
      'kind' => 'document_version',
      'visibility' => 'private',
      'original_name' => (string)$doc['name'],
      'created_by' => $userId,
    ])) {
      throw new RuntimeException("Failed to restore version");
    }

    Version::add($pdo, $docId, $userId, $targetPath, $next);
    self::archiveNonLatestVersions($pdo, $docId, (int)$doc['owner_id'], $next, $storageArea);
    AuditLog::add($pdo, $userId, "Restored version", $docId, "from=".$source['version_number'].",to=".$next);

    return $next;
  }

  public static function storageStats(PDO $pdo): array {
    $totalBytes = 0;
    $files = 0;

    $usage = StorageService::storageUsage($pdo, ['../storage/documents/']);
    $files = (int)$usage['files'];
    $totalBytes = (int)$usage['bytes'];

    return [
      'documents' => Document::countActive($pdo),
      'documents_total' => Document::countAll($pdo),
      'documents_trashed' => Document::countTrashed($pdo),
      'versions' => Version::countAll($pdo),
      'files' => $files,
      'bytes' => $totalBytes,
    ];
  }

  public static function ownerStorageSummary(int $ownerId, string $storageArea = 'ALL'): array {
    $storageArea = strtoupper(trim($storageArea));
    $used = self::ownerStorageBytes($ownerId, $storageArea);
    $limit = match ($storageArea) {
      'PRIVATE' => PRIVATE_STORAGE_LIMIT_BYTES,
      'OFFICIAL' => OFFICIAL_STORAGE_LIMIT_BYTES,
      default => PRIVATE_STORAGE_LIMIT_BYTES + OFFICIAL_STORAGE_LIMIT_BYTES,
    };
    return [
      'used' => $used,
      'limit' => $limit,
      'remaining' => max(0, $limit - $used),
      'percent' => $limit > 0 ? min(100, ($used / $limit) * 100) : 0,
    ];
  }

  public static function ownerStorageBreakdown(int $ownerId): array {
    return [
      'private' => self::ownerStorageSummary($ownerId, 'PRIVATE'),
      'official' => self::ownerStorageSummary($ownerId, 'OFFICIAL'),
      'all' => self::ownerStorageSummary($ownerId, 'ALL'),
    ];
  }

  public static function absolutePathFromVersion(string $filePath): string {
    return StorageService::absoluteDocumentPath($filePath);
  }

  public static function signedDocumentToken(int $docId): string {
    return hash_hmac('sha256', (string)$docId, APP_SECRET);
  }

  public static function verifyDocumentToken(int $docId, string $token): bool {
    return hash_equals(self::signedDocumentToken($docId), $token);
  }

  public static function createSpreadsheetLaunchToken(int $docId, int $userId, int $ttlSeconds = 900): string {
    return self::createEditorLaunchToken($docId, $userId, $ttlSeconds, 'spreadsheet');
  }

  public static function createWordLaunchToken(int $docId, int $userId, int $ttlSeconds = 900): string {
    return self::createEditorLaunchToken($docId, $userId, $ttlSeconds, 'word');
  }

  public static function verifySpreadsheetLaunchToken(string $token, int $docId): ?array {
    return self::verifyEditorLaunchToken($token, $docId, 'spreadsheet');
  }

  public static function verifyWordLaunchToken(string $token, int $docId): ?array {
    return self::verifyEditorLaunchToken($token, $docId, 'word');
  }

  public static function createWordDocumentFromHtml(string $title, string $html): string {
    if (!class_exists('ZipArchive')) {
      throw new RuntimeException('DOCX export is unavailable on this server.');
    }

    $tmpPath = tempnam(sys_get_temp_dir(), 'cddfts_docx_');
    if ($tmpPath === false) {
      throw new RuntimeException('Unable to prepare DOCX export.');
    }

    $zip = new ZipArchive();
    if ($zip->open($tmpPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
      @unlink($tmpPath);
      throw new RuntimeException('Unable to create DOCX export.');
    }

    $safeTitle = trim($title) !== '' ? trim($title) : 'Untitled Document';
    $now = gmdate('Y-m-d\TH:i:s\Z');
    $documentXml = self::buildDocxDocumentXml($safeTitle, $html);

    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
      . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
      . '<Default Extension="xml" ContentType="application/xml"/>'
      . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
      . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
      . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
      . '</Types>');

    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
      . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
      . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
      . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
      . '</Relationships>');

    $zip->addFromString('word/_rels/document.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"></Relationships>');
    $zip->addFromString('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
      . '<dc:title>' . self::escapeWordXml($safeTitle) . '</dc:title><dc:creator>CDD-File-Tracking-System</dc:creator><cp:lastModifiedBy>CDD-File-Tracking-System</cp:lastModifiedBy>'
      . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:created>'
      . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:modified>'
      . '</cp:coreProperties>');
    $zip->addFromString('docProps/app.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
      . '<Application>CDD-File-Tracking-System</Application></Properties>');
    $zip->addFromString('word/document.xml', $documentXml);
    $zip->close();

    $contents = file_get_contents($tmpPath);
    @unlink($tmpPath);

    if ($contents === false) {
      throw new RuntimeException('Unable to read DOCX export.');
    }

    return $contents;
  }

  public static function extractDocxHtml(string $contents): string {
    if (!class_exists('ZipArchive') || !class_exists('DOMDocument')) {
      return '';
    }

    $tmpPath = tempnam(sys_get_temp_dir(), 'cddfts_docx_open_');
    if ($tmpPath === false) {
      return '';
    }

    try {
      if (file_put_contents($tmpPath, $contents, LOCK_EX) === false) {
        return '';
      }

      $zip = new ZipArchive();
      if ($zip->open($tmpPath) !== true) {
        return '';
      }

      $documentXml = $zip->getFromName('word/document.xml');
      if ($documentXml === false || $documentXml === '') {
        $zip->close();
        return '';
      }

      $relationships = self::readDocxRelationships($zip, 'word/_rels/document.xml.rels');
      $imageMap = self::readDocxEmbeddedImages($zip, 'word/_rels/document.xml.rels');
      $layout = self::extractDocxLayoutSettings((string)$documentXml);
      $headerHtml = self::readDocxHeaderFooterHtml($zip, $relationships, 'header');
      $bodyHtml = self::readDocxPartHtml((string)$documentXml, $imageMap);
      $footerHtml = self::readDocxHeaderFooterHtml($zip, $relationships, 'footer');
      $zip->close();

      return '<section data-word-page-root="true"'
        . ' data-page-size="' . self::escapeWordXml((string)$layout['page_size']) . '"'
        . ' data-page-margin="' . self::escapeWordXml((string)$layout['page_margin']) . '"'
        . ' data-page-orientation="' . self::escapeWordXml((string)$layout['orientation']) . '"'
        . ' data-page-custom-width-mm="' . self::escapeWordXml(self::formatMillimeters((float)$layout['custom_page_width_mm'])) . '"'
        . ' data-page-custom-height-mm="' . self::escapeWordXml(self::formatMillimeters((float)$layout['custom_page_height_mm'])) . '"'
        . ' data-page-custom-margin-top-mm="' . self::escapeWordXml(self::formatMillimeters((float)$layout['custom_margin_top_mm'])) . '"'
        . ' data-page-custom-margin-right-mm="' . self::escapeWordXml(self::formatMillimeters((float)$layout['custom_margin_right_mm'])) . '"'
        . ' data-page-custom-margin-bottom-mm="' . self::escapeWordXml(self::formatMillimeters((float)$layout['custom_margin_bottom_mm'])) . '"'
        . ' data-page-custom-margin-left-mm="' . self::escapeWordXml(self::formatMillimeters((float)$layout['custom_margin_left_mm'])) . '"'
        . '>' . trim($headerHtml . $bodyHtml . $footerHtml) . '</section>';
    } finally {
      @unlink($tmpPath);
    }
  }

  private static function createEditorLaunchToken(int $docId, int $userId, int $ttlSeconds, string $editor): string {
    $payload = [
      'doc_id' => $docId,
      'editor' => $editor,
      'user_id' => $userId,
      'exp' => time() + max(60, $ttlSeconds),
    ];

    $encodedPayload = self::base64UrlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES));
    $signature = hash_hmac('sha256', $encodedPayload, APP_SECRET, true);
    return $encodedPayload . '.' . self::base64UrlEncode($signature);
  }

  private static function verifyEditorLaunchToken(string $token, int $docId, string $editor): ?array {
    $parts = explode('.', trim($token), 2);
    if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
      return null;
    }

    [$encodedPayload, $encodedSignature] = $parts;
    $expectedSignature = self::base64UrlEncode(hash_hmac('sha256', $encodedPayload, APP_SECRET, true));
    if (!hash_equals($expectedSignature, $encodedSignature)) {
      return null;
    }

    $payloadJson = self::base64UrlDecode($encodedPayload);
    if ($payloadJson === null) {
      return null;
    }

    $payload = json_decode($payloadJson, true);
    if (!is_array($payload)) {
      return null;
    }

    if (($payload['editor'] ?? '') !== $editor) {
      return null;
    }

    if ($docId > 0 && (int)($payload['doc_id'] ?? 0) !== $docId) {
      return null;
    }

    if ($docId <= 0 && (int)($payload['doc_id'] ?? 0) !== 0) {
      return null;
    }

    if ((int)($payload['exp'] ?? 0) < time()) {
      return null;
    }

    return $payload;
  }

  private static function buildDocxDocumentXml(string $title, string $html): string {
    $layout = self::extractWordLayoutSettingsFromHtml($html);
    $bodyXml = self::buildWordBodyXmlFromHtml($html);
    if ($bodyXml === '') {
      $bodyXml = self::buildWordParagraphXml([
        ['text' => $title, 'bold' => true, 'size' => 28]
      ]);
    }

    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      . '<w:document xmlns:wpc="http://schemas.microsoft.com/office/word/2010/wordprocessingCanvas" xmlns:mc="http://schemas.openxmlformats.org/markup-compatibility/2006" xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:m="http://schemas.openxmlformats.org/officeDocument/2006/math" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w14="http://schemas.microsoft.com/office/word/2010/wordml" xmlns:wp14="http://schemas.microsoft.com/office/word/2010/wordprocessingDrawing" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:w10="urn:schemas-microsoft-com:office:word" xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" mc:Ignorable="w14 wp14">'
      . '<w:body>'
      . $bodyXml
      . '<w:sectPr>'
      . '<w:pgSz w:w="' . (int)$layout['page_width_twips'] . '" w:h="' . (int)$layout['page_height_twips'] . '"' . ($layout['orientation'] === 'landscape' ? ' w:orient="landscape"' : '') . '/>'
      . '<w:pgMar w:top="' . (int)$layout['margin_top_twips'] . '" w:right="' . (int)$layout['margin_right_twips'] . '" w:bottom="' . (int)$layout['margin_bottom_twips'] . '" w:left="' . (int)$layout['margin_left_twips'] . '" w:header="708" w:footer="708" w:gutter="0"/>'
      . '</w:sectPr>'
      . '</w:body>'
      . '</w:document>';
  }

  private static function extractWordLayoutSettingsFromHtml(string $html): array {
    $default = [
      'orientation' => 'portrait',
      'page_width_twips' => 12240,
      'page_height_twips' => 15840,
      'margin_top_twips' => 1440,
      'margin_right_twips' => 1440,
      'margin_bottom_twips' => 1440,
      'margin_left_twips' => 1440,
    ];

    if (!class_exists('DOMDocument')) {
      return $default;
    }

    $dom = new DOMDocument('1.0', 'UTF-8');
    $wrappedHtml = '<!doctype html><html><body>' . $html . '</body></html>';
    if (!@$dom->loadHTML(mb_convert_encoding($wrappedHtml, 'HTML-ENTITIES', 'UTF-8'), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD)) {
      return $default;
    }

    $layoutRoot = null;
    foreach ($dom->getElementsByTagName('*') as $element) {
      if (strtolower((string)$element->getAttribute('data-word-page-root')) === 'true') {
        $layoutRoot = $element;
        break;
      }
    }

    if (!$layoutRoot instanceof DOMElement) {
      return $default;
    }

    $pageSize = strtoupper(trim((string)$layoutRoot->getAttribute('data-page-size')));
    $pageMargin = strtoupper(trim((string)$layoutRoot->getAttribute('data-page-margin')));
    $orientation = strtolower(trim((string)$layoutRoot->getAttribute('data-page-orientation'))) === 'landscape' ? 'landscape' : 'portrait';

    $sizes = [
      'LETTER' => [12240, 15840],
      'A4' => [11907, 16839],
      'LEGAL' => [12240, 20160],
      'A5' => [8391, 11907],
    ];
    $margins = [
      'NORMAL' => [1440, 1440, 1440, 1440],
      'NARROW' => [720, 720, 720, 720],
      'MODERATE' => [1440, 1080, 1440, 1080],
      'WIDE' => [1440, 2880, 1440, 2880],
    ];

    if ($pageSize === 'CUSTOM') {
      $pageWidth = self::twipsFromMillimeterAttribute((string)$layoutRoot->getAttribute('data-page-custom-width-mm'), $default['page_width_twips'], 90, 1000);
      $pageHeight = self::twipsFromMillimeterAttribute((string)$layoutRoot->getAttribute('data-page-custom-height-mm'), $default['page_height_twips'], 90, 1000);
    } elseif (isset($sizes[$pageSize])) {
      [$pageWidth, $pageHeight] = $sizes[$pageSize];
    } else {
      $pageSize = 'LETTER';
      [$pageWidth, $pageHeight] = $sizes[$pageSize];
    }

    if ($pageMargin === 'CUSTOM') {
      $marginTop = self::twipsFromMillimeterAttribute((string)$layoutRoot->getAttribute('data-page-custom-margin-top-mm'), $default['margin_top_twips'], 0, 120);
      $marginRight = self::twipsFromMillimeterAttribute((string)$layoutRoot->getAttribute('data-page-custom-margin-right-mm'), $default['margin_right_twips'], 0, 120);
      $marginBottom = self::twipsFromMillimeterAttribute((string)$layoutRoot->getAttribute('data-page-custom-margin-bottom-mm'), $default['margin_bottom_twips'], 0, 120);
      $marginLeft = self::twipsFromMillimeterAttribute((string)$layoutRoot->getAttribute('data-page-custom-margin-left-mm'), $default['margin_left_twips'], 0, 120);
    } elseif (isset($margins[$pageMargin])) {
      [$marginTop, $marginRight, $marginBottom, $marginLeft] = $margins[$pageMargin];
    } else {
      $pageMargin = 'NORMAL';
      [$marginTop, $marginRight, $marginBottom, $marginLeft] = $margins[$pageMargin];
    }

    if ($orientation === 'landscape') {
      [$pageWidth, $pageHeight] = [$pageHeight, $pageWidth];
    }

    return [
      'orientation' => $orientation,
      'page_width_twips' => $pageWidth,
      'page_height_twips' => $pageHeight,
      'margin_top_twips' => $marginTop,
      'margin_right_twips' => $marginRight,
      'margin_bottom_twips' => $marginBottom,
      'margin_left_twips' => $marginLeft,
    ];
  }

  private static function extractDocxLayoutSettings(string $documentXml): array {
    $default = [
      'orientation' => 'portrait',
      'page_size' => 'LETTER',
      'page_margin' => 'NORMAL',
      'custom_page_width_mm' => 216,
      'custom_page_height_mm' => 279,
      'custom_margin_top_mm' => 25,
      'custom_margin_right_mm' => 25,
      'custom_margin_bottom_mm' => 25,
      'custom_margin_left_mm' => 25,
    ];

    if (!class_exists('DOMDocument') || $documentXml === '') {
      return $default;
    }

    $dom = new DOMDocument('1.0', 'UTF-8');
    if (!@$dom->loadXML($documentXml)) {
      return $default;
    }

    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
    $sectPr = $xpath->query('//w:sectPr')->item(0);
    if (!$sectPr instanceof DOMElement) {
      return $default;
    }

    $pgSz = $xpath->query('./w:pgSz', $sectPr)->item(0);
    $pgMar = $xpath->query('./w:pgMar', $sectPr)->item(0);
    $width = $pgSz instanceof DOMElement ? (int)$pgSz->getAttribute('w:w') : 12240;
    $height = $pgSz instanceof DOMElement ? (int)$pgSz->getAttribute('w:h') : 15840;
    $orientation = ($pgSz instanceof DOMElement && strtolower((string)$pgSz->getAttribute('w:orient')) === 'landscape') || $width > $height
      ? 'landscape'
      : 'portrait';
    if ($orientation === 'landscape' && $width > $height) {
      [$width, $height] = [$height, $width];
    }

    $pageSize = 'CUSTOM';
    foreach ([
      'LETTER' => [12240, 15840],
      'A4' => [11907, 16839],
      'LEGAL' => [12240, 20160],
      'A5' => [8391, 11907],
    ] as $label => [$knownWidth, $knownHeight]) {
      if (abs($width - $knownWidth) <= 120 && abs($height - $knownHeight) <= 120) {
        $pageSize = $label;
        break;
      }
    }

    $top = $pgMar instanceof DOMElement ? (int)$pgMar->getAttribute('w:top') : 1440;
    $right = $pgMar instanceof DOMElement ? (int)$pgMar->getAttribute('w:right') : 1440;
    $bottom = $pgMar instanceof DOMElement ? (int)$pgMar->getAttribute('w:bottom') : 1440;
    $left = $pgMar instanceof DOMElement ? (int)$pgMar->getAttribute('w:left') : 1440;
    $pageMargin = 'CUSTOM';
    foreach ([
      'NORMAL' => [1440, 1440, 1440, 1440],
      'NARROW' => [720, 720, 720, 720],
      'MODERATE' => [1440, 1080, 1440, 1080],
      'WIDE' => [1440, 2880, 1440, 2880],
    ] as $label => [$knownTop, $knownRight, $knownBottom, $knownLeft]) {
      if (abs($top - $knownTop) <= 120 && abs($right - $knownRight) <= 120 && abs($bottom - $knownBottom) <= 120 && abs($left - $knownLeft) <= 120) {
        $pageMargin = $label;
        break;
      }
    }

    return [
      'orientation' => $orientation,
      'page_size' => $pageSize,
      'page_margin' => $pageMargin,
      'custom_page_width_mm' => self::millimetersFromTwips($width),
      'custom_page_height_mm' => self::millimetersFromTwips($height),
      'custom_margin_top_mm' => self::millimetersFromTwips($top),
      'custom_margin_right_mm' => self::millimetersFromTwips($right),
      'custom_margin_bottom_mm' => self::millimetersFromTwips($bottom),
      'custom_margin_left_mm' => self::millimetersFromTwips($left),
    ];
  }

  private static function buildWordBodyXmlFromHtml(string $html): string {
    if (!class_exists('DOMDocument')) {
      $text = trim(strip_tags($html));
      return $text !== '' ? self::buildWordParagraphXml([['text' => $text]]) : '';
    }

    $dom = new DOMDocument('1.0', 'UTF-8');
    $wrappedHtml = '<!doctype html><html><body>' . $html . '</body></html>';
    if (!@$dom->loadHTML(mb_convert_encoding($wrappedHtml, 'HTML-ENTITIES', 'UTF-8'), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD)) {
      $text = trim(strip_tags($html));
      return $text !== '' ? self::buildWordParagraphXml([['text' => $text]]) : '';
    }

    $body = $dom->getElementsByTagName('body')->item(0);
    if (!$body) {
      return '';
    }

    $xml = '';
    foreach ($body->childNodes as $child) {
      $xml .= self::renderWordBlockNode($child);
    }

    return $xml;
  }

  private static function renderWordBlockNode(DOMNode $node, array $style = []): string {
    if ($node->nodeType === XML_TEXT_NODE) {
      $text = trim((string)$node->textContent);
      return $text !== '' ? self::buildWordParagraphXml([['text' => $text]]) : '';
    }

    if (!$node instanceof DOMElement) {
      return '';
    }

    $tag = strtolower($node->tagName);
    if (self::isWordPageBreakElement($node)) {
      return '<w:p><w:r><w:br w:type="page"/></w:r></w:p>';
    }

    if ($tag === 'hr') {
      return '';
    }

    if (in_array($tag, ['ul', 'ol'], true)) {
      $xml = '';
      $index = 1;
      foreach ($node->childNodes as $child) {
        if (!($child instanceof DOMElement) || strtolower($child->tagName) !== 'li') {
          continue;
        }

        $marker = $tag === 'ol' ? $index . '. ' : '- ';
        $runs = self::collectWordInlineRuns($child, $style);
        array_unshift($runs, ['text' => $marker]);
        $xml .= self::buildWordParagraphXml($runs);
        $index++;
      }
      return $xml;
    }

    if ($tag === 'table') {
      $xml = '';
      foreach ($node->getElementsByTagName('tr') as $row) {
        $cells = [];
        foreach ($row->childNodes as $cell) {
          if ($cell instanceof DOMElement && in_array(strtolower($cell->tagName), ['td', 'th'], true)) {
            $cells[] = trim(preg_replace('/\s+/u', ' ', (string)$cell->textContent));
          }
        }
        if (!empty($cells)) {
          $xml .= self::buildWordParagraphXml([['text' => implode('    ', $cells)]]);
        }
      }
      return $xml;
    }

    if (in_array($tag, ['div', 'section', 'article', 'header', 'footer', 'main'], true)) {
      $xml = '';
      foreach ($node->childNodes as $child) {
        $xml .= self::renderWordBlockNode($child, $style);
      }
      return $xml;
    }

    $blockStyle = self::mergeWordStyle($style, self::wordStyleFromElement($node));
    if ($tag === 'h1') {
      $blockStyle['bold'] = true;
      $blockStyle['size'] = 32;
    } elseif ($tag === 'h2') {
      $blockStyle['bold'] = true;
      $blockStyle['size'] = 28;
    } elseif ($tag === 'h3') {
      $blockStyle['bold'] = true;
      $blockStyle['size'] = 24;
    } elseif ($tag === 'blockquote') {
      $blockStyle['italic'] = true;
    }

    $runs = self::collectWordInlineRuns($node, $blockStyle);
    if (empty($runs)) {
      $text = trim(preg_replace('/\s+/u', ' ', (string)$node->textContent));
      if ($text === '') {
        return '';
      }
      $runs = [['text' => $text] + $blockStyle];
    }

    return self::buildWordParagraphXml($runs, $blockStyle);
  }

  private static function collectWordInlineRuns(DOMNode $node, array $style = []): array {
    $runs = [];
    foreach ($node->childNodes as $child) {
      if ($child->nodeType === XML_TEXT_NODE) {
        $text = preg_replace('/\s+/u', ' ', (string)$child->textContent);
        if ($text !== null && trim($text) !== '') {
          $runs[] = ['text' => $text] + $style;
        }
        continue;
      }

      if (!($child instanceof DOMElement)) {
        continue;
      }

      $tag = strtolower($child->tagName);
      if ($tag === 'br') {
        $runs[] = ['break' => true] + $style;
        continue;
      }

      if (in_array($tag, ['ul', 'ol', 'table'], true)) {
        continue;
      }

      $childStyle = self::mergeWordStyle($style, self::wordStyleFromElement($child));
      if ($tag === 'strong' || $tag === 'b') {
        $childStyle['bold'] = true;
      }
      if ($tag === 'em' || $tag === 'i') {
        $childStyle['italic'] = true;
      }
      if ($tag === 'u') {
        $childStyle['underline'] = true;
      }
      if (in_array($tag, ['s', 'strike', 'del'], true)) {
        $childStyle['strike'] = true;
      }

      if ($tag === 'img') {
        continue;
      }

      $childRuns = self::collectWordInlineRuns($child, $childStyle);
      if (!empty($childRuns)) {
        foreach ($childRuns as $run) {
          $runs[] = $run;
        }
        continue;
      }

      $text = preg_replace('/\s+/u', ' ', (string)$child->textContent);
      if ($text !== null && trim($text) !== '') {
        $runs[] = ['text' => $text] + $childStyle;
      }
    }

    return $runs;
  }

  private static function buildWordParagraphXml(array $runs, array $paragraphStyle = []): string {
    $runXml = '';
    foreach ($runs as $run) {
      if (!empty($run['break'])) {
        $runXml .= '<w:r><w:br/></w:r>';
        continue;
      }

      $text = (string)($run['text'] ?? '');
      if ($text === '') {
        continue;
      }

      $properties = self::buildWordRunProperties($run);
      $space = preg_match('/^\s|\s$/u', $text) ? ' xml:space="preserve"' : '';
      $runXml .= '<w:r>' . $properties . '<w:t' . $space . '>' . self::escapeWordXml($text) . '</w:t></w:r>';
    }

    if ($runXml === '') {
      $runXml = '<w:r><w:t></w:t></w:r>';
    }

    $paragraphProperties = '';
    $align = (string)($paragraphStyle['align'] ?? '');
    if (in_array($align, ['left', 'center', 'right', 'both'], true)) {
      $paragraphProperties .= '<w:jc w:val="' . $align . '"/>';
    }

    return '<w:p>' . ($paragraphProperties !== '' ? '<w:pPr>' . $paragraphProperties . '</w:pPr>' : '') . $runXml . '</w:p>';
  }

  private static function buildWordRunProperties(array $style): string {
    $properties = '';

    if (!empty($style['bold'])) {
      $properties .= '<w:b/>';
    }
    if (!empty($style['italic'])) {
      $properties .= '<w:i/>';
    }
    if (!empty($style['underline'])) {
      $properties .= '<w:u w:val="single"/>';
    }
    if (!empty($style['strike'])) {
      $properties .= '<w:strike/>';
    }

    $size = (int)($style['size'] ?? 0);
    if ($size > 0) {
      $properties .= '<w:sz w:val="' . $size . '"/><w:szCs w:val="' . $size . '"/>';
    }

    $color = self::normalizeWordColor((string)($style['color'] ?? ''));
    if ($color !== '') {
      $properties .= '<w:color w:val="' . $color . '"/>';
    }

    return $properties !== '' ? '<w:rPr>' . $properties . '</w:rPr>' : '';
  }

  private static function wordStyleFromElement(DOMElement $element): array {
    $style = [];
    $styleAttribute = strtolower(trim((string)$element->getAttribute('style')));
    if ($styleAttribute === '') {
      return $style;
    }

    foreach (explode(';', $styleAttribute) as $declaration) {
      if (!str_contains($declaration, ':')) {
        continue;
      }

      [$property, $value] = array_map('trim', explode(':', $declaration, 2));
      if ($property === '' || $value === '') {
        continue;
      }

      if ($property === 'font-weight' && ($value === 'bold' || (is_numeric($value) && (int)$value >= 600))) {
        $style['bold'] = true;
      } elseif ($property === 'font-style' && $value === 'italic') {
        $style['italic'] = true;
      } elseif ($property === 'text-decoration' && str_contains($value, 'underline')) {
        $style['underline'] = true;
      } elseif ($property === 'text-decoration' && str_contains($value, 'line-through')) {
        $style['strike'] = true;
      } elseif ($property === 'color') {
        $style['color'] = $value;
      } elseif ($property === 'text-align') {
        $style['align'] = match ($value) {
          'center' => 'center',
          'right' => 'right',
          'justify' => 'both',
          default => 'left',
        };
      } elseif ($property === 'font-size') {
        $style['size'] = self::wordFontSizeFromCss($value);
      }
    }

    return $style;
  }

  private static function mergeWordStyle(array $base, array $next): array {
    return array_merge($base, array_filter($next, static fn($value): bool => $value !== '' && $value !== null));
  }

  private static function wordFontSizeFromCss(string $value): int {
    if (!preg_match('/([\d.]+)/', $value, $matches)) {
      return 0;
    }

    $numeric = (float)$matches[1];
    if (str_contains($value, 'px')) {
      return max(16, (int)round($numeric * 1.5));
    }

    if (str_contains($value, 'pt')) {
      return max(16, (int)round($numeric * 2));
    }

    return max(16, (int)round($numeric * 2));
  }

  private static function normalizeWordColor(string $value): string {
    $value = strtoupper(trim($value));
    if (preg_match('/^#?([0-9A-F]{6})$/', $value, $matches)) {
      return $matches[1];
    }

    return '';
  }

  private static function escapeWordXml(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
  }

  private static function isWordPageBreakElement(DOMElement $element): bool {
    if (strtolower(trim((string)$element->getAttribute('data-word-page-break'))) === 'true') {
      return true;
    }

    return preg_match('/(?:^|\s)docx-page-break(?:\s|$)/i', (string)$element->getAttribute('class')) === 1;
  }

  private static function wordPageBreakHtml(): string {
    return '<hr class="docx-page-break" data-word-page-break="true">';
  }

  private static function twipsFromMillimeterAttribute(string $value, int $fallbackTwips, float $minMm, float $maxMm): int {
    $trimmed = trim($value);
    if (!is_numeric($trimmed)) {
      return $fallbackTwips;
    }

    $number = min($maxMm, max($minMm, (float)$trimmed));
    return (int)round(($number / 25.4) * 1440);
  }

  private static function millimetersFromTwips(int $twips): float {
    return round(($twips / 1440) * 25.4, 2);
  }

  private static function formatMillimeters(float $value): string {
    $formatted = number_format($value, 2, '.', '');
    return rtrim(rtrim($formatted, '0'), '.');
  }

  private static function readDocxRelationships(ZipArchive $zip, string $relsPath): array {
    $relsXml = $zip->getFromName($relsPath);
    if ($relsXml === false || $relsXml === '' || !class_exists('DOMDocument')) {
      return [];
    }

    $dom = new DOMDocument();
    if (!@$dom->loadXML($relsXml)) {
      return [];
    }

    $relationships = [];
    foreach ($dom->getElementsByTagName('Relationship') as $relationship) {
      if (!($relationship instanceof DOMElement)) {
        continue;
      }

      $id = trim((string)$relationship->getAttribute('Id'));
      $type = trim((string)$relationship->getAttribute('Type'));
      $target = trim((string)$relationship->getAttribute('Target'));
      if ($id === '' || $target === '') {
        continue;
      }

      $relationships[$id] = [
        'type' => $type,
        'target' => self::resolveDocxTargetPath($target),
      ];
    }

    return $relationships;
  }

  private static function resolveDocxTargetPath(string $target): string {
    $target = str_replace('\\', '/', trim($target));
    if ($target === '') {
      return '';
    }
    if (str_starts_with($target, '/')) {
      return ltrim($target, '/');
    }

    $segments = [];
    foreach (explode('/', 'word/' . $target) as $segment) {
      if ($segment === '' || $segment === '.') {
        continue;
      }
      if ($segment === '..') {
        array_pop($segments);
        continue;
      }
      $segments[] = $segment;
    }

    return implode('/', $segments);
  }

  private static function readDocxEmbeddedImages(ZipArchive $zip, string $relsPath): array {
    $relationships = self::readDocxRelationships($zip, $relsPath);
    $images = [];
    foreach ($relationships as $id => $relationship) {
      $type = (string)($relationship['type'] ?? '');
      $target = (string)($relationship['target'] ?? '');
      if ($target === '' || !str_contains($type, '/image')) {
        continue;
      }

      $binary = $zip->getFromName($target);
      if ($binary === false || $binary === '') {
        continue;
      }

      $images[$id] = 'data:' . self::mimeTypeFromPath($target) . ';base64,' . base64_encode($binary);
    }

    return $images;
  }

  private static function mimeTypeFromPath(string $path): string {
    return match (strtolower((string)pathinfo($path, PATHINFO_EXTENSION))) {
      'png' => 'image/png',
      'jpg', 'jpeg' => 'image/jpeg',
      'gif' => 'image/gif',
      'webp' => 'image/webp',
      'bmp' => 'image/bmp',
      'svg' => 'image/svg+xml',
      default => 'application/octet-stream',
    };
  }

  private static function readDocxHeaderFooterHtml(ZipArchive $zip, array $relationships, string $typeNeedle): string {
    $html = '';
    foreach ($relationships as $relationship) {
      $type = (string)($relationship['type'] ?? '');
      $target = (string)($relationship['target'] ?? '');
      if ($target === '' || !str_contains($type, '/' . $typeNeedle)) {
        continue;
      }

      $partXml = $zip->getFromName($target);
      if ($partXml === false || $partXml === '') {
        continue;
      }

      $html .= self::readDocxPartHtml(
        (string)$partXml,
        self::readDocxEmbeddedImages($zip, self::docxRelationshipsPathForPart($target))
      );
    }

    return $html;
  }

  private static function docxRelationshipsPathForPart(string $partPath): string {
    $partPath = str_replace('\\', '/', trim($partPath));
    if ($partPath === '') {
      return '';
    }

    $dir = dirname($partPath);
    $base = basename($partPath);
    if ($dir === '.' || $dir === '') {
      return '_rels/' . $base . '.rels';
    }

    return $dir . '/_rels/' . $base . '.rels';
  }

  private static function readDocxPartHtml(string $xml, array $imageMap): string {
    if (!class_exists('DOMDocument')) {
      return '';
    }

    $dom = new DOMDocument();
    if (!@$dom->loadXML($xml)) {
      return '';
    }

    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
    $paragraphs = $xpath->query('//w:p');
    if (!$paragraphs) {
      return '';
    }

    $html = '';
    foreach ($paragraphs as $paragraph) {
      $content = '';
      foreach ($paragraph->childNodes as $child) {
        $content .= self::renderDocxInlineNode($child, $imageMap);
      }

      $content = trim($content);
      if ($content !== '') {
        $parts = explode(self::WORD_PAGE_BREAK_MARKER, $content);
        $lastIndex = count($parts) - 1;
        foreach ($parts as $index => $part) {
          $part = trim($part);
          if ($part !== '') {
            $html .= '<p>' . $part . '</p>';
          }
          if ($index < $lastIndex) {
            $html .= self::wordPageBreakHtml();
          }
        }
      }
    }

    return $html;
  }

  private static function renderDocxInlineNode(DOMNode $node, array $imageMap): string {
    if ($node->nodeType === XML_TEXT_NODE) {
      return '';
    }

    $name = $node->localName ?? '';
    if ($name === 't') {
      return htmlspecialchars((string)$node->textContent, ENT_QUOTES, 'UTF-8');
    }
    if ($name === 'tab') {
      return '&emsp;';
    }
    if ($name === 'br') {
      $type = '';
      if ($node instanceof DOMElement) {
        $type = trim((string)$node->getAttribute('w:type'));
        if ($type === '') {
          $type = trim((string)$node->getAttributeNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'type'));
        }
      }
      if (strtolower($type) === 'page') {
        return self::WORD_PAGE_BREAK_MARKER;
      }
      return '<br>';
    }
    if ($name === 'blip' && $node instanceof DOMElement) {
      $embedId = trim((string)$node->getAttribute('r:embed'));
      if ($embedId === '') {
        $embedId = trim((string)$node->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'embed'));
      }
      $src = $embedId !== '' ? ($imageMap[$embedId] ?? '') : '';
      if ($src !== '') {
        return '<img src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '" alt="Embedded image">';
      }
    }

    $html = '';
    foreach ($node->childNodes as $child) {
      $html .= self::renderDocxInlineNode($child, $imageMap);
    }

    return $html;
  }

  public static function createBlankEditableDocument(
    PDO $pdo,
    int $ownerId,
    string $extension,
    int $userId,
    string $storageArea = 'OFFICIAL',
    ?int $divisionId = null,
    array $metadata = [],
    ?int $folderId = null
  ): int {
    $normalizedExtension = self::normalizeEditableExtension($extension);
    if ($normalizedExtension === null) {
      throw new RuntimeException('Unsupported file type');
    }

    $baseName = trim((string)($metadata['title'] ?? ''));
    if ($baseName === '') {
      $baseName = $normalizedExtension === 'docx' ? 'Untitled Document' : 'Untitled Spreadsheet';
    }
    $baseName = preg_replace('/\.[^.]+$/', '', $baseName);
    $originalName = self::sanitizeFilename($baseName . '.' . $normalizedExtension);

    $contents = self::buildBlankEditableContents($normalizedExtension);
    return self::createEditableDocumentFromContents(
      $pdo,
      $ownerId,
      $originalName,
      $contents,
      $userId,
      $storageArea,
      $divisionId,
      $metadata,
      $folderId
    );
  }

  public static function createEditableDocumentFromContents(
    PDO $pdo,
    int $ownerId,
    string $originalName,
    string $contents,
    int $userId,
    string $storageArea = 'OFFICIAL',
    ?int $divisionId = null,
    array $metadata = [],
    ?int $folderId = null
  ): int {
    $storageArea = Document::normalizeStorageArea($storageArea);
    $sourceName = self::sanitizeFilename($originalName !== '' ? $originalName : 'Untitled Document.docx');
    $ext = self::normalizeEditableExtension((string)pathinfo($sourceName, PATHINFO_EXTENSION));
    if ($ext === null) {
      throw new RuntimeException('Unsupported file type');
    }

    if (!str_ends_with(strtolower($sourceName), '.' . $ext)) {
      $sourceName = preg_replace('/\.[^.]+$/', '', $sourceName) . '.' . $ext;
    }

    $size = strlen($contents);
    if ($size <= 0 || $size > MAX_UPLOAD_BYTES_ADMIN) {
      throw new RuntimeException('File exceeds upload size policy');
    }

    $mime = $ext === 'docx'
      ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
      : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    self::assertWithinQuota($ownerId, $size, $storageArea);

    $mergedMetadata = array_merge([
      'document_code' => '',
      'title' => pathinfo($sourceName, PATHINFO_FILENAME),
      'document_type' => 'INCOMING',
      'signatory' => '',
      'current_location' => 'Current holder',
      'routing_status' => 'AVAILABLE',
      'priority_level' => 'NORMAL',
      'document_date' => date('Y-m-d'),
      'category' => $ext === 'docx' ? 'Document' : 'Spreadsheet',
      'status' => 'Draft',
      'retention_until' => null,
    ], $metadata);

    $versionNumber = 1;
    $safeName = self::buildStoredFilename(0, $versionNumber, $sourceName);
    $relativePath = self::relativePath($ownerId, $safeName, $storageArea);

    if (!StorageService::storeContents($pdo, $relativePath, $contents, [
      'kind' => 'document_version',
      'visibility' => 'private',
      'original_name' => $sourceName,
      'mime_type' => $mime,
      'created_by' => $userId,
    ])) {
      throw new RuntimeException('Failed to store file');
    }

    $documentId = Document::create($pdo, $ownerId, $folderId, $sourceName, $storageArea, $divisionId, $mergedMetadata);
    Version::add($pdo, $documentId, $userId, $relativePath, $versionNumber);
    AuditLog::add($pdo, $userId, 'Created blank document', $documentId, $sourceName);

    return $documentId;
  }

  public static function createSpreadsheetDocumentFromContents(PDO $pdo, int $ownerId, string $originalName, string $contents, int $userId, string $storageArea = 'OFFICIAL'): int {
    return self::createEditableDocumentFromContents(
      $pdo,
      $ownerId,
      $originalName !== '' ? $originalName : 'spreadsheet.xlsx',
      $contents,
      $userId,
      $storageArea,
      null,
      ['category' => 'Spreadsheet']
    );
  }

  public static function uploadNewVersionFromContents(PDO $pdo, int $docId, string $originalName, string $contents, int $userId): int {
    $doc = Document::get($pdo, $docId);
    if (!$doc) {
      throw new RuntimeException('Document not found');
    }

    $sourceName = self::sanitizeFilename($originalName !== '' ? $originalName : (string)$doc['name']);
    $ext = strtolower((string)pathinfo($sourceName, PATHINFO_EXTENSION));
    $docExt = strtolower((string)pathinfo((string)$doc['name'], PATHINFO_EXTENSION));
    if ($ext === '') {
      $ext = $docExt;
      $sourceName .= ($docExt !== '' ? ('.' . $docExt) : '');
    }

    if (!isset(self::ALLOWED_UPLOAD_MIME_TYPES[$ext])) {
      throw new RuntimeException('Unsupported file type');
    }

    if ($docExt !== $ext) {
      throw new RuntimeException('Uploaded version must match the document format');
    }

    $size = strlen($contents);
    if ($size <= 0 || $size > MAX_UPLOAD_BYTES_ADMIN) {
      throw new RuntimeException('File exceeds upload size policy');
    }

    $tmpPath = tempnam(sys_get_temp_dir(), 'cddfts_xlsx_');
    if ($tmpPath === false) {
      throw new RuntimeException('Unable to prepare upload payload');
    }

    try {
      if (file_put_contents($tmpPath, $contents, LOCK_EX) === false) {
        throw new RuntimeException('Unable to prepare upload payload');
      }

      $mime = self::detectMimeType($tmpPath);
      if ($mime !== null && !in_array($mime, self::ALLOWED_UPLOAD_MIME_TYPES[$ext], true)) {
        throw new RuntimeException('Uploaded file content does not match its extension');
      }
    } finally {
      @unlink($tmpPath);
    }

    $storageArea = (string)($doc['storage_area'] ?? 'PRIVATE');
    self::assertWithinQuota((int)$doc['owner_id'], $size, $storageArea);

    $versionNumber = Version::nextNumber($pdo, $docId);
    $safeName = self::buildStoredFilename($docId, $versionNumber, $sourceName);
    $relativePath = self::relativePath((int)$doc['owner_id'], $safeName, $storageArea);

    if (!StorageService::storeContents($pdo, $relativePath, $contents, [
      'kind' => 'document_version',
      'visibility' => 'private',
      'original_name' => $sourceName,
      'mime_type' => $mime ?? 'application/octet-stream',
      'created_by' => $userId,
    ])) {
      throw new RuntimeException('Failed to store file');
    }

    Version::add($pdo, $docId, $userId, $relativePath, $versionNumber);
    self::archiveNonLatestVersions($pdo, $docId, (int)$doc['owner_id'], $versionNumber, $storageArea);
    AuditLog::add($pdo, $userId, 'Uploaded new version from editor', $docId, 'version=' . $versionNumber);

    return $versionNumber;
  }

  private static function storeUploadedVersion(PDO $pdo, int $docId, array $file, int $userId): int {
    if (!isset($file['tmp_name']) || !self::isAcceptedUploadSource((string)$file['tmp_name'])) {
      throw new RuntimeException("Invalid upload");
    }

    $doc = Document::get($pdo, $docId);
    if (!$doc) {
      throw new RuntimeException("Document not found");
    }

    $ver = Version::nextNumber($pdo, $docId);
    $sourceName = self::sanitizeFilename((string)($file['name'] ?? $doc['name']));
    $ext = strtolower((string)pathinfo($sourceName, PATHINFO_EXTENSION));
    $docExt = strtolower((string)pathinfo((string)$doc['name'], PATHINFO_EXTENSION));
    self::assertUploadConstraints($file, $userId);

    if ($docExt !== $ext) {
      throw new RuntimeException("Uploaded version must match the document format");
    }
    $storageArea = (string)($doc['storage_area'] ?? 'PRIVATE');
    self::assertWithinQuota((int)$doc['owner_id'], (int)($file['size'] ?? 0), $storageArea);

    $safeName = self::buildStoredFilename($docId, $ver, $sourceName);
    $relativePath = self::relativePath((int)$doc['owner_id'], $safeName, $storageArea);
    if (!StorageService::storeUploadedFile($pdo, $file, $relativePath, [
      'kind' => 'document_version',
      'visibility' => 'private',
      'original_name' => $sourceName,
      'mime_type' => self::detectMimeType((string)$file['tmp_name']),
      'created_by' => $userId,
    ])) {
      throw new RuntimeException("Failed to store file");
    }

    Version::add($pdo, $docId, $userId, $relativePath, $ver);
    self::archiveNonLatestVersions($pdo, $docId, (int)$doc['owner_id'], $ver, $storageArea);
    return $ver;
  }

  private static function archiveNonLatestVersions(PDO $pdo, int $docId, int $ownerId, int $latestVersionNumber, string $storageArea = 'PRIVATE'): void {
    if ($latestVersionNumber <= 1) {
      return;
    }

    $s = $pdo->prepare("\n      SELECT id, file_path, version_number\n      FROM document_versions\n      WHERE document_id = ? AND version_number < ?\n      ORDER BY version_number ASC\n    ");
    $s->execute([$docId, $latestVersionNumber]);
    $rows = $s->fetchAll();

    foreach ($rows as $row) {
      $currentPath = (string)$row['file_path'];
      if (!StorageService::exists($pdo, $currentPath)) {
        continue;
      }

      $base = basename($currentPath);
      $targetPath = self::relativePathForArchivedVersion($ownerId, $docId, $base, $storageArea);
      if (strtolower($currentPath) === strtolower($targetPath)) {
        continue;
      }

      $targetPath = self::resolveArchiveCollision($pdo, $targetPath);
      if (!StorageService::move($pdo, $currentPath, $targetPath)) {
        if (!StorageService::copy($pdo, $currentPath, $targetPath) ) {
          continue;
        }
        StorageService::delete($pdo, $currentPath);
      }

      $u = $pdo->prepare("UPDATE document_versions SET file_path=? WHERE id=?");
      $u->execute([$targetPath, (int)$row['id']]);
    }
  }

  private static function relativePathForArchivedVersion(int $ownerId, int $docId, string $basename, string $storageArea = 'PRIVATE'): string {
    $segment = strtolower(Document::normalizeStorageArea($storageArea));
    return "../storage/documents/" . $segment . "/" . $ownerId . "/previous_versions/" . $docId . "/" . $basename;
  }

  private static function resolveArchiveCollision(PDO $pdo, string $targetPath): string {
    if (!StorageService::exists($pdo, $targetPath)) {
      return $targetPath;
    }

    $dir = dirname(str_replace('\\', '/', $targetPath));
    $name = pathinfo($targetPath, PATHINFO_FILENAME);
    $ext = pathinfo($targetPath, PATHINFO_EXTENSION);
    $suffix = '_' . time();
    return rtrim(str_replace('\\', '/', $dir), '/') . '/' . $name . $suffix . ($ext !== '' ? '.' . $ext : '');
  }

  private static function normalizeEditableExtension(string $extension): ?string {
    $ext = strtolower(trim($extension));
    return in_array($ext, self::EDITABLE_EXTENSIONS, true) ? $ext : null;
  }

  private static function assertUploadConstraints(array $file, int $actorId): void {
    $error = (int)($file['error'] ?? UPLOAD_ERR_OK);
    if ($error !== UPLOAD_ERR_OK) {
      throw new RuntimeException("Upload failed");
    }

    $tmpName = (string)($file['tmp_name'] ?? '');
    if ($tmpName === '' || !self::isAcceptedUploadSource($tmpName)) {
      throw new RuntimeException("Invalid upload");
    }

    $size = (int)($file['size'] ?? 0);
    $userRole = strtoupper((string)($_SESSION['user']['role'] ?? 'USER'));
    $max = in_array($userRole, ['SUPER_ADMIN', 'ADMIN'], true) ? MAX_UPLOAD_BYTES_ADMIN : MAX_UPLOAD_BYTES_USER;

    if ($size <= 0 || $size > $max) {
      throw new RuntimeException("File exceeds upload size policy");
    }

    $extension = strtolower((string)pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
    if (!isset(self::ALLOWED_UPLOAD_MIME_TYPES[$extension])) {
      throw new RuntimeException("Unsupported file type");
    }

    $mime = self::detectMimeType($tmpName);
    if ($mime === null) {
      return;
    }

    if (!in_array($mime, self::ALLOWED_UPLOAD_MIME_TYPES[$extension], true)) {
      throw new RuntimeException("Uploaded file content does not match its extension");
    }
  }

  private static function ownerStorageBytes(int $ownerId, string $storageArea = 'ALL'): int {
    $storageArea = strtoupper(trim($storageArea));
    $prefixes = [];
    if ($storageArea === 'ALL' || $storageArea === 'PRIVATE') {
      $prefixes[] = "../storage/documents/private/" . $ownerId . "/";
    }
    if ($storageArea === 'ALL' || $storageArea === 'OFFICIAL') {
      $prefixes[] = "../storage/documents/official/" . $ownerId . "/";
    }

    return (int)StorageService::storageUsage($GLOBALS['pdo'], $prefixes)['bytes'];
  }

  private static function assertWithinQuota(int $ownerId, int $incomingBytes, string $storageArea = 'PRIVATE'): void {
    if ($incomingBytes <= 0) {
      return;
    }

    $summary = self::ownerStorageSummary($ownerId, $storageArea);
    if (($summary['used'] + $incomingBytes) > $summary['limit']) {
      throw new RuntimeException("User storage limit exceeded");
    }
  }

  private static function sanitizeFilename(string $name): string {
    $clean = preg_replace('/[^\w\-. ]+/', '_', $name);
    return trim((string)$clean) ?: 'file';
  }

  private static function detectMimeType(string $path): ?string {
    if (!is_file($path)) {
      return null;
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo === false) {
      return null;
    }

    $mime = finfo_file($finfo, $path);
    finfo_close($finfo);
    if ($mime === false) {
      return null;
    }

    return strtolower(trim((string)$mime));
  }

  private static function isAcceptedUploadSource(string $tmpName): bool {
    if ($tmpName === '') {
      return false;
    }

    if (is_uploaded_file($tmpName)) {
      return true;
    }

    return defined('CDDFTS_TEST_MODE') && CDDFTS_TEST_MODE && is_file($tmpName);
  }

  private static function buildStoredFilename(int $docId, int $version, string $originalName): string {
    $ext = pathinfo($originalName, PATHINFO_EXTENSION);
    return "doc{$docId}_v{$version}_" . time() . ($ext ? ".{$ext}" : "");
  }

  private static function relativePath(int $ownerId, string $basename, string $storageArea = 'PRIVATE'): string {
    $segment = strtolower(Document::normalizeStorageArea($storageArea));
    return "../storage/documents/" . $segment . "/" . $ownerId . "/" . $basename;
  }

  private static function buildBlankEditableContents(string $extension): string {
    $tmpPath = tempnam(sys_get_temp_dir(), 'cddfts_blank_');
    if ($tmpPath === false) {
      throw new RuntimeException('Unable to prepare blank document');
    }

    $zip = new ZipArchive();
    if ($zip->open($tmpPath, ZipArchive::OVERWRITE) !== true) {
      @unlink($tmpPath);
      throw new RuntimeException('Unable to prepare blank document');
    }

    try {
      if ($extension === 'docx') {
        self::writeBlankDocxPayload($zip);
      } elseif ($extension === 'xlsx') {
        self::writeBlankXlsxPayload($zip);
      } else {
        throw new RuntimeException('Unsupported file type');
      }
    } finally {
      $zip->close();
    }

    $contents = file_get_contents($tmpPath);
    @unlink($tmpPath);
    if ($contents === false || $contents === '') {
      throw new RuntimeException('Unable to prepare blank document');
    }

    return $contents;
  }

  private static function writeBlankDocxPayload(ZipArchive $zip): void {
    $zip->addFromString('[Content_Types].xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
</Types>
XML);
    $zip->addFromString('_rels/.rels', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>
</Relationships>
XML);
    $zip->addFromString('word/document.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
  <w:body>
    <w:p>
      <w:r>
        <w:t></w:t>
      </w:r>
    </w:p>
  </w:body>
</w:document>
XML);
  }

  private static function writeBlankXlsxPayload(ZipArchive $zip): void {
    $zip->addFromString('[Content_Types].xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
</Types>
XML);
    $zip->addFromString('_rels/.rels', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>
XML);
    $zip->addFromString('xl/workbook.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets>
    <sheet name="Sheet1" sheetId="1" r:id="rId1"/>
  </sheets>
</workbook>
XML);
    $zip->addFromString('xl/_rels/workbook.xml.rels', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
</Relationships>
XML);
    $zip->addFromString('xl/worksheets/sheet1.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <sheetData></sheetData>
</worksheet>
XML);
  }

  private static function base64UrlEncode(string $value): string {
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
  }

  private static function base64UrlDecode(string $value): ?string {
    $normalized = strtr($value, '-_', '+/');
    $padding = strlen($normalized) % 4;
    if ($padding > 0) {
      $normalized .= str_repeat('=', 4 - $padding);
    }

    $decoded = base64_decode($normalized, true);
    return $decoded === false ? null : $decoded;
  }
}
