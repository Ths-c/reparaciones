CREATE DATABASE IF NOT EXISTS reparaciones;
USE reparaciones;

-- Tabla de Secretarías
CREATE TABLE IF NOT EXISTS secretarias (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(255) NOT NULL,
    descripcion TEXT,
    activa TINYINT(1) DEFAULT 1
);

-- Tabla de Oficinas/Departamentos
CREATE TABLE IF NOT EXISTS oficinas_departamentos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(255) NOT NULL,
    secretaria_id INT,
    activa TINYINT(1) DEFAULT 1,
    FOREIGN KEY (secretaria_id) REFERENCES secretarias(id) ON DELETE SET NULL
);

-- Tabla de Reparaciones
CREATE TABLE IF NOT EXISTS reparaciones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tipo_equipo VARCHAR(100),
    marca VARCHAR(100),
    modelo VARCHAR(100),
    motivo TEXT,
    secretaria_origen_id INT,
    oficina_origen_id INT,
    fecha_envio DATE,
    tecnico VARCHAR(100),
    costo_estimado DECIMAL(10, 2),
    persona_presupuesto VARCHAR(100),
    fecha_presupuesto DATE,
    numero_orden VARCHAR(50),
    fecha_orden DATE,
    estado VARCHAR(50),
    observaciones TEXT,
    FOREIGN KEY (secretaria_origen_id) REFERENCES secretarias(id) ON DELETE SET NULL,
    FOREIGN KEY (oficina_origen_id) REFERENCES oficinas_departamentos(id) ON DELETE SET NULL
);
