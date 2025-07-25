# reporte
Un pequeño, sistema desarrollado, para poder machear las llamadas entrantes en issabel. de tal menera a que la persona que recibe la llamada, pueda identicar la llamada con nombre y apellido. 

Para poder utilizar el proyecto, debes ejecutar la base de datos callerid.sql, una vez creada la base de datos. 

Se debe alojar la carpeta reportes en /var/www/html/reporte

si estas usando issabel. puedes instalar issabel developer y agregar un nuevo link que puedes usar para ver lo siguiente:

https://servidor/reporte//reporte.php (Donde podras ver el reporte de las llamadas entrantes,si ya tienes registrado un numero podras verlo alli en llamadas entrantes)
https://servidor/reporte/index.php (donde podras ver y gestionar los numeros de los clientes que llamen.)


*OBS:* Se debe modificar los datos de la base en db.php y reporte.php

en issabel hay que configurar la fuente de busqueda caller id se debe usar base de datos, mysql:

en el parametro consulta: SELECT CONCAT(nombre, ' ', apellido) FROM clientes WHERE numero LIKE '%[NUMBER]%'
