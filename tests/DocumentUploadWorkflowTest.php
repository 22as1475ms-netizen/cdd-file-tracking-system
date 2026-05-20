<?php
require_once __DIR__ . '/TestCase.php';
require_once __DIR__ . '/../app/services/DocumentService.php';

class DocumentUploadWorkflowTest extends TestCase {
  public function testUploadCreatesDocumentVersionAndStoredFile(): void {
    $owner = $this->actingAs(3);
    $tmpFile = $this->makeTempOfficeUpload('memo.docx');

    $docId = DocumentService::upload(
      $this->pdo,
      $tmpFile,
      (int)$owner['id'],
      null,
      (int)$owner['id'],
      'OFFICIAL',
      (int)$owner['division_id'],
      [
        'title' => 'Upload Test',
        'current_location' => 'Owner Desk',
        'routing_status' => 'AVAILABLE',
        'status' => 'Draft',
      ]
    );

    $doc = Document::get($this->pdo, $docId);
    $latestVersion = Version::latest($this->pdo, $docId);
    $auditLogs = AuditLog::recentForUser($this->pdo, (int)$owner['id'], 5);

    $this->assertSame('memo.docx', (string)$doc['name']);
    $this->assertNotNull($latestVersion);
    $this->assertTrue(is_file(DocumentService::absolutePathFromVersion((string)$latestVersion['file_path'])), 'Expected uploaded file to exist in storage.');
    $this->assertSame('Uploaded document', (string)$auditLogs[0]['action']);
  }

  public function testUploadAcceptsPdfIntoRoutingQueue(): void {
    $owner = $this->actingAs(3);
    $tmpFile = $this->makeTempPdfUpload('routing-memo.pdf');

    $docId = DocumentService::upload(
      $this->pdo,
      $tmpFile,
      (int)$owner['id'],
      null,
      (int)$owner['id'],
      'OFFICIAL',
      (int)$owner['division_id'],
      [
        'document_code' => 'PDF-001',
        'title' => 'Routing Memo PDF',
        'signatory' => 'Section Head',
        'current_location' => 'Owner Desk',
        'routing_status' => 'AVAILABLE',
        'priority_level' => 'RUSH',
        'document_date' => '2026-05-07',
        'category' => 'Memorandum',
        'status' => 'Draft',
      ]
    );

    $doc = Document::get($this->pdo, $docId);
    $latestVersion = Version::latest($this->pdo, $docId);

    $this->assertSame('routing-memo.pdf', (string)$doc['name']);
    $this->assertSame('PDF-001', (string)$doc['document_code']);
    $this->assertSame('RUSH', (string)$doc['priority_level']);
    $this->assertNotNull($latestVersion);
    $this->assertTrue(is_file(DocumentService::absolutePathFromVersion((string)$latestVersion['file_path'])), 'Expected uploaded PDF to exist in storage.');
  }

  public function testPriorityNormalizationKeepsModerateAndMapsLegacyValues(): void {
    $this->assertSame('LOW', Document::normalizePriorityLevel('low'));
    $this->assertSame('MODERATE', Document::normalizePriorityLevel('moderate'));
    $this->assertSame('MODERATE', Document::normalizePriorityLevel('normal'));
    $this->assertSame('HIGH', Document::normalizePriorityLevel('HIGH'));
    $this->assertSame('RUSH', Document::normalizePriorityLevel('rush'));
    $this->assertSame('RUSH', Document::normalizePriorityLevel('urgent'));
  }

  public function testUploadNewVersionArchivesPreviousVersionAndIncrementsVersionNumber(): void {
    $owner = $this->actingAs(3);
    $firstUpload = $this->makeTempOfficeUpload('tracker.xlsx');
    $docId = DocumentService::upload(
      $this->pdo,
      $firstUpload,
      (int)$owner['id'],
      null,
      (int)$owner['id'],
      'OFFICIAL',
      (int)$owner['division_id'],
      [
        'title' => 'Upload Test',
        'current_location' => 'Owner Desk',
        'routing_status' => 'AVAILABLE',
        'status' => 'Draft',
      ]
    );

    $secondUpload = $this->makeTempOfficeUpload('tracker.xlsx');
    $versionNumber = DocumentService::uploadNewVersion($this->pdo, $docId, $secondUpload, (int)$owner['id']);

    $versions = Version::list($this->pdo, $docId);
    $latestVersion = Version::latest($this->pdo, $docId);
    $archivedPath = DocumentService::absolutePathFromVersion((string)$versions[1]['file_path']);

    $this->assertSame(2, $versionNumber);
    $this->assertCount(2, $versions);
    $this->assertSame(2, (int)$latestVersion['version_number']);
    $this->assertTrue(str_contains((string)$versions[1]['file_path'], 'previous_versions'), 'Expected previous version to be archived.');
    $this->assertTrue(is_file($archivedPath), 'Expected archived version file to exist.');
  }

  public function testUploadRejectsUnsupportedFileType(): void {
    $owner = $this->actingAs(3);
    $tmpFile = $this->makeTempUpload('script.php', "<?php echo 'bad';");

    $this->expectExceptionMessage('Unsupported file type', function () use ($tmpFile, $owner): void {
      DocumentService::upload(
        $this->pdo,
        $tmpFile,
        (int)$owner['id'],
        null,
        (int)$owner['id'],
        'OFFICIAL',
        (int)$owner['division_id'],
        [
          'title' => 'Upload Test',
          'current_location' => 'Owner Desk',
          'routing_status' => 'AVAILABLE',
          'status' => 'Draft',
        ]
      );
    });
  }

  public function testCreateBlankWordPlaceholderCreatesStoredDocx(): void {
    $owner = $this->actingAs(3);

    $docId = DocumentService::createBlankEditableDocument(
      $this->pdo,
      (int)$owner['id'],
      'docx',
      (int)$owner['id'],
      'OFFICIAL',
      (int)$owner['division_id'],
      [
        'document_code' => 'DOC-001',
        'title' => 'Routing Memo',
        'signatory' => 'Section Head',
        'current_location' => 'Owner Desk',
        'routing_status' => 'AVAILABLE',
        'document_date' => '2026-04-21',
        'category' => 'Memorandum',
      ]
    );

    $doc = Document::get($this->pdo, $docId);
    $latestVersion = Version::latest($this->pdo, $docId);

    $this->assertSame('Routing Memo.docx', (string)$doc['name']);
    $this->assertSame('DOC-001', (string)$doc['document_code']);
    $this->assertNotNull($latestVersion);
    $this->assertTrue(is_file(DocumentService::absolutePathFromVersion((string)$latestVersion['file_path'])), 'Expected blank Word placeholder to exist in storage.');
  }

  public function testCreateBlankSpreadsheetPlaceholderCreatesStoredXlsx(): void {
    $owner = $this->actingAs(3);

    $docId = DocumentService::createBlankEditableDocument(
      $this->pdo,
      (int)$owner['id'],
      'xlsx',
      (int)$owner['id'],
      'OFFICIAL',
      (int)$owner['division_id'],
      [
        'document_code' => 'XLS-001',
        'title' => 'Routing Tracker',
        'signatory' => 'Section Head',
        'current_location' => 'Owner Desk',
        'routing_status' => 'AVAILABLE',
        'document_date' => '2026-04-21',
        'category' => 'Tracker',
      ]
    );

    $doc = Document::get($this->pdo, $docId);
    $latestVersion = Version::latest($this->pdo, $docId);

    $this->assertSame('Routing Tracker.xlsx', (string)$doc['name']);
    $this->assertSame('XLS-001', (string)$doc['document_code']);
    $this->assertNotNull($latestVersion);
    $this->assertTrue(is_file(DocumentService::absolutePathFromVersion((string)$latestVersion['file_path'])), 'Expected blank spreadsheet placeholder to exist in storage.');
  }

  public function testWordEditorPageBreakAndPresetLayoutRoundTripThroughDocx(): void {
    $html = '<section data-word-page-root="true" data-page-size="LEGAL" data-page-margin="NARROW" data-page-orientation="landscape">'
      . '<p>First page</p><hr class="docx-page-break" data-word-page-break="true"><p>Second page</p></section>';

    $contents = DocumentService::createWordDocumentFromHtml('Layout Test', $html);
    $documentXml = $this->readDocxDocumentXml($contents);
    $extractedHtml = DocumentService::extractDocxHtml($contents);

    $this->assertStringContains('<w:br w:type="page"/>', $documentXml);
    $this->assertStringContains('<w:pgSz w:w="20160" w:h="12240" w:orient="landscape"/>', $documentXml);
    $this->assertStringContains('<w:pgMar w:top="720" w:right="720" w:bottom="720" w:left="720"', $documentXml);
    $this->assertStringContains('data-page-size="LEGAL"', $extractedHtml);
    $this->assertStringContains('data-page-margin="NARROW"', $extractedHtml);
    $this->assertStringContains('data-page-orientation="landscape"', $extractedHtml);
    $this->assertStringContains('class="docx-page-break"', $extractedHtml);
  }

  public function testWordEditorCustomLayoutRoundTripThroughDocx(): void {
    $html = '<section data-word-page-root="true" data-page-size="CUSTOM" data-page-margin="CUSTOM" data-page-orientation="portrait"'
      . ' data-page-custom-width-mm="127" data-page-custom-height-mm="254"'
      . ' data-page-custom-margin-top-mm="12.7" data-page-custom-margin-right-mm="19.05"'
      . ' data-page-custom-margin-bottom-mm="12.7" data-page-custom-margin-left-mm="19.05">'
      . '<p>Custom page</p></section>';

    $contents = DocumentService::createWordDocumentFromHtml('Custom Layout Test', $html);
    $documentXml = $this->readDocxDocumentXml($contents);
    $extractedHtml = DocumentService::extractDocxHtml($contents);

    $this->assertStringContains('<w:pgSz w:w="7200" w:h="14400"/>', $documentXml);
    $this->assertStringContains('<w:pgMar w:top="720" w:right="1080" w:bottom="720" w:left="1080"', $documentXml);
    $this->assertStringContains('data-page-size="CUSTOM"', $extractedHtml);
    $this->assertStringContains('data-page-margin="CUSTOM"', $extractedHtml);
    $this->assertStringContains('data-page-custom-width-mm="127"', $extractedHtml);
    $this->assertStringContains('data-page-custom-height-mm="254"', $extractedHtml);
    $this->assertStringContains('data-page-custom-margin-right-mm="19.05"', $extractedHtml);
  }

  private function readDocxDocumentXml(string $contents): string {
    $tmpPath = tempnam(sys_get_temp_dir(), 'cddfts_docx_assert_');
    if ($tmpPath === false) {
      throw new RuntimeException('Failed to create temp DOCX assertion file.');
    }

    file_put_contents($tmpPath, $contents);
    $zip = new ZipArchive();
    if ($zip->open($tmpPath) !== true) {
      @unlink($tmpPath);
      throw new RuntimeException('Failed to open DOCX assertion file.');
    }

    $documentXml = $zip->getFromName('word/document.xml');
    $zip->close();
    @unlink($tmpPath);

    if ($documentXml === false) {
      throw new RuntimeException('DOCX assertion file did not contain word/document.xml.');
    }

    return (string)$documentXml;
  }

  private function makeTempUpload(string $name, string $contents): array {
    $tmpPath = tempnam(sys_get_temp_dir(), 'cddfts_upload_');
    if ($tmpPath === false) {
      throw new RuntimeException('Failed to create temp file.');
    }

    file_put_contents($tmpPath, $contents);

    return [
      'name' => $name,
      'type' => 'application/octet-stream',
      'tmp_name' => $tmpPath,
      'error' => UPLOAD_ERR_OK,
      'size' => filesize($tmpPath) ?: strlen($contents),
    ];
  }

  private function makeTempPdfUpload(string $name): array {
    return $this->makeTempUpload(
      $name,
      "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n"
    );
  }

  private function makeTempOfficeUpload(string $name): array {
    $extension = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
    $tmpPath = tempnam(sys_get_temp_dir(), 'cddfts_office_');
    if ($tmpPath === false) {
      throw new RuntimeException('Failed to create Office temp file.');
    }

    $zip = new ZipArchive();
    if ($zip->open($tmpPath, ZipArchive::OVERWRITE) !== true) {
      @unlink($tmpPath);
      throw new RuntimeException('Failed to open Office temp file.');
    }

    if ($extension === 'docx') {
      $this->writeDocxPayload($zip);
      $mime = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    } elseif ($extension === 'xlsx') {
      $this->writeXlsxPayload($zip);
      $mime = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    } else {
      $zip->close();
      @unlink($tmpPath);
      throw new RuntimeException('Unsupported Office fixture type.');
    }

    $zip->close();

    return [
      'name' => $name,
      'type' => $mime,
      'tmp_name' => $tmpPath,
      'error' => UPLOAD_ERR_OK,
      'size' => filesize($tmpPath) ?: 0,
    ];
  }

  private function writeDocxPayload(ZipArchive $zip): void {
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
        <w:t>CDD-File-Tracking-System upload test document</w:t>
      </w:r>
    </w:p>
  </w:body>
</w:document>
XML);
  }

  private function writeXlsxPayload(ZipArchive $zip): void {
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
  <sheetData>
    <row r="1">
      <c r="A1" t="inlineStr">
        <is><t>CDD-File-Tracking-System</t></is>
      </c>
    </row>
  </sheetData>
</worksheet>
XML);
  }
}
