<?php

namespace Tests\Feature;

use App\Models\Adm\AdmAccount;
use App\Services\GoogleSpreadsheetService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * シート系 4 本（all-sheets・spreadsheets・sheets・preview）の回帰テスト（execute・export は S3-16 で廃止した）
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
    }
}
