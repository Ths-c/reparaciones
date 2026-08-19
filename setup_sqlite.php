<?php
try {
    $pdo = new PDO("sqlite:" . __DIR__ . "/database.sqlite");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("PRAGMA foreign_keys = ON;");

    // Create tables
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS secretarias (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nombre TEXT NOT NULL,
            descripcion TEXT,
            activa INTEGER DEFAULT 1
        );

        CREATE TABLE IF NOT EXISTS oficinas_departamentos (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nombre TEXT NOT NULL,
            activa INTEGER DEFAULT 1
        );

        CREATE TABLE IF NOT EXISTS oficinas_secretarias (
            oficina_id INTEGER,
            secretaria_id INTEGER,
            PRIMARY KEY (oficina_id, secretaria_id),
            FOREIGN KEY (oficina_id) REFERENCES oficinas_departamentos(id) ON DELETE CASCADE,
            FOREIGN KEY (secretaria_id) REFERENCES secretarias(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS reparaciones (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tipo_equipo TEXT,
            marca TEXT,
            modelo TEXT,
            motivo TEXT,
            secretaria_origen_id INTEGER,
            oficina_origen_id INTEGER,
            fecha_envio TEXT,
            tecnico TEXT,
            costo_estimado REAL,
            persona_presupuesto TEXT,
            fecha_presupuesto TEXT,
            numero_orden TEXT,
            fecha_orden TEXT,
            estado TEXT,
            observaciones TEXT,
            FOREIGN KEY (secretaria_origen_id) REFERENCES secretarias(id) ON DELETE SET NULL,
            FOREIGN KEY (oficina_origen_id) REFERENCES oficinas_departamentos(id) ON DELETE SET NULL
        );
    ");

    // Insert sample data if empty
    $stmt = $pdo->query("SELECT COUNT(*) FROM secretarias");
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO secretarias (nombre, descripcion) VALUES ('Secretaría General', 'Administración central')");
        $sec1 = $pdo->lastInsertId();
        $pdo->exec("INSERT INTO secretarias (nombre, descripcion) VALUES ('Recursos Humanos', 'Gestión de personal')");
        $sec2 = $pdo->lastInsertId();
        
        $pdo->exec("INSERT INTO oficinas_departamentos (nombre) VALUES ('Mesa de Entradas')");
        $of1 = $pdo->lastInsertId();
        $pdo->exec("INSERT INTO oficinas_secretarias (oficina_id, secretaria_id) VALUES ($of1, $sec1)");
        
        $pdo->exec("INSERT INTO oficinas_departamentos (nombre) VALUES ('Liquidación de Sueldos')");
        $of2 = $pdo->lastInsertId();
        $pdo->exec("INSERT INTO oficinas_secretarias (oficina_id, secretaria_id) VALUES ($of2, $sec2)");

        $pdo->exec("INSERT INTO reparaciones (tipo_equipo, marca, modelo, motivo, secretaria_origen_id, oficina_origen_id, fecha_envio, tecnico, estado, observaciones) 
                    VALUES ('PC', 'Dell', 'Optiplex', 'No enciende', $sec1, $of1, '2023-10-01', 'Juan Perez', 'En reparación', 'Posible falla de fuente')");
    }

    echo "Base de datos SQLite creada exitosamente.";

} catch (PDOException $e) {
    die("Error: " . $e->getMessage());
}
