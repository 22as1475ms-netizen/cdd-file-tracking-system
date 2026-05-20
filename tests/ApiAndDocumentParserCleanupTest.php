<?php
require_once __DIR__ . '/TestCase.php';
require_once __DIR__ . '/../app/controllers/ApiController.php';
require_once __DIR__ . '/../app/controllers/DocumentController.php';

class ApiAndDocumentParserCleanupTest extends TestCase {
  public function testWordEditorTitleCleanupRemovesRepeatedDocxFragments(): void {
    $this->assertSame('Quarterly Report', api_editor_title_clean('Quarterly Report.docx', 'docx'));
    $this->assertSame('saa', api_editor_title_clean('s.docxa.docxa.docx', 'docx'));
    $this->assertSame('saa.docx', api_editor_file_name('s.docxa.docxa.docx', 'document.docx', 'docx'));
    $this->assertSame('Budget 2026', api_editor_title_clean('Budget 2026.xlsx', 'xlsx'));
    $this->assertSame('Budget 2026.xlsx', api_editor_file_name('Budget 2026.xlsx', 'tracker.xlsx', 'xlsx'));
  }

  public function testApiDispatchReturns404ForUnknownRouteInSubprocess(): void {
    $scriptPath = tempnam(sys_get_temp_dir(), 'cddfts_api_');
    if ($scriptPath === false) {
      throw new RuntimeException('Failed to create temp script.');
    }

    $scriptFile = $scriptPath . '.php';
    @unlink($scriptPath);

    $projectRoot = str_replace('\\', '\\\\', dirname(__DIR__));
    $script = <<<PHP
<?php
require_once '{$projectRoot}\\tests\\bootstrap.php';
require_once '{$projectRoot}\\app\\controllers\\ApiController.php';
\$_SESSION['user'] = [
  'id' => 1,
  'role' => 'ADMIN',
  'status' => 'ACTIVE',
];
api_dispatch('GET', '/api/does-not-exist');
PHP;

    file_put_contents($scriptFile, $script);
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($scriptFile);
    $output = shell_exec($command . ' 2>&1');
    @unlink($scriptFile);

    $this->assertTrue(is_string($output), 'Expected subprocess output.');
    $this->assertStringContains('API route not found', (string)$output);
    $this->assertStringContains('"message":"API route not found"', (string)$output);
  }

  public function testDocumentDocxRelationshipsSkipsNonElementNodes(): void {
    $zipPath = tempnam(sys_get_temp_dir(), 'cddfts_docx_');
    if ($zipPath === false) {
      throw new RuntimeException('Failed to create temp archive.');
    }

    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::OVERWRITE) !== true) {
      @unlink($zipPath);
      throw new RuntimeException('Failed to open temp archive.');
    }

    $zip->addFromString('word/_rels/document.xml.rels', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/image1.png"/>
</Relationships>
XML);
    $zip->close();

    $zip = new ZipArchive();
    $this->assertTrue($zip->open($zipPath) === true, 'Expected temp archive to reopen.');

    $relationships = document_docx_relationships($zip, 'word/_rels/document.xml.rels');
    $zip->close();
    @unlink($zipPath);

    $this->assertCount(1, $relationships);
    $this->assertSame('word/media/image1.png', (string)$relationships['rId1']['target']);
    $this->assertSame('http://schemas.openxmlformats.org/officeDocument/2006/relationships/image', (string)$relationships['rId1']['type']);
  }
}
