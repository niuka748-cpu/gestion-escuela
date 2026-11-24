-- Ejercicio 3: Sistema de gestión escolar.
-- Hecho por Norka Mideros.

CREATE DATABASE IF NOT EXISTS gestion_escuela
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE gestion_escuela;

-- Roles y cuentas para el formulario de inicio de sesión.
CREATE TABLE roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(20) NOT NULL UNIQUE
) ENGINE=InnoDB;

INSERT INTO roles (nombre)
VALUES ('Administrador'), ('Profesor'), ('Estudiante');

CREATE TABLE usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    correo VARCHAR(150) NOT NULL UNIQUE,
    contrasena_hash VARCHAR(255) NOT NULL,
    rol_id INT NOT NULL,
    FOREIGN KEY (rol_id) REFERENCES roles(id)
) ENGINE=InnoDB;

-- Cada estudiante y profesor se vincula a su cuenta.
CREATE TABLE estudiantes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NOT NULL UNIQUE,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB;

CREATE TABLE profesores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NOT NULL UNIQUE,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB;

-- Una asignatura puede ofrecerse en varios cursos.
CREATE TABLE asignaturas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL UNIQUE,
    descripcion VARCHAR(255) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE cursos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    asignatura_id INT NOT NULL,
    profesor_id INT NOT NULL,
    nombre VARCHAR(100) NOT NULL,
    descripcion VARCHAR(255) NOT NULL,
    fecha_inicio DATE NOT NULL,
    fecha_fin DATE NOT NULL,
    FOREIGN KEY (asignatura_id) REFERENCES asignaturas(id),
    FOREIGN KEY (profesor_id) REFERENCES profesores(id),
    CHECK (fecha_fin >= fecha_inicio)
) ENGINE=InnoDB;

-- Los estudiantes solicitan matrícula en un curso.
CREATE TABLE matriculas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    estudiante_id INT NOT NULL,
    curso_id INT NOT NULL,
    fecha_solicitud TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    estado ENUM('Pendiente', 'Aprobada', 'Rechazada')
        NOT NULL DEFAULT 'Pendiente',
    UNIQUE (estudiante_id, curso_id),
    FOREIGN KEY (estudiante_id) REFERENCES estudiantes(id),
    FOREIGN KEY (curso_id) REFERENCES cursos(id)
) ENGINE=InnoDB;

-- Cada calificación corresponde a una evaluación de una matrícula.
CREATE TABLE calificaciones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    matricula_id INT NOT NULL,
    evaluacion VARCHAR(100) NOT NULL,
    nota DECIMAL(5, 2) NOT NULL,
    UNIQUE (matricula_id, evaluacion),
    FOREIGN KEY (matricula_id) REFERENCES matriculas(id),
    CHECK (nota BETWEEN 0 AND 100)
) ENGINE=InnoDB;

-- Actividades que aparecerán en el calendario escolar.
CREATE TABLE eventos_calendario (
    id INT AUTO_INCREMENT PRIMARY KEY,
    titulo VARCHAR(100) NOT NULL,
    descripcion VARCHAR(255) NOT NULL,
    fecha DATE NOT NULL
) ENGINE=InnoDB;