<?php

require 'vendor/autoload.php';

$capsule = new \Illuminate\Database\Capsule\Manager;
$capsule->addConnection([
    'driver' => 'mysql',
    'host' => 'db',
    'database' => 'db',
    'username' => 'db',
    'password' => 'db',
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix' => '',
]);
$capsule->setAsGlobal();
$capsule->bootEloquent();

use App\Models\User;
use App\Models\Role;

try {
    // Basic Admin Role
    $role = Role::firstOrCreate(['name' => 'Admin'], [
        'hierarchy_level' => 100,
        'can_manage_users' => 1,
        'can_edit_users' => 1,
        'can_manage_project_members' => 1
    ]);

    // Admin User
    $user = User::firstOrCreate(['email' => 'georg@chorkuma.at'], [
        'first_name' => 'Georg',
        'last_name' => 'Test',
        'password' => password_hash('test', PASSWORD_DEFAULT),
        'is_active' => 1
    ]);

    // Attach Role if not attached
    if (!$user->roles->contains($role->id)) {
        $user->roles()->attach($role->id);
    }

    echo "Admin user created successfully.\n";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
