# SiGeRu - API de Gestión

API de gestión operativa del proyecto **SiGeRu - Sistema de Gestión de Residuos Urbanos**.

Este repositorio contiene el backend desarrollado con **Laravel** encargado de administrar la información operativa del sistema y comunicarse con la base de datos `sigeru`.

La API es consumida principalmente por el repositorio frontend:

[SiGeRu-Website](https://github.com/DeltaLogic-UTU/SiGeRu-Website)

## Funcionalidades

Actualmente la API permite gestionar información relacionada con:

- camiones;
- contenedores;
- circuitos;
- cuadrillas;
- incidencias;
- instalaciones;
- inventario;
- operarios;
- reclamos;
- historial y seguimiento de distintos recursos operativos.

La información se obtiene y modifica mediante solicitudes HTTP realizadas desde el frontend.

## Tecnologías utilizadas

- PHP
- Laravel
- Composer
- MariaDB / MySQL
- API REST
- JSON
- Sesiones de Laravel
- Protección CSRF

## Organización del proyecto

```text
SiGeRu-API-Gestion/

├── app/
│   ├── Http/
│   │   └── Controllers/
│   │       ├── CamionController.php
│   │       ├── ContenedorController.php
│   │       ├── CuadrillaController.php
│   │       ├── IncidenciaController.php
│   │       ├── InstalacionController.php
│   │       └── ReclamoController.php
│   │
│   └── Models/
│
├── bootstrap/
├── config/
├── database/
│   ├── migrations/
│   └── seeders/
│
├── public/
├── resources/
├── routes/
│   └── web.php
│
├── storage/
├── tests/
├── .env.example
├── .gitignore
├── artisan
├── composer.json
├── composer.lock
└── README.md
```

## Base de datos

La API utiliza una base de datos MariaDB/MySQL llamada:

```text
sigeru
```

El SQL completo del proyecto se encuentra en el repositorio:

[SiGeRu-DumpSQL](https://github.com/DeltaLogic-UTU/SiGeRu-DumpSQL)

Archivo principal:

```text
SQL_SiGeRU.sql
```

El archivo contiene la estructura, relaciones y datos necesarios para recrear la base utilizada por SiGeRu.

## Configuración del entorno

El archivo `.env` contiene configuraciones propias de cada entorno y no debe subirse al repositorio.

El proyecto incluye:

```text
.env.example
```

como configuración base.

Después de clonar el proyecto se debe crear el archivo `.env`:

```powershell
copy .env.example .env
```

Luego generar la clave de Laravel:

```powershell
php artisan key:generate
```

La configuración principal de la base de datos es:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=sigeru
DB_USERNAME=root
DB_PASSWORD=
```

Si la instalación local de MySQL/MariaDB utiliza otro usuario o contraseña, estos valores deben modificarse en el archivo `.env`.

La API de Gestión utiliza una cookie de sesión propia:

```env
SESSION_COOKIE=sigeru_gestion_session
```

Esto permite diferenciar su sesión de la utilizada por otros servicios de SiGeRu.

## CSRF y sesiones

La API utiliza sesiones de Laravel y protección CSRF para las solicitudes que modifican información.

El frontend obtiene el token mediante:

```text
GET /csrf-token
```

Luego lo envía en las solicitudes correspondientes mediante el encabezado:

```text
X-CSRF-TOKEN
```

Las solicitudes desde el frontend utilizan también las credenciales de sesión.

Los tokens CSRF se generan automáticamente y no deben guardarse en `.env` ni en el repositorio.

## Rutas

Las rutas de la API están definidas en:

```text
routes/web.php
```

La dirección base utilizada durante el desarrollo es:

```text
http://127.0.0.1:8001
```

Algunos grupos de endpoints utilizados por el sistema son:

### Contenedores

```text
GET /api/contenedores
GET /api/contenedores/catalogos
GET /api/contenedores/resumen-circuitos
GET /api/contenedores/{id}/historial
```

El resumen de circuitos permite utilizar filtros como:

```text
prioridad
municipio
circuito
```

### Cuadrillas

```text
GET    /api/cuadrillas
GET    /api/cuadrillas/responsables
POST   /api/cuadrillas
PUT    /api/cuadrillas/{id}
DELETE /api/cuadrillas/{id}

GET /api/cuadrillas/{id}/integrantes
GET /api/cuadrillas/{id}/integrantes-disponibles
```

### Instalaciones

La gestión de instalaciones utiliza:

```text
/api/instalaciones
```

También se administra el inventario de cada instalación mediante rutas anidadas:

```text
GET    /api/instalaciones/{id}/inventario
POST   /api/instalaciones/{id}/inventario
PUT    /api/instalaciones/{id}/inventario/{id_inventario}
DELETE /api/instalaciones/{id}/inventario/{id_inventario}
```

El inventario puede filtrarse por tipo.

Las rutas completas y actualizadas deben consultarse en:

```text
routes/web.php
```

## Ejecutar el proyecto localmente

Se necesitan:

- PHP
- Composer
- MySQL/MariaDB
- XAMPP o un entorno equivalente

### 1. Clonar el repositorio

```powershell
git clone https://github.com/DeltaLogic-UTU/SiGeRu-API-Gestion.git
cd SiGeRu-API-Gestion
```

El código de la API se encuentra en la rama:

```text
develop
```

Cambiar a ella:

```powershell
git switch develop
```

### 2. Instalar dependencias

```powershell
composer install
```

### 3. Crear el archivo de entorno

```powershell
copy .env.example .env
```

### 4. Generar la clave de Laravel

```powershell
php artisan key:generate
```

### 5. Preparar la base de datos

Iniciar MySQL/MariaDB.

Crear la base:

```text
sigeru
```

e importar:

```text
SQL_SiGeRU.sql
```

desde el repositorio `SiGeRu-DumpSQL`.

### 6. Iniciar la API

```powershell
php artisan serve --port=8001
```

La API quedará disponible en:

```text
http://127.0.0.1:8001
```

## Integración con el frontend

El frontend de SiGeRu utiliza como dirección de la API de Gestión:

```javascript
const API_GESTION = 'http://127.0.0.1:8001';
```

Las solicitudes se realizan mediante `fetch` y utilizan JSON para el intercambio de información.

Para las operaciones que requieren protección CSRF, el frontend obtiene previamente el token desde `/csrf-token`.

Este archivo permite que otra persona pueda preparar su propio entorno sin compartir credenciales, claves o configuraciones privadas.

## Ramas

El repositorio utiliza la siguiente organización:

```text
main
└── documentación principal

develop
└── código completo de la API
```

La rama `main` se utiliza como rama principal del repositorio.

La rama `develop` contiene la versión de desarrollo e integración del código.

Las nuevas funcionalidades se representan en:

```text
feature/nombre-funcionalidad
```

y luego integrarse en `develop`.

## Repositorios de SiGeRu

El proyecto se encuentra separado en cuatro repositorios:

### Frontend

[SiGeRu-Website](https://github.com/DeltaLogic-UTU/SiGeRu-Website)

Interfaz web desarrollada con HTML, CSS, JavaScript y Bootstrap.

### API de Usuarios

[SiGeRu-API-Usuarios](https://github.com/DeltaLogic-UTU/SiGeRu-API-Usuarios)

Responsable de autenticación, usuarios, perfiles, solicitudes de acceso y recuperación de contraseña.

### API de Gestión

[SiGeRu-API-Gestion](https://github.com/DeltaLogic-UTU/SiGeRu-API-Gestion)

Responsable de la gestión operativa del sistema.

### Base de datos

[SiGeRu-DumpSQL](https://github.com/DeltaLogic-UTU/SiGeRu-DumpSQL)

Contiene el dump SQL utilizado por el proyecto.

## Proyecto académico 2026

Proyecto desarrollado por:

**Delta-Logic**

