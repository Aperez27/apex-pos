-- Datos ficticios para las capturas de los manuales (base apex_demo)
UPDATE configuracion SET nombre_empresa='Tienda Demo', nit='900.000.000-0', telefono='300 000 0000', direccion='Calle 10 # 20-30, Centro', mensaje_ticket='¡Gracias por su compra! Garantía de 30 días en productos.' WHERE id=1;
UPDATE usuarios SET nombre='Carlos Ramírez', password='{HASH}', debe_cambiar_clave=0 WHERE usuario='admin';
UPDATE usuarios SET nombre='Laura Gómez', usuario='laura', password='{HASH}', debe_cambiar_clave=0 WHERE usuario='vendedor';
INSERT INTO proveedores (nombre,contacto,telefono,email,observacion) VALUES
 ('Distribuidora iStore Mayorista','Andrés Mejía','601 555 0101','ventas@istore-demo.co','Equipos Apple nuevos y sellados: iPhone, iPad y MacBook'),
 ('Vidrios y Protectores Medellín','Sandra Ríos','604 555 0202','pedidos@vidrios-demo.co','Vidrios templados, protectores de cámara y láminas de hidrogel'),
 ('Repuestos Express','Jorge Castaño','300 555 0303','repuestos@express-demo.co','Pantallas, baterías y repuestos para reparación'),
 ('Accesorios Global','Paola Duarte','310 555 0404','ventas@global-demo.co','Forros, cargadores, cables y audífonos');
INSERT INTO productos (nombre,color,id_categoria,id_proveedor,precio_compra,precio_venta,stock) VALUES
 ('iPhone 15 Pro 256GB','Titanio natural',1,1,4100000,4799000,4),
 ('iPhone 15 Pro 256GB','Titanio negro',1,1,4100000,4799000,3),
 ('iPhone 13 128GB','Medianoche',1,1,2150000,2599000,5),
 ('iPhone 13 128GB','Blanco estelar',1,1,2150000,2599000,2),
 ('Samsung Galaxy A55 256GB','Azul',1,4,1250000,1549000,6),
 ('MacBook Air M2 13"','Gris espacial',2,1,4300000,4999000,2),
 ('iPad 10ª generación 64GB','Azul',3,1,1550000,1899000,3),
 ('Vidrio templado iPhone 15',NULL,4,2,6000,25000,60),
 ('Forro de silicona iPhone 15','Rosa',4,4,18000,49000,12),
 ('Forro de silicona iPhone 15','Negro',4,4,18000,49000,15),
 ('Cargador 20W USB-C',NULL,4,4,32000,79000,20),
 ('Cable USB-C a Lightning 1m',NULL,4,4,12000,39000,25),
 ('AirPods Pro 2ª generación',NULL,4,1,780000,999000,1),
 ('Pantalla iPhone 12 (repuesto)',NULL,5,3,190000,350000,4),
 ('Batería iPhone 11 (repuesto)',NULL,5,3,65000,150000,0);
INSERT INTO clientes (nombre,documento,telefono,email) VALUES
 ('Juan Pérez','1020304050','311 222 3344','juan.perez@correo.com'),
 ('María Rodríguez','52123456','315 444 5566','maria.r@correo.com'),
 ('Andrés Gómez','80765432','320 777 8899',''),
 ('Valentina Torres','1098765432','301 999 0011','vale.torres@correo.com'),
 ('Carlos Muñoz','71234567','318 123 4567','');
