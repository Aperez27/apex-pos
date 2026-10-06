# Política de seguridad

Si encuentras una vulnerabilidad en APEX, **no abras un issue público**.
Repórtala de forma privada desde la pestaña **Security → Report a vulnerability** de este repositorio
(GitHub Private Vulnerability Reporting). Incluye los pasos para reproducirla y su posible impacto.

Recibirás respuesta en un plazo razonable y se coordinará la corrección antes de cualquier divulgación.

## Buenas prácticas para quien instala APEX

- Cambia las contraseñas iniciales (`admin` / `vendedor`); el sistema obliga a hacerlo en el primer ingreso.
- Usa un usuario de base de datos propio con contraseña (ver `deploy/crear_base_mysql.sql`).
- Publica el sistema solo por HTTPS si es accesible desde internet.
- No elimines los archivos `.htaccess` (Apache) o usa `deploy/nginx-apex.conf` (Nginx).
- Programa copias de seguridad diarias de la base de datos.
