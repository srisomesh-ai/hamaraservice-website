<?php
require_once __DIR__ . '/db.php';
setCorsHeaders();
requireAdmin();

try {
    $db = getDB();

    // Test connection
    $stmt = $db->query("SELECT VERSION() as version");
    $ver  = $stmt->fetch()['version'];

    // Check which tables exist
    $stmt  = $db->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Count services seeded
    $svcCount = 0;
    if (in_array('services', $tables)) {
        $svcCount = $db->query("SELECT COUNT(*) FROM services")->fetchColumn();
    }

    ok([
        'message'       => 'Database connected successfully',
        'mysql_version' => $ver,
        'database'      => DB_NAME,
        'tables_found'  => $tables,
        'services_seeded' => (int)$svcCount,
    ]);
} catch (Exception $e) {
    error_log('test-db: ' . $e->getMessage());
    err('Connection failed', 500);
}
