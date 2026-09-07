<?php
$dbDriver = getenv('DB_DRIVER') ?: 'mysql';
$dbUser = null;
$dbPass = null;
if ($dbDriver === 'pgsql') {
    $dbUrl = getenv('DB_URL') ?: getenv('DATABASE_URL_UNPOOLED') ?: getenv('POSTGRES_URL_NON_POOLING') ?: getenv('DATABASE_URL');
    if ($dbUrl) {
        $u = parse_url($dbUrl);
        $dsn = 'pgsql:host=' . $u['host']
            . ';port=' . (isset($u['port']) ? $u['port'] : 5432)
            . ';dbname=' . ltrim($u['path'], '/')
            . ';user=' . rawurldecode($u['user'])
            . ';password=' . rawurldecode(isset($u['pass']) ? $u['pass'] : '');
        if (strpos($u['host'], 'neon.tech') !== false) {
            $dsn .= ';options=endpoint=' . explode('.', $u['host'])[0];
        }
        $dsn .= ';sslmode=require';
    } else {
        $pghost = getenv('DB_HOST') ?: getenv('PGHOST_UNPOOLED') ?: getenv('PGHOST') ?: 'localhost';
        $dsn = 'pgsql:host=' . $pghost
            . ';port=' . (getenv('DB_PORT') ?: getenv('PGPORT') ?: '5432')
            . ';dbname=' . (getenv('DB_NAME') ?: getenv('PGDATABASE') ?: 'postgres');
        if (strpos($pghost, 'neon.tech') !== false) {
            $dsn .= ';options=endpoint=' . explode('.', $pghost)[0];
        }
        if (getenv('PGSSL') === '1' || getenv('DB_SSL') === '1') {
            $dsn .= ';sslmode=require';
        }
        $dbUser = getenv('DB_USER') ?: getenv('PGUSER') ?: 'postgres';
        $dbPass = getenv('DB_PASSWORD') ?: getenv('PGPASSWORD') ?: '';
    }
} else {
    $dsn = 'mysql:host=' . (getenv('DB_HOST') ?: 'localhost') . ';dbname=' . (getenv('DB_NAME') ?: 'reparaciones') . ';charset=utf8mb4';
    $dbUser = getenv('DB_USER') ?: 'root';
    $dbPass = getenv('DB_PASSWORD') ?: '';
}
try {
    $pdo = new PDO($dsn, $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Error de conexión: " . $e->getMessage());
}

function insertId(PDO $pdo, PDOStatement $stmt) {
    return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql' ? (int)$stmt->fetchColumn() : (int)$pdo->lastInsertId();
}

// ======================== MANEJO DE ACCIONES AJAX ========================
if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    
    switch ($_POST['ajax_action']) {
        case 'get_oficinas_por_secretaria':
            $secretaria_id = (int)$_POST['secretaria_id'];
            try {
                $sql = "SELECT o.* FROM oficinas_departamentos o 
                        JOIN oficinas_secretarias os ON o.id = os.oficina_id 
                        WHERE os.secretaria_id = :secretaria_id AND o.activa = 1 
                        ORDER BY o.nombre";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':secretaria_id' => $secretaria_id]);
                $oficinas = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['success' => true, 'oficinas' => $oficinas]);
            } catch (PDOException $e) {
                echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
            }
            exit;

        case 'get_movimientos':
            $reparacion_id = (int)$_POST['id'];
            try {
                $sql = "SELECT * FROM movimientos WHERE reparacion_id = :reparacion_id AND tipo_movimiento = 'estado' ORDER BY fecha_movimiento DESC";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':reparacion_id' => $reparacion_id]);
                $movimientos = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['success' => true, 'movimientos' => $movimientos]);
            } catch (PDOException $e) {
                echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
            }
            exit;

        case 'crear_secretaria':
            $nombre = trim($_POST['nombre']);
            $descripcion = trim($_POST['descripcion']);
            if (empty($nombre)) {
                echo json_encode(['success' => false, 'message' => 'El nombre es obligatorio']);
                exit;
            }
            try {
                $sql = "INSERT INTO secretarias (nombre, descripcion) VALUES (:nombre, :descripcion)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':nombre' => $nombre, ':descripcion' => $descripcion]);
                echo json_encode(['success' => true, 'message' => 'Secretaría creada exitosamente']);
            } catch (PDOException $e) {
                echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
            }
            exit;

        case 'editar_secretaria':
            $id = (int)$_POST['id'];
            $nombre = trim($_POST['nombre']);
            $descripcion = trim($_POST['descripcion']);
            if (empty($nombre)) {
                echo json_encode(['success' => false, 'message' => 'El nombre es obligatorio']);
                exit;
            }
            try {
                $sql = "UPDATE secretarias SET nombre = :nombre, descripcion = :descripcion WHERE id = :id";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':nombre' => $nombre, ':descripcion' => $descripcion, ':id' => $id]);
                echo json_encode(['success' => true, 'message' => 'Secretaría actualizada exitosamente']);
            } catch (PDOException $e) {
                echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
            }
            exit;

        case 'eliminar_secretaria':
            $id = (int)$_POST['id'];
            try {
                $check_sql = "SELECT COUNT(*) FROM reparaciones WHERE secretaria_origen_id = :id";
                $check_stmt = $pdo->prepare($check_sql);
                $check_stmt->execute([':id' => $id]);
                $count = $check_stmt->fetchColumn();

                if ($count > 0) {
                    echo json_encode(['success' => false, 'message' => 'No se puede eliminar porque tiene reparaciones asociadas']);
                } else {
                    $sql = "DELETE FROM secretarias WHERE id = :id";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([':id' => $id]);
                    echo json_encode(['success' => true, 'message' => 'Secretaría eliminada exitosamente']);
                }
            } catch (PDOException $e) {
                echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
            }
            exit;

        case 'crear_oficina':
            $nombre = trim($_POST['nombre']);
            $secretaria_ids = isset($_POST['secretaria_ids']) ? json_decode($_POST['secretaria_ids'], true) : [];
            
            if (empty($nombre) || empty($secretaria_ids)) {
                echo json_encode(['success' => false, 'message' => 'El nombre y al menos una secretaría son obligatorios']);
                exit;
            }
            try {
                $pdo->beginTransaction();
                
                $sql = "INSERT INTO oficinas_departamentos (nombre) VALUES (:nombre)" . ($dbDriver === 'pgsql' ? " RETURNING id" : "");
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':nombre' => $nombre]);
                $oficina_id = insertId($pdo, $stmt);
                
                $sql_relation = "INSERT INTO oficinas_secretarias (oficina_id, secretaria_id) VALUES (:oficina_id, :secretaria_id)";
                $stmt_relation = $pdo->prepare($sql_relation);
                
                foreach ($secretaria_ids as $sec_id) {
                    $stmt_relation->execute([':oficina_id' => $oficina_id, ':secretaria_id' => $sec_id]);
                }
                
                $pdo->commit();
                echo json_encode(['success' => true, 'message' => 'Oficina creada exitosamente']);
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
            }
            exit;

        case 'editar_oficina':
            $id = (int)$_POST['id'];
            $nombre = trim($_POST['nombre']);
            $secretaria_ids = isset($_POST['secretaria_ids']) ? json_decode($_POST['secretaria_ids'], true) : [];
            
            if (empty($nombre) || empty($secretaria_ids)) {
                echo json_encode(['success' => false, 'message' => 'El nombre y al menos una secretaría son obligatorios']);
                exit;
            }
            try {
                $pdo->beginTransaction();
                
                $sql = "UPDATE oficinas_departamentos SET nombre = :nombre WHERE id = :id";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':nombre' => $nombre, ':id' => $id]);
                
                // Eliminar relaciones existentes
                $pdo->prepare("DELETE FROM oficinas_secretarias WHERE oficina_id = ?")->execute([$id]);
                
                // Insertar nuevas relaciones
                $sql_relation = "INSERT INTO oficinas_secretarias (oficina_id, secretaria_id) VALUES (:oficina_id, :secretaria_id)";
                $stmt_relation = $pdo->prepare($sql_relation);
                
                foreach ($secretaria_ids as $sec_id) {
                    $stmt_relation->execute([':oficina_id' => $id, ':secretaria_id' => $sec_id]);
                }
                
                $pdo->commit();
                echo json_encode(['success' => true, 'message' => 'Oficina actualizada exitosamente']);
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
            }
            exit;

        case 'eliminar_oficina':
            $id = (int)$_POST['id'];
            try {
                $check_sql = "SELECT COUNT(*) FROM reparaciones WHERE oficina_origen_id = :id";
                $check_stmt = $pdo->prepare($check_sql);
                $check_stmt->execute([':id' => $id]);
                $count = $check_stmt->fetchColumn();

                if ($count > 0) {
                    echo json_encode(['success' => false, 'message' => 'No se puede eliminar porque tiene reparaciones asociadas']);
                } else {
                    $sql = "DELETE FROM oficinas_departamentos WHERE id = :id";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([':id' => $id]);
                    echo json_encode(['success' => true, 'message' => 'Oficina eliminada exitosamente']);
                }
            } catch (PDOException $e) {
                echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
            }
            exit;
    }
}

// ======================== MANEJO DE ACCIONES ========================
$action = isset($_GET['action']) ? $_GET['action'] : '';
$vista = isset($_GET['vista']) ? $_GET['vista'] : 'reparaciones';
$mensaje_exito = '';
$mensaje_error = '';

// Agregar nuevo registro
if (isset($_POST['action']) && $_POST['action'] === 'add') {
    try {
        $sql = "INSERT INTO reparaciones (tipo_equipo, marca, modelo, motivo, secretaria_origen_id, oficina_origen_id, fecha_envio, tecnico, costo_estimado, persona_presupuesto, fecha_presupuesto, numero_orden, fecha_orden, estado, observaciones) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)" . ($dbDriver === 'pgsql' ? " RETURNING id" : "");
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $_POST['tipo_equipo'], $_POST['marca'], $_POST['modelo'], $_POST['motivo'],
            !empty($_POST['secretaria_origen_id']) ? $_POST['secretaria_origen_id'] : null,
            !empty($_POST['oficina_origen_id']) ? $_POST['oficina_origen_id'] : null,
            !empty($_POST['fecha_envio']) ? $_POST['fecha_envio'] : null,
            $_POST['tecnico'],
            $_POST['costo_estimado'] !== '' ? $_POST['costo_estimado'] : null,
            $_POST['persona_presupuesto'],
            !empty($_POST['fecha_presupuesto']) ? $_POST['fecha_presupuesto'] : null,
            $_POST['numero_orden'],
            !empty($_POST['fecha_orden']) ? $_POST['fecha_orden'] : null,
            $_POST['estado'], $_POST['observaciones']
        ]);
        
        // Registrar movimiento de estado inicial
        $reparacion_id = insertId($pdo, $stmt);
        $sql_mov = "INSERT INTO movimientos (reparacion_id, tipo_movimiento, descripcion, usuario) VALUES (?, ?, ?, ?)";
        $stmt_mov = $pdo->prepare($sql_mov);
        $stmt_mov->execute([$reparacion_id, 'estado', 'Estado inicial: ' . $_POST['estado'], 'Sistema']);
        
        $mensaje_exito = "Reparación registrada exitosamente";
    } catch(PDOException $e) {
        $mensaje_error = "Error al registrar: " . $e->getMessage();
    }
}

// Actualizar registro
if (isset($_POST['action']) && $_POST['action'] === 'update') {
    try {
        $sql = "UPDATE reparaciones SET tipo_equipo=?, marca=?, modelo=?, motivo=?, secretaria_origen_id=?, oficina_origen_id=?, fecha_envio=?, tecnico=?, costo_estimado=?, persona_presupuesto=?, fecha_presupuesto=?, numero_orden=?, fecha_orden=?, estado=?, observaciones=? WHERE id=?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $_POST['tipo_equipo'], $_POST['marca'], $_POST['modelo'], $_POST['motivo'],
            !empty($_POST['secretaria_origen_id']) ? $_POST['secretaria_origen_id'] : null,
            !empty($_POST['oficina_origen_id']) ? $_POST['oficina_origen_id'] : null,
            !empty($_POST['fecha_envio']) ? $_POST['fecha_envio'] : null,
            $_POST['tecnico'],
            $_POST['costo_estimado'] !== '' ? $_POST['costo_estimado'] : null,
            $_POST['persona_presupuesto'],
            !empty($_POST['fecha_presupuesto']) ? $_POST['fecha_presupuesto'] : null,
            $_POST['numero_orden'],
            !empty($_POST['fecha_orden']) ? $_POST['fecha_orden'] : null,
            $_POST['estado'], $_POST['observaciones'], $_POST['id']
        ]);
        
        // Registrar movimiento de actualización de estado
        $sql_mov = "INSERT INTO movimientos (reparacion_id, tipo_movimiento, descripcion, usuario) VALUES (?, ?, ?, ?)";
        $stmt_mov = $pdo->prepare($sql_mov);
        $stmt_mov->execute([$_POST['id'], 'estado', 'Cambio de estado a: ' . $_POST['estado'], 'Sistema']);
        
        $mensaje_exito = "Reparación actualizada exitosamente";
    } catch(PDOException $e) {
        $mensaje_error = "Error al actualizar: " . $e->getMessage();
    }
}

// Eliminar registro
if ($action === 'delete' && isset($_GET['id'])) {
    try {
        $stmt = $pdo->prepare("DELETE FROM reparaciones WHERE id = ?");
        $stmt->execute([$_GET['id']]);
        $mensaje_exito = "Reparación eliminada exitosamente";
    } catch(PDOException $e) {
        $mensaje_error = "Error al eliminar: " . $e->getMessage();
    }
}


// Obtener registro para editar
$edit_record = null;
if ($action === 'edit' && isset($_GET['id'])) {
    $stmt = $pdo->prepare("SELECT * FROM reparaciones WHERE id = ?");
    $stmt->execute([$_GET['id']]);
    $edit_record = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Obtener registros con filtros
$filtros = '';
$params = [];

if (!empty($_GET['filter_estado'])) {
    $filtros .= " AND r.estado = ?";
    $params[] = $_GET['filter_estado'];
}
if (!empty($_GET['filter_tecnico'])) {
    $filtros .= " AND r.tecnico LIKE ?";
    $params[] = '%' . $_GET['filter_tecnico'] . '%';
}
if (!empty($_GET['filter_tipo'])) {
    $filtros .= " AND r.tipo_equipo = ?";
    $params[] = $_GET['filter_tipo'];
}

$sql = "SELECT r.*, s.nombre as secretaria_origen, o.nombre as oficina_origen 
        FROM reparaciones r 
        LEFT JOIN secretarias s ON r.secretaria_origen_id = s.id
        LEFT JOIN oficinas_departamentos o ON r.oficina_origen_id = o.id
        WHERE 1=1 $filtros ORDER BY r.fecha_envio DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$reparaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Obtener estadísticas
$stats = $pdo->query("SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN estado = 'Presupuestado' THEN 1 ELSE 0 END) as presupuestado,
    SUM(CASE WHEN estado = 'Enviado' THEN 1 ELSE 0 END) as enviado,
    SUM(CASE WHEN estado = 'Orden de compra' THEN 1 ELSE 0 END) as en_reparacion,
    SUM(CASE WHEN estado = 'Finalizado' THEN 1 ELSE 0 END) as finalizado
    FROM reparaciones")->fetch(PDO::FETCH_ASSOC);

// Obtener técnicos únicos para filtro
$tecnicos = $pdo->query("SELECT DISTINCT tecnico FROM reparaciones ORDER BY tecnico")->fetchAll(PDO::FETCH_COLUMN);

// Obtener secretarías y oficinas
$secretarias = $pdo->query("SELECT * FROM secretarias WHERE activa = 1 ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
$aggNombre = $dbDriver === 'pgsql' ? "STRING_AGG(s.nombre, ', ')" : "GROUP_CONCAT(s.nombre, ', ')";
$aggIds = $dbDriver === 'pgsql' ? "STRING_AGG(s.id::text, ',')" : "GROUP_CONCAT(s.id)";
$oficinas = $pdo->query("SELECT o.*, $aggNombre as secretaria_nombre, $aggIds as secretaria_ids
                         FROM oficinas_departamentos o
                         LEFT JOIN oficinas_secretarias os ON o.id = os.oficina_id
                         LEFT JOIN secretarias s ON os.secretaria_id = s.id
                         WHERE o.activa = 1
                         GROUP BY o.id
                         ORDER BY o.nombre")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistema de Gestión de Reparaciones</title>
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E%3Crect width='100' height='100' rx='22' fill='%23C05F1D'/%3E%3Ctext x='50' y='70' font-size='55' text-anchor='middle'%3E%F0%9F%9B%A0%3C/text%3E%3C/svg%3E">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@600;700;800;900&family=Nunito+Sans:ital,opsz,wght@0,6..12,400..800;1,6..12,400..800&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/app.css">
</head>
<body>
    <header class="app-header">
        <div class="container-fluid">
            <div class="app-header-inner">
                <a class="brand" href="?">
                    <span class="brand-mark"><i class="fas fa-tools"></i></span>
                    <span class="brand-text">
                        <strong>Gestión de Reparaciones</strong>
                        <small>Taller de soporte técnico</small>
                    </span>
                </a>
                <nav>
                    <ul class="nav nav-tabs" id="mainTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link <?php echo $vista === 'reparaciones' ? 'active' : ''; ?>" onclick="cambiarVista('reparaciones')">
                                <i class="fas fa-tools"></i> Reparaciones
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link <?php echo $vista === 'gestion' ? 'active' : ''; ?>" onclick="cambiarVista('gestion')">
                                <i class="fas fa-cog"></i> Gestión
                            </button>
                        </li>
                    </ul>
                </nav>
                <a class="btn btn-primary ms-auto" href="?action=new">
                    <i class="fas fa-plus"></i> Nueva reparación
                </a>
            </div>
        </div>
    </header>
    <div class="container-fluid py-4">
        <div class="row">
            <div class="col-12">
                <h1 class="text-center mb-4"><i class="fas fa-tools"></i> Sistema de Gestión de Reparaciones</h1>
                
                <?php if ($mensaje_exito): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle"></i> <?php echo $mensaje_exito; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if ($mensaje_error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-circle"></i> <?php echo $mensaje_error; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($vista === 'reparaciones'): ?>
                    <!-- Estadísticas -->
                    <div class="stats-grid">
                        <a class="stat-card" href="?vista=reparaciones">
                            <span class="stat-icon total"><i class="fas fa-clipboard-list"></i></span>
                            <span>
                                <span class="stat-value"><?php echo $stats['total']; ?></span>
                                <span class="stat-label">Total</span>
                            </span>
                        </a>
                        <a class="stat-card" href="?vista=reparaciones&filter_estado=<?php echo urlencode('Enviado'); ?>">
                            <span class="stat-icon enviado"><i class="fas fa-paper-plane"></i></span>
                            <span>
                                <span class="stat-value"><?php echo $stats['enviado']; ?></span>
                                <span class="stat-label">Enviado</span>
                            </span>
                        </a>
                        <a class="stat-card" href="?vista=reparaciones&filter_estado=<?php echo urlencode('Presupuestado'); ?>">
                            <span class="stat-icon presupuestado"><i class="fas fa-file-invoice-dollar"></i></span>
                            <span>
                                <span class="stat-value"><?php echo $stats['presupuestado']; ?></span>
                                <span class="stat-label">Presupuestado</span>
                            </span>
                        </a>
                        <a class="stat-card" href="?vista=reparaciones&filter_estado=<?php echo urlencode('Orden de compra'); ?>">
                            <span class="stat-icon reparacion"><i class="fas fa-wrench"></i></span>
                            <span>
                                <span class="stat-value"><?php echo $stats['en_reparacion']; ?></span>
                                <span class="stat-label">En Reparación</span>
                            </span>
                        </a>
                        <a class="stat-card" href="?vista=reparaciones&filter_estado=<?php echo urlencode('Finalizado'); ?>">
                            <span class="stat-icon finalizado"><i class="fas fa-check-circle"></i></span>
                            <span>
                                <span class="stat-value"><?php echo $stats['finalizado']; ?></span>
                                <span class="stat-label">Finalizado</span>
                            </span>
                        </a>
                    </div>
                    
                    <!-- Filtros -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5><span class="head-icon"><i class="fas fa-filter"></i></span> Filtros</h5>
                        </div>
                        <div class="card-body">
                            <form method="GET" class="row g-3">
                                <input type="hidden" name="vista" value="reparaciones">
                                <div class="col-md-3">
                                    <label class="form-label">Estado</label>
                                    <select name="filter_estado" class="form-select" onchange="this.form.submit()">
                                        <option value="">Todos</option>
                                        <option value="Enviado" <?php echo (((isset($_GET['filter_estado']) ? $_GET['filter_estado'] : '') === 'Enviado') ? 'selected' : ''); ?>>Enviado</option>
                                        <option value="Presupuestado" <?php echo (((isset($_GET['filter_estado']) ? $_GET['filter_estado'] : '') === 'Presupuestado') ? 'selected' : ''); ?>>Presupuestado</option>
                                        <option value="Orden de compra" <?php echo (((isset($_GET['filter_estado']) ? $_GET['filter_estado'] : '') === 'Orden de compra') ? 'selected' : ''); ?>>Orden de compra</option>
                                        <option value="Finalizado" <?php echo (((isset($_GET['filter_estado']) ? $_GET['filter_estado'] : '') === 'Finalizado') ? 'selected' : ''); ?>>Finalizado</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Técnico</label>
                                    <select name="filter_tecnico" class="form-select" onchange="this.form.submit()">
                                        <option value="">Todos</option>
                                        <?php foreach ($tecnicos as $tecnico): ?>
                                            <option value="<?php echo htmlspecialchars($tecnico); ?>" <?php echo (((isset($_GET['filter_tecnico']) ? $_GET['filter_tecnico'] : '') === $tecnico) ? 'selected' : ''); ?>><?php echo htmlspecialchars($tecnico); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Tipo de Equipo</label>
                                    <select name="filter_tipo" class="form-select" onchange="this.form.submit()">
                                        <option value="">Todos</option>
                                        <option value="PC de escritorio" <?php echo (((isset($_GET['filter_tipo']) ? $_GET['filter_tipo'] : '') === 'PC de escritorio') ? 'selected' : ''); ?>>PC de escritorio</option>
                                        <option value="notebook" <?php echo (((isset($_GET['filter_tipo']) ? $_GET['filter_tipo'] : '') === 'notebook') ? 'selected' : ''); ?>>Notebook</option>
                                        <option value="impresora" <?php echo (((isset($_GET['filter_tipo']) ? $_GET['filter_tipo'] : '') === 'impresora') ? 'selected' : ''); ?>>Impresora</option>
                                        <option value="proyector" <?php echo (((isset($_GET['filter_tipo']) ? $_GET['filter_tipo'] : '') === 'proyector') ? 'selected' : ''); ?>>Proyector</option>
                                        <option value="monitor" <?php echo (((isset($_GET['filter_tipo']) ? $_GET['filter_tipo'] : '') === 'monitor') ? 'selected' : ''); ?>>Monitor</option>
                                    </select>
                                </div>
                                <div class="col-md-3 d-flex align-items-end">
                                    <a href="?vista=reparaciones" class="btn btn-outline-secondary w-100">
                                        <i class="fas fa-times"></i> Limpiar filtros
                                    </a>
                                </div>
                            </form>
                        </div>
                    </div>
                    
                    <!-- Tabla de registros -->
                    <div class="card">
                        <div class="card-header">
                            <h5><span class="head-icon"><i class="fas fa-list"></i></span> Registros de Reparaciones</h5>
                            <span class="pill-count"><?php echo count($reparaciones); ?></span>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-container">
                                <table class="table table-striped table-hover mb-0">
                                    <thead class="sticky-top">
                                        <tr>
                                            <th>ID</th>
                                            <th>Tipo</th>
                                            <th>Marca/Modelo</th>
                                            <th>Motivo</th>
                                            <th>Estado</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $iconos_tipo = [
                                            'PC de escritorio' => 'fa-desktop',
                                            'notebook' => 'fa-laptop',
                                            'impresora' => 'fa-print',
                                            'proyector' => 'fa-video',
                                            'monitor' => 'fa-tv',
                                        ];
                                        $orden_estados = ['Enviado' => 1, 'Presupuestado' => 2, 'Orden de compra' => 3, 'Finalizado' => 4];
                                        ?>
                                        <?php foreach ($reparaciones as $rep): ?>
                                            <?php
                                            $badge_class = '';
                                            switch ($rep['estado']) {
                                                case 'Presupuestado': $badge_class = 'badge-presupuestado'; break;
                                                case 'Enviado': $badge_class = 'badge-enviado'; break;
                                                case 'Orden de compra': $badge_class = 'badge-en-reparacion'; break;
                                                case 'Finalizado': $badge_class = 'badge-finalizado'; break;
                                            }
                                            $icono_tipo = isset($iconos_tipo[$rep['tipo_equipo']]) ? $iconos_tipo[$rep['tipo_equipo']] : 'fa-microchip';
                                            $paso_estado = isset($orden_estados[$rep['estado']]) ? $orden_estados[$rep['estado']] : 0;
                                            $mini_ruta = '<span class="mini-ruta">';
                                            for ($i = 1; $i <= 4; $i++) {
                                                $mini_ruta .= '<i class="' . ($i <= $paso_estado ? 'activo' : '') . '"></i>';
                                            }
                                            $mini_ruta .= '</span>';
                                            ?>
                                            <tr onclick="verDetalle(<?php echo htmlspecialchars(json_encode($rep)); ?>)">
                                                <td class="td-id">#<?php echo $rep['id']; ?></td>
                                                <td class="tipo-cell">
                                                    <span class="tipo-icon"><i class="fas <?php echo $icono_tipo; ?>"></i></span>
                                                    <?php echo htmlspecialchars($rep['tipo_equipo']); ?>
                                                </td>
                                                <td>
                                                    <span class="equipo-nombre"><?php echo htmlspecialchars($rep['marca']); ?></span><br>
                                                    <span class="equipo-meta"><?php echo htmlspecialchars($rep['modelo']); ?></span>
                                                </td>
                                                <td class="motivo-cell"><?php echo htmlspecialchars($rep['motivo']); ?></td>
                                                <td class="estado-cell">
                                                    <span class="badge <?php echo $badge_class; ?>"><?php echo htmlspecialchars($rep['estado']); ?></span>
                                                    <?php echo $mini_ruta; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        
                                        <?php if (empty($reparaciones)): ?>
                                            <tr>
                                                <td colspan="5" class="text-center py-4">
                                                    <div class="empty-state">
                                                        <div class="empty-icon"><i class="fas fa-inbox"></i></div>
                                                        <p>No hay registros que mostrar</p>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                <?php else: ?>
                    <!-- Gestión de Secretarías y Oficinas -->
                    <div class="row">
                        <div class="col-md-6">
                            <!-- Gestión de Secretarías -->
                            <div class="card mb-4">
                                <div class="card-header">
                                    <h5><span class="head-icon"><i class="fas fa-university"></i></span> Gestión de Secretarías</h5>
                                </div>
                                <div class="card-body">
                                    <div class="row g-3 mb-3">
                                        <div class="col-md-8">
                                            <input type="text" class="form-control" id="nuevaSecretaria" placeholder="Nombre de la secretaría" maxlength="100">
                                        </div>
                                        <div class="col-md-4">
                                            <button class="btn btn-primary w-100" onclick="crearSecretaria()">
                                                <i class="fas fa-plus"></i> Crear
                                            </button>
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <input type="text" class="form-control" id="nuevaSecretariaDesc" placeholder="Descripción (opcional)" maxlength="255">
                                    </div>

                                    <div id="listadoSecretarias">
                                        <?php foreach ($secretarias as $secretaria): ?>
                                            <div class="item-row">
                                                <span class="item-icon secretaria"><i class="fas fa-university"></i></span>
                                                <div class="flex-grow-1">
                                                    <div class="item-name"><?php echo htmlspecialchars($secretaria['nombre']); ?></div>
                                                    <?php if ($secretaria['descripcion']): ?>
                                                        <div class="item-detail"><?php echo htmlspecialchars($secretaria['descripcion']); ?></div>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="item-acciones">
                                                    <button class="btn btn-sm btn-outline-secondary" title="Editar" onclick="editarSecretaria(<?php echo $secretaria['id']; ?>, '<?php echo addslashes($secretaria['nombre']); ?>', '<?php echo addslashes($secretaria['descripcion'] ?: ''); ?>')">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <button class="btn btn-sm btn-outline-danger" title="Eliminar" onclick="eliminarSecretaria(<?php echo $secretaria['id']; ?>)">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <!-- Gestión de Oficinas -->
                            <div class="card mb-4">
                                <div class="card-header">
                                    <h5><span class="head-icon teal"><i class="fas fa-building"></i></span> Gestión de Oficinas</h5>
                                </div>
                                <div class="card-body">
                                    <div class="row g-3 mb-3">
                                        <div class="col-md-6">
                                            <input type="text" class="form-control" id="nuevaOficina" placeholder="Nombre de la oficina" maxlength="100">
                                        </div>
                                        <div class="col-md-6">
                                            <div class="dropdown">
                                                <button class="btn btn-outline-secondary btn-select w-100 text-start position-relative" type="button" id="dropdownSecretariasBtn" data-dropdown-toggle="dropdownSecretariasMenu" aria-expanded="false" aria-controls="dropdownSecretariasMenu">
                                                    <span id="dropdownSecretariasText">Seleccione secretarías...</span>
                                                </button>
                                                <ul class="dropdown-menu" id="dropdownSecretariasMenu" aria-labelledby="dropdownSecretariasBtn">
                                                    <?php foreach ($secretarias as $secretaria): ?>
                                                        <li>
                                                            <label class="check-chip w-100" for="sec_<?php echo $secretaria['id']; ?>">
                                                                <input class="form-check-input secretaria-checkbox" type="checkbox" value="<?php echo $secretaria['id']; ?>" id="sec_<?php echo $secretaria['id']; ?>" onchange="actualizarBotonSecretarias()">
                                                                <?php echo htmlspecialchars($secretaria['nombre']); ?>
                                                            </label>
                                                        </li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <button class="btn btn-info w-100" onclick="crearOficina()">
                                            <i class="fas fa-plus"></i> Crear Oficina
                                        </button>
                                    </div>

                                    <div id="listadoOficinas">
                                        <?php foreach ($oficinas as $oficina): ?>
                                            <div class="item-row">
                                                <span class="item-icon oficina"><i class="fas fa-building"></i></span>
                                                <div class="flex-grow-1">
                                                    <div class="item-name"><?php echo htmlspecialchars($oficina['nombre']); ?></div>
                                                    <div class="item-detail"><?php echo htmlspecialchars($oficina['secretaria_nombre']); ?></div>
                                                </div>
                                                <div class="item-acciones">
                                                    <button class="btn btn-sm btn-outline-secondary" title="Editar" onclick="editarOficina(<?php echo $oficina['id']; ?>, '<?php echo addslashes($oficina['nombre']); ?>', '<?php echo $oficina['secretaria_ids']; ?>')">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <button class="btn btn-sm btn-outline-danger" title="Eliminar" onclick="eliminarOficina(<?php echo $oficina['id']; ?>)">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Modal Formulario -->
    <div class="modal fade" id="formModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <form method="POST" id="reparacionForm" class="over-scroll">
                    <div class="modal-header">
                        <h5 class="modal-title">
                            <i class="fas fa-plus"></i> 
                            <span id="modalTitle"><?php echo $edit_record ? 'Editar' : 'Agregar'; ?> Reparación</span>
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="<?php echo $edit_record ? 'update' : 'add'; ?>">
                        <?php if ($edit_record): ?>
                            <input type="hidden" name="id" value="<?php echo $edit_record['id']; ?>">
                        <?php endif; ?>
                        
                        <!-- Información del Equipo -->
                        <div class="form-section">
                            <div class="form-section-head">
                                <span class="head-icon"><i class="fas fa-laptop"></i></span>
                                <h6>Información del Equipo</h6>
                            </div>
                            <div class="form-section-body">
                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Tipo de Equipo *</label>
                                        <select name="tipo_equipo" class="form-select" required>
                                            <option value="">Seleccionar...</option>
                                            <option value="PC de escritorio" <?php echo ((isset($edit_record['tipo_equipo']) ? $edit_record['tipo_equipo'] : '') === 'PC de escritorio') ? 'selected' : ''; ?>>PC de escritorio</option>
                                            <option value="notebook" <?php echo ((isset($edit_record['tipo_equipo']) ? $edit_record['tipo_equipo'] : '') === 'notebook') ? 'selected' : ''; ?>>Notebook</option>
                                            <option value="impresora" <?php echo ((isset($edit_record['tipo_equipo']) ? $edit_record['tipo_equipo'] : '') === 'impresora') ? 'selected' : ''; ?>>Impresora</option>
                                            <option value="proyector" <?php echo ((isset($edit_record['tipo_equipo']) ? $edit_record['tipo_equipo'] : '') === 'proyector') ? 'selected' : ''; ?>>Proyector</option>
                                            <option value="monitor" <?php echo ((isset($edit_record['tipo_equipo']) ? $edit_record['tipo_equipo'] : '') === 'monitor') ? 'selected' : ''; ?>>Monitor</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Marca *</label>
                                        <input type="text" name="marca" class="form-control" value="<?php echo htmlspecialchars(isset($edit_record['marca']) ? $edit_record['marca'] : ''); ?>" required>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Modelo *</label>
                                        <input type="text" name="modelo" class="form-control" value="<?php echo htmlspecialchars(isset($edit_record['modelo']) ? $edit_record['modelo'] : ''); ?>" required>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Motivo de la Reparación *</label>
                                    <textarea name="motivo" class="form-control" rows="3" required><?php echo htmlspecialchars(isset($edit_record['motivo']) ? $edit_record['motivo'] : ''); ?></textarea>
                                </div>
                            </div>
                        </div>

                        <!-- Origen del Equipo -->
                        <div class="form-section">
                            <div class="form-section-head">
                                <span class="head-icon teal"><i class="fas fa-map-marker-alt"></i></span>
                                <h6>Origen del Equipo</h6>
                            </div>
                            <div class="form-section-body">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Secretaría de Origen</label>
                                        <select name="secretaria_origen_id" id="secretariaOrigen" class="form-select" onchange="cargarOficinasOrigen()">
                                            <option value="">Seleccionar secretaría...</option>
                                            <?php foreach ($secretarias as $secretaria): ?>
                                                <option value="<?php echo $secretaria['id']; ?>" <?php echo (((isset($edit_record['secretaria_origen_id']) ? $edit_record['secretaria_origen_id'] : '') == $secretaria['id']) ? 'selected' : ''); ?>>
                                                    <?php echo htmlspecialchars($secretaria['nombre']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Oficina de Origen</label>
                                        <select name="oficina_origen_id" id="oficinaOrigen" class="form-select">
                                            <option value="">Seleccionar oficina...</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Información de Reparación -->
                        <div class="form-section">
                            <div class="form-section-head">
                                <span class="head-icon ambar"><i class="fas fa-wrench"></i></span>
                                <h6>Información de Reparación</h6>
                            </div>
                            <div class="form-section-body">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Estado *</label>
                                        <select name="estado" id="estadoSelect" class="form-select" required onchange="mostrarCamposSegunEstado()">
                                            <option value="Enviado" <?php echo (((isset($edit_record['estado']) ? $edit_record['estado'] : 'Enviado') === 'Enviado') ? 'selected' : ''); ?>>Enviado</option>
                                            <option value="Presupuestado" <?php echo (((isset($edit_record['estado']) ? $edit_record['estado'] : '') === 'Presupuestado') ? 'selected' : ''); ?>>Presupuestado</option>
                                            <option value="Orden de compra" <?php echo (((isset($edit_record['estado']) ? $edit_record['estado'] : '') === 'Orden de compra') ? 'selected' : ''); ?>>Orden de compra</option>
                                            <option value="Finalizado" <?php echo (((isset($edit_record['estado']) ? $edit_record['estado'] : '') === 'Finalizado') ? 'selected' : ''); ?>>Finalizado</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6" id="divFechaEnvio">
                                        <label class="form-label">Fecha de Envío *</label>
                                        <input type="date" name="fecha_envio" class="form-control" value="<?php echo ((isset($edit_record['fecha_envio']) ? $edit_record['fecha_envio'] : '') ?: date('Y-m-d')); ?>">
                                    </div>

                                    <!-- Campos condicionales dinámicos -->
                                    <div class="col-md-6" id="divPersonaPresupuesto">
                                        <label class="form-label" id="lblPersonaPresupuesto">Persona Responsable Area *</label>
                                        <input type="text" name="persona_presupuesto" class="form-control" value="<?php echo htmlspecialchars(isset($edit_record['persona_presupuesto']) ? $edit_record['persona_presupuesto'] : ''); ?>">
                                    </div>
                                    <div class="col-md-6" id="divFechaPresupuesto">
                                        <label class="form-label">Fecha Presupuesto *</label>
                                        <input type="date" name="fecha_presupuesto" class="form-control" value="<?php echo ((isset($edit_record['fecha_presupuesto']) ? $edit_record['fecha_presupuesto'] : '') ?: date('Y-m-d')); ?>">
                                    </div>

                                    <div class="col-md-6" id="divTecnico">
                                        <label class="form-label">Técnico/Proveedor *</label>
                                        <input type="text" name="tecnico" class="form-control" value="<?php echo htmlspecialchars(isset($edit_record['tecnico']) ? $edit_record['tecnico'] : ''); ?>">
                                    </div>
                                    <div class="col-md-6" id="divCosto">
                                        <label class="form-label" id="lblCosto">Costo Estimado *</label>
                                        <input type="number" name="costo_estimado" class="form-control" step="0.01" value="<?php echo (isset($edit_record['costo_estimado']) ? $edit_record['costo_estimado'] : ''); ?>">
                                    </div>

                                    <div class="col-md-6" id="divNumeroOrden">
                                        <label class="form-label" id="lblNumeroOrden">Número de Orden *</label>
                                        <input type="number" name="numero_orden" class="form-control" value="<?php echo (isset($edit_record['numero_orden']) ? $edit_record['numero_orden'] : ''); ?>">
                                    </div>
                                    <div class="col-md-6" id="divFechaOrden">
                                        <label class="form-label" id="lblFechaOrden">Fecha de Orden *</label>
                                        <input type="date" name="fecha_orden" class="form-control" value="<?php echo ((isset($edit_record['fecha_orden']) ? $edit_record['fecha_orden'] : '') ?: date('Y-m-d')); ?>">
                                    </div>
                                </div>

                                <div class="mb-3 mt-3" id="divObservaciones">
                                    <div>
                                        <label class="form-label">Observaciones</label>
                                        <textarea name="observaciones" class="form-control" rows="3"><?php echo htmlspecialchars(isset($edit_record['observaciones']) ? $edit_record['observaciones'] : ''); ?></textarea>
                                    </div>
                                    <!-- Subir presupuesto -->
                                    <div class="budget-upload-area mb-3" id="divAdjuntarPresupuesto">
                                        <div class="upload-input d-flex align-items-center">
                                            <!-- Botón con icono PDF - gris cuando vacío, rojo cuando hay archivo -->
                                            <label class="btn-subir-presupuesto position-relative w-auto cursor-pointer">
                                                <input type="file" name="pdf" class="d-none" accept="application/pdf">
                                                <i class="fas fa-file-pdf fa-2x"></i>
                                            </label>
                                            <button type="button" class="btn btn-outline-danger btn-eliminar ms-2 d-none">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        </div>
                                        <p class="text-muted small upload-filename mt-2" style="display: none;"></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> <?php echo $edit_record ? 'Actualizar' : 'Guardar'; ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal Detalle -->
    <div class="modal fade" id="detalleModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-info-circle"></i> Detalle de Reparación</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="detalleContent">
                    <!-- Contenido dinámico -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>
    
    <div class="toast-container" id="toastContainer"></div>

    <script>
        let editandoSecretariaId = null;
        let editandoOficinaId = null;

        // ======================== CAPA UI PROPIA (reemplaza Bootstrap JS) ========================
        let modalAbierto = null;

        function abrirModal(id) {
            const el = document.getElementById(id);
            if (!el || el.classList.contains('show')) return;
            modalAbierto = el;
            el.classList.add('show');
            document.body.classList.add('modal-abierto');
        }

        function cerrarModal(el) {
            if (!el) return;
            el.classList.remove('show');
            modalAbierto = null;
            if (!document.querySelector('.modal.show')) {
                document.body.classList.remove('modal-abierto');
            }
        }

        // Cierre por botón [data-bs-dismiss], clic en el fondo y tecla ESC
        document.addEventListener('click', function(e) {
            const cerrarBtn = e.target.closest('[data-bs-dismiss="modal"]');
            if (cerrarBtn) {
                cerrarModal(cerrarBtn.closest('.modal'));
                return;
            }
            if (e.target.classList.contains('modal')) {
                cerrarModal(e.target);
            }
        });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && modalAbierto) {
                cerrarModal(modalAbierto);
            }
        });

        // Dropdown de secretarías
        document.addEventListener('click', function(e) {
            const toggle = e.target.closest('[data-dropdown-toggle]');
            if (toggle) {
                const menu = document.getElementById(toggle.getAttribute('data-dropdown-toggle'));
                if (!menu) return;
                const abierto = menu.classList.contains('show');
                document.querySelectorAll('.dropdown-menu.show').forEach(m => {
                    m.classList.remove('show');
                    const btn = document.querySelector('[data-dropdown-toggle="' + m.id + '"]');
                    if (btn) btn.setAttribute('aria-expanded', 'false');
                });
                if (!abierto) {
                    menu.classList.add('show');
                    toggle.setAttribute('aria-expanded', 'true');
                }
                return;
            }
            document.querySelectorAll('.dropdown-menu.show').forEach(m => {
                if (!m.contains(e.target)) {
                    m.classList.remove('show');
                    const btn = document.querySelector('[data-dropdown-toggle="' + m.id + '"]');
                    if (btn) btn.setAttribute('aria-expanded', 'false');
                }
            });
        });

        // Cierre de alertas
        document.addEventListener('click', function(e) {
            const close = e.target.closest('[data-bs-dismiss="alert"]');
            if (close) {
                const alerta = close.closest('.alert');
                if (alerta) alerta.remove();
            }
        });

        // Variables globales para manejo de formularios
        function cambiarVista(vista) {
            window.location.href = '?vista=' + vista;
        }

        // Mostrar modal al cargar si hay registro para editar
        <?php if ($edit_record || (isset($_GET['action']) && $_GET['action'] === 'new')): ?>
            document.addEventListener('DOMContentLoaded', function() {
                abrirModal('formModal');
                <?php if ($edit_record): ?>
                    cargarOficinasOrigen(<?php echo $edit_record['oficina_origen_id'] ?: 'null'; ?>);
                <?php endif; ?>
                mostrarCamposSegunEstado();
            });
        <?php endif; ?>
        
        // Función para mostrar/ocultar campos según el estado
        function mostrarCamposSegunEstado() {
            const estado = document.getElementById('estadoSelect').value;
            
            // Obtener contenedores
            const divPersonaPresupuesto = document.getElementById('divPersonaPresupuesto');
            const divFechaPresupuesto = document.getElementById('divFechaPresupuesto');
            const divTecnico = document.getElementById('divTecnico');
            const divCosto = document.getElementById('divCosto');
            const divNumeroOrden = document.getElementById('divNumeroOrden');
            const divFechaOrden = document.getElementById('divFechaOrden');
            const divFechaEnvio = document.getElementById('divFechaEnvio');
            const divAdjuntarPresupuesto = document.getElementById('divAdjuntarPresupuesto');
            const divObservaciones = document.getElementById('divObservaciones');

            // Obtener labels para renombrar
            const lblCosto = document.getElementById('lblCosto');
            const lblNumeroOrden = document.getElementById('lblNumeroOrden');
            const lblFechaOrden = document.getElementById('lblFechaOrden');

            // Obtener inputs para required
            const inputFechaEnvio = document.querySelector('[name="fecha_envio"]');
            const inputPersona = document.querySelector('[name="persona_presupuesto"]');
            const inputFechaPresupuesto = document.querySelector('[name="fecha_presupuesto"]');
            const inputAdjuntarPresupuesto = document.querySelector('[name="pdf"]');
            const inputTecnico = document.querySelector('[name="tecnico"]');
            const inputCosto = document.querySelector('[name="costo_estimado"]');
            const inputNumeroOrden = document.querySelector('[name="numero_orden"]');
            const inputFechaOrden = document.querySelector('[name="fecha_orden"]');
            
            // Resetear visibilidad (ocultar todo por defecto)
            const todosLosDivs = [
                divPersonaPresupuesto, divFechaPresupuesto, divTecnico, 
                divCosto, divNumeroOrden, divFechaOrden, divFechaEnvio, divAdjuntarPresupuesto, divObservaciones
            ];
            todosLosDivs.forEach(div => div.style.display = 'none');
            
            // Resetear required
            const todosLosInputs = [
                inputFechaEnvio, inputPersona, inputFechaPresupuesto, 
                inputTecnico, inputCosto, inputNumeroOrden, inputFechaOrden, inputAdjuntarPresupuesto
            ];
            todosLosInputs.forEach(input => { if(input) input.required = false; });

            // Lógica según estado
            if (estado === 'Enviado') {
                // Enviado: Técnico + Fecha Envío
                divTecnico.style.display = 'block';
                divFechaEnvio.style.display = 'block';
                
                if(inputTecnico) inputTecnico.required = true;
                if(inputFechaEnvio) inputFechaEnvio.required = true;

            } else if (estado === 'Presupuestado') {
                // Presupuestado: +Costo, +Persona, +FechaPresupuesto
                divObservaciones.style.display = 'block';
                divPersonaPresupuesto.style.display = 'block';
                divFechaPresupuesto.style.display = 'block';
                divCosto.style.display = 'block';
                divAdjuntarPresupuesto.style.display = 'block';
                
                // Renombrar
                lblCosto.textContent = 'Costo *';

                // Required
                if(inputPersona) inputPersona.required = true;
                if(inputFechaPresupuesto) inputFechaPresupuesto.required = true;
                if(inputCosto) inputCosto.required = true;

            } else if (estado === 'Finalizado') {
                // Finalizado: Costo Final, Fecha Vuelta
                divCosto.style.display = 'none';
                divFechaOrden.style.display = 'block';
                
                // Renombrar
                lblFechaOrden.textContent = 'Fecha Vuelta *';

                // Required
                if(inputCosto) inputCosto.required = true;
                if(inputFechaOrden) inputFechaOrden.required = true;

            } else if (estado === 'Orden de compra') {
                // Orden de compra: Técnico, Fecha Envío, Número de Orden
                divTecnico.style.display = 'block';
                divFechaEnvio.style.display = 'block';
                divNumeroOrden.style.display = 'block';
                
                // Renombrar
                lblNumeroOrden.textContent = 'Número de Orden *';

                if(inputTecnico) inputTecnico.required = true;
                if(inputFechaEnvio) inputFechaEnvio.required = true;
                if(inputNumeroOrden) inputNumeroOrden.required = true;
            }
        }

        // Inicializar campos condicionales al cargar la página
        document.addEventListener('DOMContentLoaded', function() {
            mostrarCamposSegunEstado();
        });
        
        // Función para cargar oficinas de origen
        function cargarOficinasOrigen(selectedOficina = null) {
            const secretariaId = document.getElementById('secretariaOrigen').value;
            const oficinaSelect = document.getElementById('oficinaOrigen');
            
            oficinaSelect.innerHTML = '<option value="">Seleccionar oficina...</option>';
            
            if (secretariaId) {
                const formData = new FormData();
                formData.append('ajax_action', 'get_oficinas_por_secretaria');
                formData.append('secretaria_id', secretariaId);

                fetch('', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        data.oficinas.forEach(oficina => {
                            const option = document.createElement('option');
                            option.value = oficina.id;
                            option.textContent = oficina.nombre;
                            if (selectedOficina && oficina.id == selectedOficina) {
                                option.selected = true;
                            }
                            oficinaSelect.appendChild(option);
                        });
                    }
                })
                .catch(error => {
                    console.error('Error cargando oficinas:', error);
                });
            }
        }
        
        // Función para mostrar mensajes (toasts)
        function mostrarMensaje(mensaje, tipo = 'success') {
            let container = document.getElementById('toastContainer');
            const esError = tipo === 'danger';
            const icono = esError ? 'fa-circle-exclamation' : 'fa-circle-check';

            const toast = document.createElement('div');
            toast.className = 'toast toast-' + (esError ? 'danger' : 'success');
            toast.setAttribute('role', 'status');
            toast.innerHTML = `<i class="fas ${icono}"></i><span>${mensaje}</span>`;
            container.appendChild(toast);

            requestAnimationFrame(() => toast.classList.add('show'));
            setTimeout(() => {
                toast.classList.remove('show');
                setTimeout(() => toast.remove(), 250);
            }, 3800);
        }

        // Gestión de Secretarías
        function crearSecretaria() {
            const nombre = document.getElementById('nuevaSecretaria').value.trim();
            const descripcion = document.getElementById('nuevaSecretariaDesc').value.trim();
            
            if (!nombre) {
                mostrarMensaje('El nombre es obligatorio', 'danger');
                return;
            }

            const formData = new FormData();
            formData.append('ajax_action', editandoSecretariaId ? 'editar_secretaria' : 'crear_secretaria');
            formData.append('nombre', nombre);
            formData.append('descripcion', descripcion);
            if (editandoSecretariaId) formData.append('id', editandoSecretariaId);

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                mostrarMensaje(data.message, data.success ? 'success' : 'danger');
                if (data.success) {
                    document.getElementById('nuevaSecretaria').value = '';
                    document.getElementById('nuevaSecretariaDesc').value = '';
                    editandoSecretariaId = null;
                    setTimeout(() => location.reload(), 1000);
                }
            });
        }

        function editarSecretaria(id, nombre, descripcion = '') {
            document.getElementById('nuevaSecretaria').value = nombre;
            document.getElementById('nuevaSecretariaDesc').value = descripcion;
            editandoSecretariaId = id;
        }

        function eliminarSecretaria(id) {
            if (!confirm('¿Está seguro de eliminar esta secretaría?')) return;

            const formData = new FormData();
            formData.append('ajax_action', 'eliminar_secretaria');
            formData.append('id', id);

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                mostrarMensaje(data.message, data.success ? 'success' : 'danger');
                if (data.success) {
                    setTimeout(() => location.reload(), 1000);
                }
            });
        }

        // Actualizar texto del botón dropdown
        function actualizarBotonSecretarias() {
            const checkboxes = document.querySelectorAll('.secretaria-checkbox:checked');
            const btn = document.getElementById('dropdownSecretariasText');
            if (checkboxes.length === 0) {
                btn.textContent = 'Seleccione secretarías...';
            } else if (checkboxes.length === 1) {
                const label = document.querySelector(`label[for="${checkboxes[0].id}"]`).textContent.trim();
                btn.textContent = label;
            } else {
                btn.textContent = `${checkboxes.length} seleccionadas`;
            }
        }

        // Gestión de Oficinas
        function crearOficina() {
            const nombre = document.getElementById('nuevaOficina').value.trim();
            const checkboxes = document.querySelectorAll('.secretaria-checkbox:checked');
            const secretariaIds = Array.from(checkboxes).map(cb => cb.value);
            
            if (!nombre || secretariaIds.length === 0) {
                mostrarMensaje('El nombre y al menos una secretaría son obligatorios', 'danger');
                return;
            }

            const formData = new FormData();
            formData.append('ajax_action', editandoOficinaId ? 'editar_oficina' : 'crear_oficina');
            formData.append('nombre', nombre);
            formData.append('secretaria_ids', JSON.stringify(secretariaIds));
            if (editandoOficinaId) formData.append('id', editandoOficinaId);

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                mostrarMensaje(data.message, data.success ? 'success' : 'danger');
                if (data.success) {
                    document.getElementById('nuevaOficina').value = '';
                    document.querySelectorAll('.secretaria-checkbox').forEach(cb => cb.checked = false);
                    actualizarBotonSecretarias();
                    editandoOficinaId = null;
                    setTimeout(() => location.reload(), 1000);
                }
            });
        }

        function editarOficina(id, nombre, secretariaIdsString) {
            document.getElementById('nuevaOficina').value = nombre;
            
            // Limpiar selección previa
            document.querySelectorAll('.secretaria-checkbox').forEach(cb => cb.checked = false);
            
            // Seleccionar valores
            if (secretariaIdsString) {
                const ids = String(secretariaIdsString).split(',');
                ids.forEach(id => {
                    const checkbox = document.querySelector(`.secretaria-checkbox[value="${id}"]`);
                    if (checkbox) checkbox.checked = true;
                });
            }
            actualizarBotonSecretarias();
            editandoOficinaId = id;
        }

        function eliminarOficina(id) {
            if (!confirm('¿Está seguro de eliminar esta oficina?')) return;

            const formData = new FormData();
            formData.append('ajax_action', 'eliminar_oficina');
            formData.append('id', id);

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                mostrarMensaje(data.message, data.success ? 'success' : 'danger');
                if (data.success) {
                    setTimeout(() => location.reload(), 1000);
                }
            });
        }
        
        // Función para mostrar detalle
        function verDetalle(registro) {
            const estados = {
                'Presupuestado': 'badge-presupuestado',
                'Enviado': 'badge-enviado',
                'Orden de compra': 'badge-en-reparacion',
                'Finalizado': 'badge-finalizado'
            };
            
            const badgeClass = estados[registro.estado] || '';
            
            const formatearFecha = (fecha) => fecha ? new Date(fecha).toLocaleDateString('es-ES') : 'No definida';
            
            // Firma: ruta de reparación (timeline de 4 pasos)
            const ordenRuta = ['Enviado', 'Presupuestado', 'Orden de compra', 'Finalizado'];
            const pasoActual = ordenRuta.indexOf(registro.estado);
            const fechasRuta = {
                'Enviado': registro.fecha_envio ? formatearFecha(registro.fecha_envio) : '',
                'Presupuestado': registro.fecha_presupuesto ? formatearFecha(registro.fecha_presupuesto) : '',
                'Orden de compra': registro.numero_orden ? 'Orden #' + registro.numero_orden : '',
                'Finalizado': registro.fecha_orden ? formatearFecha(registro.fecha_orden) : ''
            };
            const rutaHtml = `
                <div class="ruta">
                    ${ordenRuta.map((paso, i) => {
                        const clase = i < pasoActual ? 'completado' : (i === pasoActual ? 'actual' : '');
                        const fecha = fechasRuta[paso] ? `<span class="ruta-fecha">${fechasRuta[paso]}</span>` : '';
                        return `
                            <div class="ruta-paso ${clase}">
                                <span class="ruta-nodo">${i < pasoActual ? '<i class="fas fa-check"></i>' : (i + 1)}</span>
                                <span class="ruta-label">${paso}</span>
                                ${fecha}
                            </div>`;
                    }).join('')}
                </div>
            `;
            
            const campoDetalle = (k, v) => `
                <div class="det-field">
                    <span class="k">${k}</span>
                    <span class="v">${v}</span>
                </div>`;
            
            const origenSec = registro.secretaria_origen ? `<span class="badge bg-secondary">${registro.secretaria_origen}</span>` : '';
            const origenOfi = registro.oficina_origen ? `<span class="badge bg-secondary">Oficina: ${registro.oficina_origen}</span>` : '';
            
            // Obtener historial de movimientos via AJAX
            const historialHtml = async () => {
                let html = '';
                const cajaHistorial = (contenido) => `
                    <div class="historial-box">
                        <h6><i class="fas fa-history"></i> Historial de Movimientos</h6>
                        ${contenido}
                    </div>`;
                try {
                    const formData = new FormData();
                    formData.append('ajax_action', 'get_movimientos');
                    formData.append('id', registro.id);
                    
                    const response = await fetch('', {
                        method: 'POST',
                        body: formData
                    });
                    const data = await response.json();
                    if (data.success && data.movimientos.length > 0) {
                        html = cajaHistorial(`
                            <div class="table-responsive">
                                <table class="table table-sm table-striped mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Fecha</th>
                                            <th>Tipo</th>
                                            <th>Descripción</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    ${data.movimientos.map(m => `
                                        <tr>
                                            <td class="align-middle text-muted">${new Date(m.fecha_movimiento).toLocaleDateString('es-ES')} ${new Date(m.fecha_movimiento).toLocaleTimeString('es-ES')}</td>
                                            <td class="align-middle text-primary">${m.tipo_movimiento}</td>
                                            <td class="align-middle">${m.descripcion}</td>
                                        </tr>
                                    `).join('')}
                                    </tbody>
                                </table>
                            </div>
                        `);
                    } else if (data.success) {
                        html = cajaHistorial(`<p class="mb-0 text-muted">No hay registros de movimiento</p>`);
                    }
                } catch (error) {
                    html = cajaHistorial(`<p class="mb-0 text-muted">Error cargando historial</p>`);
                }
                return html;
            };
            
            const content = `
                <div class="detalle-head">
                    <span class="detalle-id">Reparación #${registro.id}</span>
                    <span class="badge ${badgeClass}">${registro.estado}</span>
                </div>
                
                ${rutaHtml}
                
                <div class="detalle-grid">
                    <div class="det-seccion">
                        <h6><i class="fas fa-laptop"></i> Equipo</h6>
                        ${campoDetalle('Tipo de Equipo', registro.tipo_equipo || 'N/A')}
                        ${campoDetalle('Marca', registro.marca || 'N/A')}
                        ${campoDetalle('Modelo', registro.modelo || 'N/A')}
                    </div>
                    <div class="det-seccion">
                        <h6><i class="fas fa-wrench"></i> Reparación</h6>
                        ${campoDetalle('Técnico', registro.tecnico || 'No asignado')}
                        ${campoDetalle('Costo', registro.costo_estimado ? `$${parseFloat(registro.costo_estimado).toFixed(2)}` : 'No definido')}
                        ${campoDetalle('Número de Orden', registro.numero_orden || 'No asignado')}
                    </div>
                    <div class="det-seccion">
                        <h6><i class="fas fa-calendar-alt"></i> Fechas</h6>
                        ${campoDetalle('Fecha de Envío', formatearFecha(registro.fecha_envio))}
                        ${campoDetalle('Fecha de Presupuesto', formatearFecha(registro.fecha_presupuesto))}
                        ${campoDetalle('Fecha de Orden', formatearFecha(registro.fecha_orden))}
                    </div>
                    <div class="det-seccion">
                        <h6><i class="fas fa-user"></i> Presupuesto</h6>
                        ${campoDetalle('Persona Responsable', registro.persona_presupuesto || 'No especificada')}
                        ${campoDetalle('Observaciones', registro.observaciones ? registro.observaciones : 'Ninguna')}
                    </div>
                </div>
                
                <div class="box-soft">
                    <h6><i class="fas fa-text-width"></i> Motivo</h6>
                    <p>${registro.motivo || 'N/A'}</p>
                </div>
                
                ${origenSec || origenOfi ? `
                <div class="origen-info">
                    <i class="fas fa-map-marker-alt"></i>
                    <div>
                        <strong>Origen</strong>
                        <div class="mt-1">${origenSec} ${origenOfi}</div>
                    </div>
                </div>
                ` : ''}
                
                <!-- Historial de Movimientos se carga dinámicamente -->
                <div id="historialMovimientos${registro.id}">
                    <div class="historial-box">
                        <i class="fas fa-spinner fa-pulse cargando-texto"> Cargando historial...</i>
                    </div>
                </div>
            `;
            
            document.getElementById('detalleContent').innerHTML = content;
            
            // Cargar historial de movimientos
            historialHtml().then(html => {
                document.getElementById('historialMovimientos' + registro.id).innerHTML = html;
            });
            
            const footer = document.querySelector('#detalleModal .modal-footer');
            footer.innerHTML = `
                <div class="d-flex justify-content-between w-100">
                    <div>
                        <a href="?action=edit&id=${registro.id}&vista=reparaciones" class="btn btn-warning">
                            <i class="fas fa-edit"></i> Editar
                        </a>
                        <a href="?action=delete&id=${registro.id}&vista=reparaciones" class="btn btn-danger" onclick="return confirm('¿Está seguro de eliminar este registro?')">
                            <i class="fas fa-trash"></i> Eliminar
                        </a>
                    </div>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                </div>
            `;
            
            abrirModal('detalleModal');
        }
    </script>
    
    <script>
        // Manejo de subida de presupuesto - Funcionalidad creativa
        document.addEventListener('DOMContentLoaded', function() {
            const uploadArea = document.getElementById('divAdjuntarPresupuesto');
            const fileInput = uploadArea.querySelector('input[type="file"]');
            const btnSubir = uploadArea.querySelector('.btn-subir-presupuesto');
            const filenameDisplay = uploadArea.querySelector('.upload-filename');
            const eliminarBtn = uploadArea.querySelector('.btn-eliminar');
            
            // Manejar selección de archivo
            fileInput.addEventListener('change', function(e) {
                const file = e.target.files[0];
                
                if (file) {
                    // Validar tipo de archivo
                    if (!file.type.startsWith('application/pdf')) {
                        mostrarError('Solo se permiten archivos PDF');
                        fileInput.value = '';
                        return;
                    }
                    
                    // Validar tamaño (máximo 10MB)
                    if (file.size > 10 * 1024 * 1024) {
                        mostrarError('El archivo es demasiado grande (máximo 10MB)');
                        fileInput.value = '';
                        return;
                    }
                    
                    // Cambiar estilo del botón a rojo
                    btnSubir.style.background = '#C2452F';
                    btnSubir.style.color = '#fff';
                    btnSubir.style.boxShadow = '0 2px 8px rgba(194, 69, 47, .35)';
                    
                    // Mostrar nombre y botón de quitar
                    filenameDisplay.textContent = 'Archivo seleccionado: ' + file.name;
                    filenameDisplay.style.display = 'block';
                    eliminarBtn.style.display = 'inline-block';
                }
            });
            
            // Quitar archivo
            eliminarBtn.addEventListener('click', function() {
                fileInput.value = '';
                filenameDisplay.style.display = 'none';
                
                // Restaurar estilo del botón (gris)
                btnSubir.style.background = '#ECE5D8';
                btnSubir.style.color = '';
                btnSubir.style.boxShadow = '';
                
                // Ocultar botón de quitar
                eliminarBtn.style.display = 'none';
            });
        });
        
        // Función auxiliar para mostrar errores
        function mostrarError(mensaje) {
            mostrarMensaje(mensaje, 'danger');
        }
    </script>
</body>
</html>
