<?php

use App\Models\Expense;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * The spending heads were a hard-coded list in the model, which meant adding one was a code
     * change. They become a table an admin edits on the page, and every expense already booked
     * links to its head so a rename or a retirement shows up in the history. The name the payment
     * went out under stays on the expense row, because that text is already written in the ledger.
     */
    public function up(): void
    {
        if (! Schema::hasTable('expense_categories')) {
            Schema::create('expense_categories', function (Blueprint $table) {
                $table->id();
                $table->string('name', 60)->unique();
                $table->boolean('is_active')->default(true);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        foreach (Expense::CATEGORIES as $name) {
            DB::table('expense_categories')->updateOrInsert(
                ['name' => $name],
                ['is_active' => true, 'updated_at' => now(), 'created_at' => now()]
            );
        }

        if (! Schema::hasColumn('expenses', 'category_id')) {
            Schema::table('expenses', function (Blueprint $table) {
                $table->foreignId('category_id')->nullable()->after('expense_date')
                    ->constrained('expense_categories')->nullOnDelete();
            });
        }

        // File the spending already booked under its matching head. One statement rather than a
        // loop so the backfill does not depend on how many rows the live book has grown to.
        DB::statement('UPDATE expenses SET category_id = (
            SELECT id FROM expense_categories WHERE expense_categories.name = expenses.category
        ) WHERE category_id IS NULL');

        // The permissions seeder deliberately never re-grants to roles that already exist, so a
        // new ability has to be handed to the roles that own it here or nobody on the live site
        // could reach the page it protects.
        $permission = Permission::firstOrCreate(['name' => 'manage-expense-categories', 'guard_name' => 'web']);

        foreach (['superadmin', 'admin'] as $name) {
            $role = Role::where('guard_name', 'web')->where('name', $name)->first();

            if ($role && ! $role->hasPermissionTo($permission, false)) {
                $role->givePermissionTo($permission);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // The list and the links stay: rolling back must not orphan spending already recorded.
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropForeign(['category_id']);
            $table->dropColumn('category_id');
        });

        Schema::dropIfExists('expense_categories');
    }
};
