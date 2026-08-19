<?php
try {
    $pdo = new PDO("sqlite:" . __DIR__ . "/database.sqlite");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("PRAGMA foreign_keys = ON;");

    // Create junction table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS oficinas_secretarias (
            oficina_id INTEGER,
            secretaria_id INTEGER,
            PRIMARY KEY (oficina_id, secretaria_id),
            FOREIGN KEY (oficina_id) REFERENCES oficinas_departamentos(id) ON DELETE CASCADE,
            FOREIGN KEY (secretaria_id) REFERENCES secretarias(id) ON DELETE CASCADE
        );
    ");

    // Migrate existing data
    // Check if we need to migrate (if table is empty and oficinas_departamentos has data)
    $stmt = $pdo->query("SELECT COUNT(*) FROM oficinas_secretarias");
    if ($stmt->fetchColumn() == 0) {
        // Check if old column exists (it does based on setup_sqlite.php)
        // We select from oficinas_departamentos and insert into new table
        $pdo->exec("
            INSERT INTO oficinas_secretarias (oficina_id, secretaria_id)
            SELECT id, secretaria_id FROM oficinas_departamentos WHERE secretaria_id IS NOT NULL
        ");
        echo "Datos migrados a la nueva estructura de oficinas-secretarias.\n";
    }

    echo "Esquema actualizado correctamente.";

} catch (PDOException $e) {
    die("Error: " . $e->getMessage());
}
