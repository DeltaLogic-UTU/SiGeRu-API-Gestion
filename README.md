# SiGeRu — API de Gestión

**Sistema de Gestión de Residuos Urbanos | Delta-Logic — 2026**

La API de Gestión es uno de los componentes backend de **SiGeRu**, desarrollado con PHP y Laravel.

Su función es centralizar la administración de los recursos y procesos operativos relacionados con la gestión de residuos urbanos.

Permite administrar contenedores, circuitos, rutas, camiones, cuadrillas, instalaciones, inventarios, reclamos, incidencias, recolecciones y estadísticas.

Además, incorpora funcionalidades de seguimiento GPS, planificación de recorridos, comunicación interna y consulta de información operativa.

La API es consumida principalmente por [SiGeRu-Website](https://github.com/DeltaLogic-UTU/SiGeRu-Website) y trabaja en conjunto con las demás APIs del proyecto.

## 1. Estado del proyecto

SiGeRu-API-Gestion dispone de módulos conectados a una base de datos MariaDB/MySQL y proporciona respuestas JSON mediante endpoints HTTP.

El sistema incorpora funcionalidades para:

- Administración de recursos operativos.
- Gestión de personal y asignaciones.
- Planificación y despacho de recorridos.
- Seguimiento de recolecciones.
- Atención de reclamos e incidencias.
- Administración de instalaciones e inventarios.
- Consulta de estadísticas.
- Mensajería interna.
- Notificaciones a vecinos.

La API forma parte de un proyecto académico y continúa su proceso de pruebas, documentación y validación.

## 2. Tecnologías utilizadas

| Tecnología | Función |
|---|---|
| PHP | Lenguaje del backend |
| Laravel | Framework de desarrollo |
| Composer | Gestión de dependencias |
| MariaDB/MySQL | Base de datos relacional |
| JWT | Autenticación |
| API REST | Comunicación mediante HTTP |
| JSON | Intercambio de información |
| Eloquent y Query Builder | Consultas a la base de datos |
| Git y GitHub | Control de versiones |
| XAMPP | Entorno de desarrollo local |

## 3. Organización del proyecto

```text
SiGeRu-API-Gestion/
├── app/
│   ├── Http/
│   │   └── Controllers/
│   │       ├── CamionController.php
│   │       ├── ContenedorController.php
│   │       ├── CuadrillaController.php
│   │       ├── CircuitoController.php
│   │       ├── IncidenciaController.php
│   │       ├── InstalacionController.php
│   │       ├── ReclamoController.php
│   │       ├── RutaController.php
│   │       ├── RecoleccionController.php
│   │       ├── PanelCuadrillaController.php
│   │       ├── EstadisticaController.php
│   │       ├── MensajeInternoController.php
│   │       └── NotificacionVecinoController.php
│   ├── Models/
│   └── Services/
│
├── bootstrap/
├── config/
├── database/
│   ├── migrations/
│   └── seeders/
├── public/
├── resources/
├── routes/
│   ├── api.php
│   └── web.php
├── storage/
├── tests/
├── .env.example
├── .gitignore
├── artisan
├── composer.json
├── composer.lock
└── README.md
```

Los controladores procesan las solicitudes HTTP y gestionan las operaciones con la base de datos.

Los servicios permiten separar procesos más complejos, como la lógica de despacho automático de rutas.

## 4. Autenticación y seguridad

Las operaciones protegidas utilizan autenticación JWT mediante el middleware de Laravel:

```php
Route::middleware('auth:api')->group(function () {
    // Rutas protegidas de la API
});
```

El cliente debe enviar un token válido:

```http
Authorization: Bearer TU_TOKEN_JWT
Accept: application/json
```

### Funcionamiento

1. El usuario inicia sesión mediante la API de Usuarios.
2. El frontend obtiene su token JWT.
3. El token se incorpora a las solicitudes protegidas.
4. La API de Gestión verifica la autenticación.
5. El controlador procesa la operación según las validaciones y permisos implementados.

Los roles contemplados por el sistema incluyen:

- Administrador.
- Supervisor.
- Vecino.
- Cuadrilla.
- Barrendero.
- Operario.
- Chofer.

Los permisos deben verificarse también en el backend, especialmente en las operaciones administrativas.

### JWT y CSRF

Las rutas ubicadas bajo `routes/api.php` utilizan autenticación mediante token.

No debe confundirse este mecanismo con las sesiones de navegador y la protección CSRF de Laravel, que corresponden a las rutas web cuando utilizan ese tipo de autenticación.

## 5. Gestión de contenedores

Este módulo administra los contenedores registrados en SiGeRu.

Permite:

- Consultar contenedores.
- Registrar nuevos contenedores.
- Modificar información.
- Asignar circuitos.
- Actualizar ubicaciones.
- Incorporar fotografías.
- Consultar estados.
- Desactivar y reactivar contenedores.
- Registrar reemplazos.
- Consultar historiales.

### Información almacenada

Cada contenedor puede incluir:

- Identificador.
- Modelo.
- Tipo de residuo.
- Capacidad.
- Estado.
- Circuito.
- Municipio.
- Ubicación geográfica.
- Fotografía.
- Fecha de última recolección.
- Días sin recolección.

### Prioridad operativa

El sistema utiliza indicadores visuales según el tiempo transcurrido desde la última recolección.

Los criterios contemplan:

- Verde: hasta 2 días.
- Amarillo: entre 3 y 4 días.
- Rojo: 6 días o más.

La clasificación de los valores intermedios debe corresponder a la configuración definitiva del módulo.

Esta información contribuye a la planificación de recorridos y al seguimiento de contenedores pendientes.

## 6. Circuitos y mapa

La API administra los circuitos asociados a los contenedores y recorridos.

Entre sus funciones se encuentran:

- Consulta de circuitos.
- Registro de circuitos.
- Actualización de información.
- Consulta de coordenadas geográficas.
- Asociación de contenedores.
- Resúmenes por circuito.
- Integración con los mapas del frontend.

Las consultas geográficas permiten visualizar la distribución de los recursos en el territorio.

El sistema utiliza los datos almacenados para presentar marcadores, ubicaciones y delimitaciones de circuitos.

## 7. Gestión de rutas

El módulo administra la planificación de rutas de recolección.

Permite:

- Consultar rutas.
- Obtener información del circuito asociado.
- Configurar frecuencias.
- Consultar la última recolección.
- Estimar próximas fechas de recolección.
- Identificar rutas pendientes o vencidas.
- Consultar historiales.
- Ejecutar procesos de despacho.

### Frecuencia de recolección

Cada ruta puede disponer de una frecuencia expresada en días.

Por ejemplo:

```text
Cada 3 días
```

La frecuencia se utiliza como criterio para determinar la planificación de los recorridos.

### Despacho automático

La API incorpora un servicio para la asignación automática de rutas.

El proceso tiene en cuenta las condiciones configuradas para la planificación, como:

- Rutas pendientes.
- Frecuencia de recolección.
- Disponibilidad de camiones.
- Disponibilidad de choferes.
- Capacidad operativa y cupos de despacho.

Los despachos se registran para permitir su consulta y seguimiento.

La operación de despacho automático dispone de una comprobación específica del rol administrador en las rutas revisadas.

## 8. Gestión de camiones

El módulo permite consultar y administrar la flota de vehículos.

Funciones principales:

- Registrar camiones.
- Consultar información.
- Actualizar fotografías.
- Asignar choferes.
- Asociar cuadrillas.
- Administrar rutas asignadas.
- Actualizar estados operativos.
- Registrar posiciones GPS.
- Consultar información relacionada con recolecciones.

### Seguimiento GPS

La API contempla actualizaciones de ubicación de camiones.

Los datos geográficos permiten representar posiciones y recorridos desde las interfaces de Gestión y Chofer.

La disponibilidad del seguimiento depende del envío de coordenadas desde los clientes correspondientes.

## 9. Gestión de recolecciones

Este módulo registra y administra los recorridos de recolección.

Incluye:

- Consulta de recolecciones.
- Inicio de recorridos.
- Registro de contenedores recolectados.
- Seguimiento del progreso.
- Finalización de recorridos.
- Consulta del historial.
- Información sobre distancias recorridas.
- Relación con rutas, camiones y personal.

### Proceso general

1. Se identifica una ruta asignada.
2. Se inicia la recolección.
3. Se registran los contenedores recolectados.
4. Se actualizan los datos del recorrido.
5. Se finaliza la recolección.
6. Se conserva la información para consultas posteriores.

La API de Gestión contiene endpoints utilizados por los módulos de recolección y chofer.

La API de Recolección constituye otro componente del proyecto, con responsabilidades propias.

## 10. Gestión de cuadrillas

La API permite administrar las cuadrillas encargadas de tareas operativas.

Funciones disponibles:

- Registrar cuadrillas.
- Actualizar sus datos.
- Consultar responsables.
- Gestionar integrantes.
- Asignar circuitos y rutas.
- Modificar asignaciones.
- Consultar historiales.
- Relacionar reclamos e incidencias.

### Panel de Cuadrilla

La API proporciona información para la interfaz del personal de cuadrilla.

El panel permite consultar:

- Cuadrilla asignada.
- Circuitos y rutas.
- Reclamos pendientes.
- Incidencias.
- Camiones relacionados.
- Datos de ubicación disponibles.

También se incorporaron operaciones para registrar la resolución de reclamos e incidencias asignados.

El objetivo es mantener coordinadas las actividades del personal operativo con la información administrativa.

## 11. Gestión de instalaciones

Este módulo permite administrar instalaciones relacionadas con el sistema de residuos.

Tipos contemplados:

- Depósitos.
- Centros de acopio.
- Vertederos.

Las instalaciones pueden almacenar:

- Nombre.
- Tipo.
- Ubicación.
- Estado operativo.
- Capacidad.
- Tipo de residuo.
- Información adicional.

### Inventario

La API incorpora operaciones para consultar y administrar los artículos de inventario asociados a instalaciones.

Permite:

- Consultar artículos.
- Agregar registros.
- Actualizar información.
- Eliminar registros.
- Consultar cantidades y categorías.

### Operarios

Las instalaciones pueden disponer de operarios asignados.

La API permite administrar esas asignaciones y consultar información operativa.

El frontend del Operario utiliza una consulta autenticada para obtener los datos de la instalación que le corresponde, junto con la información disponible de inventario.

## 12. Gestión de reclamos

Los reclamos representan situaciones reportadas por los vecinos.

Estados principales:

- Sin asignar.
- Asignado.
- Completado.

La API permite:

1. Consultar reclamos.
2. Registrar reclamos.
3. Consultar detalles.
4. Asignar una cuadrilla.
5. Quitar asignaciones.
6. Registrar resultados.
7. Marcar reclamos como completados.
8. Reabrir reclamos.
9. Gestionar las notificaciones relacionadas.

Los reclamos pueden incluir fotografías e información sobre el tipo de problema reportado.

## 13. Gestión de incidencias

Las incidencias permiten registrar problemas relacionados con los recursos del sistema.

Pueden corresponder a:

- Camiones.
- Contenedores.
- Actividades operativas.

Estados utilizados:

- Abierta.
- En curso.
- Resuelta.

La API permite registrar, consultar, actualizar y resolver incidencias según las operaciones disponibles para cada perfil.

## 14. Notificaciones a vecinos

El sistema incorpora notificaciones relacionadas con los reclamos.

Cuando se registra un cambio de estado, la información puede almacenarse para su consulta desde la interfaz del vecino.

Las notificaciones contemplan:

- Usuario destinatario.
- Reclamo relacionado.
- Estado anterior.
- Estado nuevo.
- Fecha del cambio.
- Información de lectura, cuando corresponda.

El sistema utiliza registros de base de datos para conservar la información de los cambios producidos.

## 15. Mensajería interna

La API dispone de un módulo de comunicación entre Administración y personal operativo.

Los perfiles contemplados en el módulo incluyen:

- Administradores.
- Cuadrillas.
- Barrenderos.
- Operarios.

### Funciones

- Consultar contactos autorizados.
- Obtener conversaciones.
- Enviar mensajes.
- Consultar el historial.
- Marcar mensajes como leídos.
- Identificar mensajes pendientes.
- Contabilizar mensajes sin leer.

La información se almacena en la base de datos para mantener el historial de comunicación.

En el panel administrativo, los mensajes pendientes pueden mostrarse mediante indicadores visuales y contadores.

## 16. Estadísticas del sistema

La API incorpora un controlador de estadísticas que obtiene indicadores a partir de los datos almacenados.

Entre las estadísticas desarrolladas se encuentran:

- Reclamos recibidos.
- Reclamos pendientes.
- Reclamos completados.
- Distribución de reclamos por tipo.
- Distribución de reclamos por estado.
- Evolución por período.
- Contenedores con mayor cantidad de reportes.
- Incidencias registradas.
- Información de camiones.
- Información de cuadrillas.
- Distribución de usuarios por rol.

El módulo permite consultar información por período y proporciona los datos necesarios para generar gráficos y resúmenes desde el frontend.

Las estadísticas permiten analizar el funcionamiento del sistema sin limitarse a mostrar listados de registros individuales.

## 17. Rutas principales de la API

La mayoría de las rutas operativas se encuentran definidas en:

```text
routes/api.php
```

La dirección local es:

```text
http://127.0.0.1:8001
```

### Estado de la API

```http
GET /api/health
```

### Mapas públicos

```http
GET /api/mapa/contenedores
GET /api/mapa/circuitos
```

### Contenedores

```http
GET    /api/contenedores
GET    /api/contenedores/catalogos
GET    /api/contenedores/resumen-circuitos
POST   /api/contenedores
GET    /api/contenedores/{id}
PUT    /api/contenedores/{id}
PUT    /api/contenedores/{id}/ubicacion
DELETE /api/contenedores/{id}
POST   /api/contenedores/{id}/reactivar
POST   /api/contenedores/{id}/reemplazar
GET    /api/contenedores/{id}/historial
```

### Circuitos

```http
GET  /api/circuitos
POST /api/circuitos
GET  /api/circuitos/{id}
PUT  /api/circuitos/{id}
```

### Rutas

```http
GET   /api/rutas
GET   /api/rutas/historial
PUT   /api/rutas/{id}/habitual
PATCH /api/rutas/{id}/frecuencia
POST  /api/rutas/despachar-automaticamente
```

### Recolecciones

```http
GET  /api/recolecciones
POST /api/recolecciones/iniciar
POST /api/recolecciones/{id}/contenedor
POST /api/recolecciones/{id}/finalizar
```

### Camiones

```http
GET /api/camiones
GET /api/camiones/catalogos
GET /api/camiones/{id}
PUT /api/camiones/{id}/foto
PUT /api/camiones/{id}/gps
```

### Cuadrillas

```http
GET    /api/cuadrillas
POST   /api/cuadrillas
PUT    /api/cuadrillas/{id}
DELETE /api/cuadrillas/{id}

GET  /api/cuadrillas/{id}/integrantes
POST /api/cuadrillas/{id}/integrantes
GET  /api/cuadrillas/{id}/rutas
POST /api/cuadrillas/{id}/rutas

GET /api/cuadrilla/mi-panel
```

### Instalaciones e inventario

```http
GET    /api/instalaciones
POST   /api/instalaciones
PUT    /api/instalaciones/{id}
DELETE /api/instalaciones/{id}

GET    /api/instalaciones/{id}/inventario
POST   /api/instalaciones/{id}/inventario
PUT    /api/instalaciones/{id}/inventario/{idInventario}
DELETE /api/instalaciones/{id}/inventario/{idInventario}
```

### Reclamos

```http
GET /api/reclamos
POST /api/reclamos
GET /api/reclamos/{id}

PUT /api/reclamos/{id}/asignar
PUT /api/reclamos/{id}/quitar-asignacion
PUT /api/reclamos/{id}/completar
PUT /api/reclamos/{id}/reabrir
PUT /api/reclamos/{id}/resultado
PUT /api/reclamos/{id}/notificar
```

### Mensajería interna

```http
GET /api/mensajes/contactos
GET /api/mensajes/conversacion/{idContacto}
POST /api/mensajes/enviar
PUT /api/mensajes/conversacion/{idContacto}/leidos
```

### Estadísticas

```http
GET /api/estadisticas
```

**Nota:** estos ejemplos corresponden a rutas utilizadas durante el desarrollo. Para consultar la lista exacta y vigente de la instalación:

```powershell
php artisan route:list --path=api
```

Este comando muestra los métodos HTTP, las direcciones y los controladores registrados.

## 18. Base de datos

La API utiliza MariaDB/MySQL.

Nombre de la base:

```text
sigeru
```

La estructura del sistema se encuentra en:

[SiGeRu-DumpSQL](https://github.com/DeltaLogic-UTU/SiGeRu-DumpSQL)

La base incluye información relacionada con:

- Usuarios y roles.
- Camiones.
- Contenedores.
- Circuitos.
- Rutas.
- Despachos.
- Recolecciones.
- Cuadrillas.
- Instalaciones.
- Inventarios.
- Reclamos.
- Incidencias.
- Mensajes internos.
- Notificaciones.

Los diferentes módulos comparten relaciones entre sus registros.

La estructura y los datos deben mantenerse sincronizados con las versiones de las APIs.

## 19. Configuración del entorno

La API utiliza un archivo `.env` para configurar sus servicios.

### Crear el archivo

En PowerShell:

```powershell
Copy-Item .env.example .env
```

### Configuración local

```dotenv
APP_NAME="SiGeRu"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://127.0.0.1:8001

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=sigeru
DB_USERNAME=root
DB_PASSWORD=

SESSION_DRIVER=file
SESSION_COOKIE=sigeru_gestion_session
SESSION_LIFETIME=120
```

Estos valores corresponden a un entorno local de desarrollo con XAMPP.

Se deben adaptar cuando se utilizan otras credenciales de MySQL o una configuración diferente.

### Clave de Laravel

Ejecutar:

```powershell
php artisan key:generate
```

### Configuración JWT

La API utiliza JWT para autenticar las solicitudes.

Es necesario completar las variables y secretos correspondientes al sistema de autenticación utilizado.

Si se trabaja con varias APIs que validan los mismos tokens, sus configuraciones deben ser compatibles con el mecanismo de firma y verificación establecido.

### Limpiar caché de configuración

Después de modificar el archivo `.env`:

```powershell
php artisan config:clear
```

**Importante:** no subir a GitHub archivos `.env`, contraseñas, claves JWT ni credenciales privadas.

## 20. Ejecución local

### Requisitos

- PHP compatible con Laravel.
- Composer.
- MySQL o MariaDB.
- XAMPP o entorno equivalente.
- Git.

### Paso 1. Clonar el repositorio

```powershell
git clone https://github.com/DeltaLogic-UTU/SiGeRu-API-Gestion.git

cd SiGeRu-API-Gestion
```

La rama `main` se utiliza para publicar la versión principal del proyecto. La rama `develop` puede emplearse para integrar cambios de desarrollo.

### Paso 2. Instalar dependencias

```powershell
composer install
```

### Paso 3. Configurar Laravel

```powershell
Copy-Item .env.example .env

php artisan key:generate
```

Completar las variables necesarias en `.env`.

### Paso 4. Preparar la base de datos

Iniciar MySQL desde XAMPP.

Crear la base si todavía no existe:

```sql
CREATE DATABASE IF NOT EXISTS sigeru
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;
```

Importar el respaldo SQL correspondiente desde `SiGeRu-DumpSQL`.

No importar un respaldo sobre una base con datos importantes sin realizar previamente una copia de seguridad.

### Paso 5. Iniciar la API

```powershell
php artisan serve --host=127.0.0.1 --port=8001
```

El servicio estará disponible en:

```text
http://127.0.0.1:8001
```

### Paso 6. Comprobar funcionamiento

Abrir:

```text
http://127.0.0.1:8001/api/health
```

La respuesta esperada de la ruta de comprobación es similar a:

```json
{
  "success": true,
  "message": "API Gestion running"
}
```

También se recomienda verificar:

- Conexión a MySQL.
- Autenticación JWT.
- Consulta de contenedores.
- Consulta de circuitos.
- Gestión de camiones.
- Gestión de rutas.
- Recolecciones.
- Estadísticas.
- Mensajería interna.

## 21. Integración con el frontend

El frontend utiliza JavaScript y `fetch` para consumir los endpoints.

La URL utilizada durante el desarrollo es:

```javascript
const API_GESTION = 'http://127.0.0.1:8001';
```

Las solicitudes protegidas deben incorporar el token JWT correspondiente.

Ejemplo:

```javascript
fetch(`${API_GESTION}/api/rutas`, {
  headers: {
    Accept: 'application/json',
    Authorization: `Bearer ${token}`
  }
});
```

En este ejemplo, `token` representa el JWT obtenido previamente mediante la autenticación del usuario.

La API devuelve respuestas JSON para que el frontend pueda actualizar tablas, mapas, gráficos y formularios.

## 22. Integración con otras APIs

SiGeRu utiliza distintos servicios que trabajan de manera coordinada.

### API de Usuarios

Responsable de:

- Autenticación.
- Usuarios y perfiles.
- Solicitudes de acceso.
- Recuperación de contraseña.
- Gestión de credenciales.

Puerto local:

```text
8000
```

### API de Gestión

Responsable de:

- Administración operativa.
- Recursos.
- Rutas y asignaciones.
- Recolecciones gestionadas desde sus endpoints.
- Estadísticas.
- Mensajería.
- Reclamos e incidencias.

Puerto local:

```text
8001
```

### API de Recolección

Componente dedicado a los procesos de recolección y sus funcionalidades específicas.

Puerto local:

```text
8002
```

Las tres APIs requieren una configuración de base de datos y autenticación compatible para funcionar de forma integrada.

## 23. Pruebas y validaciones

El proyecto contempla pruebas funcionales y de integración.

Entre los procesos que deben verificarse se encuentran:

- Acceso autenticado.
- Restricciones por rol.
- CRUD de contenedores.
- Actualización de ubicaciones.
- Administración de circuitos.
- Asignación de cuadrillas.
- Asignación de camiones.
- Planificación de rutas.
- Despacho automático.
- Inicio y finalización de recolecciones.
- Registro de reclamos.
- Resolución de incidencias.
- Actualización de inventarios.
- Mensajería interna.
- Consulta de estadísticas.
- Integración con MySQL.

Los resultados deben documentarse para identificar errores y verificar el comportamiento de cada módulo.

## 24. Seguridad y mantenimiento

Antes de desplegar el sistema en un entorno de producción se recomienda:

- Verificar permisos en todos los endpoints administrativos.
- Mantener JWT y claves privadas fuera del repositorio.
- Limitar correctamente los orígenes CORS.
- Validar todos los datos recibidos.
- Proteger las operaciones de escritura.
- Revisar los permisos de acceso a mensajes y datos personales.
- Desactivar `APP_DEBUG` en producción.
- Mantener actualizadas las dependencias.
- Revisar los registros de errores.
- Realizar respaldos periódicos.
- Implementar pruebas automatizadas.

La autenticación JWT comprueba la identidad del usuario, pero no reemplaza las verificaciones de permisos por rol.

## 25. Repositorios del proyecto

| Repositorio | Descripción |
|---|---|
| [SiGeRu-Website](https://github.com/DeltaLogic-UTU/SiGeRu-Website) | Frontend del sistema |
| [SiGeRu-API-Usuarios](https://github.com/DeltaLogic-UTU/SiGeRu-API-Usuarios) | Autenticación y usuarios |
| [SiGeRu-API-Gestion](https://github.com/DeltaLogic-UTU/SiGeRu-API-Gestion) | Gestión operativa |
| [SiGeRu-API-Recoleccion](https://github.com/DeltaLogic-UTU/SiGeRu-API-Recoleccion) | Procesos de recolección |
| [SiGeRu-DumpSQL](https://github.com/DeltaLogic-UTU/SiGeRu-DumpSQL) | Base de datos |

---

## Proyecto académico 2026

**SiGeRu — Sistema de Gestión de Residuos Urbanos**

**Delta-Logic**.
