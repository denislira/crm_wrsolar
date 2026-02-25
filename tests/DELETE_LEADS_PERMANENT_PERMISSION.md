# Sistema de Permissão: Excluir Leads Permanentemente

## Descripción

Este documento explica como funcionan nuevos permisos de acción granular en el CRM, específicamente la permisión **"Excluir leads permanentemente"** ("delete_leads_permanent").

## Problema Resolvido

Anteriormente, solo los administradores (role_id = 1, "Diretor") podían eliminar permanentemente los leads de la papelera. Los usuarios comunes recibían un error:

```
Lead not found or insufficient permissions to delete permanently
```

Ahora, los administradores pueden controlar quién tiene permiso para excluir leads de la basura.

## Cómo Funciona

### 1. Permiso a Nivel de Acción

El sistema de permisos ha sido extendido para incluir acciones granulares, no solo acceso a pantallas. El permiso `delete_leads_permanent` es una "acción" que complementa los permisos de pantalla existentes.

### 2. Aplicar la Migración

Hay tres formas de agregar el permiso:

#### Opción A: Script Automático (Recomendado)
1. Como administrador, accede a esta URL en tu navegador:
   ```
   http://tudominio.com/WRCRM/scripts/init_delete_leads_permanent_permission.php
   ```
2. El script confirmará que el permiso fue agregado correctamente.

#### Opción B: Migración SQL Manual
Ejecuta este SQL em tu base de datos MySQL:

```sql
INSERT INTO role_permissions (role_id, screen, allowed) 
SELECT 1, 'delete_leads_permanent', 1 
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE role_id = 1 AND screen = 'delete_leads_permanent');

INSERT INTO role_permissions (role_id, screen, allowed) 
SELECT 2, 'delete_leads_permanent', 0 
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE role_id = 2 AND screen = 'delete_leads_permanent');

INSERT INTO role_permissions (role_id, screen, allowed) 
SELECT 3, 'delete_leads_permanent', 0 
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE role_id = 3 AND screen = 'delete_leads_permanent');

INSERT INTO role_permissions (role_id, screen, allowed) 
SELECT 4, 'delete_leads_permanent', 0 
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE role_id = 4 AND screen = 'delete_leads_permanent');
```

#### Opción C: Migración SQL desde Archivo
Ejecuta el archivo de migración:
```bash
mysql -u usuario -p base_datos < database/add_delete_leads_permanent_permission.sql
```

### 3. Configurar Permisos

Después de aplicar la migración:

1. Accede a **Configurações > Permissões**
2. Selecciona el papel (papel) que deseas configurar
3. Busca por "Excluir leads", o filtra por "Acción"
4. Marca ✓ la casilla "Excluir leads permanentemente da lixeira"
5. Haz clic en **Salvar alterações**

**Estado Inicial:**
- **Diretor** (role_id=1): Habilitado ✓
- **Gerente** (role_id=2): Deshabilitado ✗
- **Supervisor** (role_id=3): Deshabilitado ✗
- **Consultor** (role_id=4): Deshabilitado ✗

Puedes cambiar esto según tus necesidades.

## Cambios Técnicos

### Archivos Modificados:

1. **includes/leads_api.php**
   - Agregó: `require_once __DIR__ . '/permissions.php';`
   - Modificó la acción `delete_permanent` para verificar `hasPermission('delete_leads_permanent')`

2. **configuracoes.php**
   - Agregó soporte para mostrar descripciones de permisos
   - Permisos de "acción" se muestran con un badge azul "Ação" para diferenciarse

3. **assets/js/leads_gestao.js**
   - Mejoró el manejo de errores en `deletePermanent()` para mostrar mensajes amigables
   - Parsea respuestas JSON y muestra mensajes de permiso más descriptivos

### Nueva Base de Datos:

- **Archivo**: `database/add_delete_leads_permanent_permission.sql`
- **Tabla afectada**: `role_permissions`
- **Columna utilizada**: `screen` (almacena nombres de permisos)

### Nuevo Script Helper:

- **Archivo**: `scripts/init_delete_leads_permanent_permission.php`
- Inicializa automáticamente el permiso para todos los papeles

## Mensajes de Error Mejorados

Cuando un usuario intenta eliminar un lead pero no tiene permiso, verá:

```
Você não tem permissão para excluir leads permanentemente. 
Peça ao administrador para autorizar esta ação em Configurações > Permissões.
```

(En lugar del mensaje genérico anterior)

## Extensibilidad

Este patrón permite agregar fácilmente nuevas permisiones de acción:

1. Agregua la permisión a la base de datos:
   ```sql
   INSERT INTO role_permissions (role_id, screen, allowed) 
   VALUES (role_id, 'nueva_accion', allowed_value);
   ```

2. Verifica el permiso en tu código:
   ```php
   if (!hasPermission('nueva_accion')) {
       throw new Exception('Permiso insuficiente');
   }
   ```

3. La UI de permissiones se actualizará automáticamente (porque consulta todas las pantallas distintas)

## Preguntas Frecuentes

### ¿Qué sucede con los usuarios existentes?
Los permisos de acceso a pantallas no se ven afectados. El nuevo permiso se agrega en paralelo.

### ¿Puedo hacer que un Consultor pueda eliminar leads?
Sí. En Configurações > Permissões, selecciona "Consultor" y marca la opción "Excluir leads permanentemente da lixeira".

### ¿Esta acción afecta a las restauraciones?
No. La restauración desde la papelera ya permite que cualquier usuario restaure sus propios leads.

### ¿Puedo tener eliminar funcionalidade con restricciones?
Actualmente, si un usuario tiene permiso `delete_leads_permanent`:
- Un **admin** puede eliminar cualquier lead de cualquier usuario
- Un **usuario común** solo puede eliminar sus propios leads

Esto se controla em el código de la API:
```php
if ($isAdmin) {
    $stmt = $pdo->prepare('DELETE FROM leads WHERE id=? AND deleted=1');
} else {
    $stmt = $pdo->prepare('DELETE FROM leads WHERE id=? AND user_id=? AND deleted=1');
}
```

## Soporte

Si hay problemas:

1. Verifica que la migración se aplicó:
   ```sql
   SELECT COUNT(*) FROM role_permissions 
   WHERE screen = 'delete_leads_permanent';
   ```
   (Debe devolver el número de papeles + 1)

2. Verifica los logs en `logs/leads_api_errors.log`

3. Verifica que el usuario está en la sesión con el `role_id` correcto:
   ```sql
   SELECT id, username, role_id FROM users WHERE id = ?;
   ```
