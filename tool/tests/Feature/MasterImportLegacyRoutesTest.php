<?php

namespace Tests\Feature;

use App\Models\Adm\AdmAccount;
use App\Services\GoogleSpreadsheetService;
use App\Services\MasterDataExporter;
use App\Services\MasterDeployService;
use App\Services\MasterImportService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 旧経路 6 本（execute・export・all-sheets・spreadsheets・sheets・preview）の回帰テスト（S3-16 で廃止するまでの間）
 *
 * S3-13 でコントローラが GoogleSpreadsheetService を使うときに解決する形へ変えたので、
 * 認証情報が未設定でも壊れないこと・レスポンスの形が変わらないこと・Client を作り直さないことを固定する。
 */
class MasterImportLegacyRoutesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $account = new AdmAccount;
        $account->forceFill(['id' => 7, 'name' => 'operator', 'email' => 'legacy-routes@example.com']);
        $this->actingAs($account);
        Storage::fake();
    }

    /**
     * @return Mockery\MockInterface&GoogleSpreadsheetService
     */
    private function bindSheetMock(int &$buildCount): GoogleSpreadsheetService
    {
        $mock = Mockery::mock(GoogleSpreadsheetService::class);
        $this->app->bind(GoogleSpreadsheetService::class, function () use (&$buildCount, $mock) {
            $buildCount++;

            return $mock;
        });

        return $mock;
    }

    #[Test]
    public function all_sheetsはサービスを1度だけ作って使い回し_形は今までどおり(): void
    {
        $count = 0;
        $mock = $this->bindSheetMock($count);
        $mock->shouldReceive('listSpreadsheets')->once()->andReturn([['id' => 'a', 'name' => 'A'], ['id' => 'b', 'name' => 'B'], ['id' => 'c', 'name' => 'C']]);
        $mock->shouldReceive('listSheets')->times(3)->andReturn([['title' => 'mst_x'], ['title' => 'メモ']]);

        $this->getJson('/master-import/all-sheets')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonCount(6, 'sheets')
            ->assertJsonPath('sheets.0', ['spreadsheet_id' => 'a', 'spreadsheet_name' => 'A', 'sheet_title' => 'mst_x', 'is_mst' => true])
            ->assertJsonPath('sheets.1.is_mst', false)
            ->assertJsonStructure(['cached_at']);

        $this->assertSame(1, $count, 'Client と OAuth トークンをシートごとに作り直さない');
        Storage::assertExists('master-import/sheet-list-cache.json');
    }

    #[Test]
    public function 認証情報が未設定なら_シート系は422のerrorで返る(): void
    {
        Config::set('services.google_spreadsheet.credentials_json', null);
        Config::set('services.google_spreadsheet.credentials_path', null);

        foreach ([
            ['get', '/master-import/all-sheets'],
            ['get', '/master-import/spreadsheets'],
            ['get', '/master-import/sheets?spreadsheet_id=a'],
            ['get', '/master-import/preview?spreadsheet_id=a&sheet_title=mst_x'],
            ['post', '/master-import/execute', ['spreadsheet_id' => 'a', 'sheet_title' => 'mst_x']],
        ] as $case) {
            $response = $this->json($case[0], $case[1], $case[2] ?? []);
            $response->assertStatus(422)->assertJsonPath('status', 'error')->assertJsonStructure(['message']);
        }
    }

    #[Test]
    public function spreadsheets_sheets_previewの形(): void
    {
        $count = 0;
        $mock = $this->bindSheetMock($count);
        $mock->shouldReceive('listSpreadsheets')->once()->andReturn([['id' => 'a', 'name' => 'A', 'modified_at' => 'x']]);
        $mock->shouldReceive('listSheets')->with('a')->once()->andReturn([['title' => 'mst_x']]);
        $mock->shouldReceive('getPreviewData')->with('a', 'mst_x')->once()->andReturn(['mst_x' => ['headers' => ['id'], 'rows' => []]]);

        $this->getJson('/master-import/spreadsheets')->assertOk()->assertExactJson(['status' => 'success', 'spreadsheets' => [['id' => 'a', 'name' => 'A', 'modified_at' => 'x']]]);
        $this->getJson('/master-import/sheets?spreadsheet_id=a')->assertOk()->assertExactJson(['status' => 'success', 'sheets' => [['title' => 'mst_x']]]);
        $this->getJson('/master-import/preview?spreadsheet_id=a&sheet_title=mst_x')->assertOk()->assertJsonPath('status', 'success')->assertJsonPath('preview.mst_x.headers', ['id']);
    }

    #[Test]
    public function sheetsとpreviewは必須項目が無ければ422(): void
    {
        $this->getJson('/master-import/sheets')->assertStatus(422)->assertJsonValidationErrors('spreadsheet_id');
        $this->getJson('/master-import/preview')->assertStatus(422)->assertJsonValidationErrors(['spreadsheet_id', 'sheet_title']);
        $this->postJson('/master-import/execute')->assertStatus(422)->assertJsonValidationErrors(['spreadsheet_id', 'sheet_title']);
    }

    #[Test]
    public function executeの形(): void
    {
        $count = 0;
        $mock = $this->bindSheetMock($count);
        $mock->shouldReceive('getSheetData')->with('a', 'mst_x')->once()->andReturn(['mst_x' => ['headers' => ['id'], 'rows' => [['1']]]]);
        $import = Mockery::mock(MasterImportService::class);
        $import->shouldReceive('import')->once()->with('mst_x', ['id'], [['1']])->andReturn(['table' => 'mst_x', 'inserted' => 1, 'skipped' => 0, 'errors' => []]);
        $this->app->instance(MasterImportService::class, $import);

        $this->postJson('/master-import/execute', ['spreadsheet_id' => 'a', 'sheet_title' => 'mst_x'])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('message', '「mst_x」へ合計 1 件インポートしました。')
            ->assertJsonPath('results.0.inserted', 1);
    }

    #[Test]
    public function executeはシートが空なら_warning(): void
    {
        $count = 0;
        $mock = $this->bindSheetMock($count);
        $mock->shouldReceive('getSheetData')->once()->andReturn([]);

        $this->postJson('/master-import/execute', ['spreadsheet_id' => 'a', 'sheet_title' => 'mst_x'])
            ->assertOk()->assertJsonPath('status', 'warning')->assertJsonPath('results', []);
    }

    #[Test]
    public function exportの形は認証情報なしでも動く(): void
    {
        Config::set('services.google_spreadsheet.credentials_json', null);
        Config::set('services.google_spreadsheet.credentials_path', null);
        $export = ['hash' => 'h', 'file_size' => 1, 'table_count' => 2, 'file_count' => 3, 'tables' => []];
        $exporter = Mockery::mock(MasterDataExporter::class);
        $exporter->shouldReceive('export')->once()->andReturn($export);
        $this->app->instance(MasterDataExporter::class, $exporter);
        $deploy = Mockery::mock(MasterDeployService::class);
        $deploy->shouldReceive('register')->once()->with($export)->andReturn(['deploy_key' => 2026112201, 'sys_deploy_master_id' => 1, 'sys_deploy_id' => 2, 'is_new' => true]);
        $this->app->instance(MasterDeployService::class, $deploy);

        $this->postJson('/master-import/export')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('export.hash', 'h')
            ->assertJsonPath('deploy', ['deploy_key' => 2026112201, 'sys_deploy_master_id' => 1, 'sys_deploy_id' => 2, 'is_new' => true]);
    }
}
