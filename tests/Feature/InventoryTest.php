<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\InventoryItem;
use App\Models\School;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** الأصول والمخزون: الرصيد يتغيّر بالحركات فقط ولا يهبط تحت الصفر. */
class InventoryTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = School::factory()->create();
        $this->admin = User::factory()->role(UserRole::Admin)->create(['school_id' => $this->school->id]);
        Sanctum::actingAs($this->admin);
    }

    private function item(array $extra = []): array
    {
        return $this->postJson('/api/inventory/items', [
            'name' => 'أقلام سبورة', 'unit' => 'علبة', 'min_quantity' => 5, 'quantity' => 20, ...$extra,
        ])->assertCreated()->json('data');
    }

    public function test_opening_balance_is_a_movement_and_stock_moves_with_history(): void
    {
        $i = $this->item();
        $this->assertSame(20, $i['quantity']);

        $this->postJson("/api/inventory/items/{$i['id']}/move", ['type' => 'out', 'quantity' => 6, 'reason' => 'صف الأول'])
            ->assertOk()->assertJsonPath('data.quantity', 14);
        $this->postJson("/api/inventory/items/{$i['id']}/move", ['type' => 'in', 'quantity' => 10])
            ->assertOk()->assertJsonPath('data.quantity', 24);

        $this->getJson("/api/inventory/items/{$i['id']}/movements")->assertOk()
            ->assertJsonCount(3, 'data')->assertJsonPath('data.0.balance_after', 24);
    }

    public function test_cannot_issue_more_than_the_balance_and_nothing_is_recorded(): void
    {
        $i = $this->item();
        $this->postJson("/api/inventory/items/{$i['id']}/move", ['type' => 'out', 'quantity' => 21])->assertUnprocessable();

        $this->assertSame(20, InventoryItem::findOrFail($i['id'])->quantity);
        $this->assertSame(1, StockMovement::count());
    }

    public function test_adjust_sets_the_balance_and_low_stock_is_flagged(): void
    {
        $i = $this->item();
        $this->postJson("/api/inventory/items/{$i['id']}/move", ['type' => 'adjust', 'quantity' => 4])
            ->assertOk()->assertJsonPath('data.quantity', 4)->assertJsonPath('data.low', true);

        $this->assertSame(-16, StockMovement::where('type', 'adjust')->firstOrFail()->delta);
        $this->getJson('/api/inventory/summary')->assertOk()->assertJsonPath('data.low_stock', 1);
    }

    public function test_assets_keep_money_in_minor_units_and_validate_the_holder(): void
    {
        $res = $this->postJson('/api/inventory/assets', [
            'name' => 'سبورة ذكية', 'cost' => '1250000.50', 'location' => 'الصف الأول',
            'assigned_to' => $this->admin->id,
        ])->assertCreated()->assertJsonPath('data.cost', '1250000.50')->assertJsonPath('data.holder_name', $this->admin->name);

        $this->putJson('/api/inventory/assets/'.$res->json('data.id'), ['name' => 'سبورة ذكية', 'status' => 'maintenance'])
            ->assertOk()->assertJsonPath('data.status', 'maintenance');

        $stranger = User::factory()->role(UserRole::Teacher)->create(['school_id' => School::factory()->create()->id]);
        $this->postJson('/api/inventory/assets', ['name' => 'x', 'assigned_to' => $stranger->id])->assertUnprocessable();

        $this->getJson('/api/inventory/summary')->assertOk()
            ->assertJsonPath('data.assets_maintenance', 1)->assertJsonPath('data.assets_value', '1250000.50');
    }

    public function test_admins_only_school_isolation_and_audit(): void
    {
        $i = $this->item();
        $this->assertTrue(AuditLog::where('auditable_type', 'InventoryItem')->exists());

        Sanctum::actingAs(User::factory()->role(UserRole::Admin)->create(['school_id' => School::factory()->create()->id]));
        $this->getJson('/api/inventory/items')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson("/api/inventory/items/{$i['id']}/move", ['type' => 'in', 'quantity' => 1])->assertNotFound();

        Sanctum::actingAs(User::factory()->role(UserRole::Teacher)->create(['school_id' => $this->school->id]));
        $this->getJson('/api/inventory/items')->assertForbidden();
        $this->postJson('/api/inventory/assets', ['name' => 'x'])->assertForbidden();
    }
}
