<?php
// header.php
session_start();
require_once __DIR__.'/config.php';
require_once __DIR__.'/functions.php';
require_once __DIR__.'/../classes/RyuFlowManager.php';

$flowManager = new RyuFlowManager($ryuIp, $ryuPort, $rulesFile);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Topología SDN en Tiempo Real</title>
<style>
/* CSS global */
body { font-family: Arial, sans-serif; }
/* ... */
</style>
<script src="https://d3js.org/d3.v5.min.js"></script>
<script>
var ryuIp = "<?php echo $ryuIp; ?>";
var ryuPort = <?php echo $ryuPort; ?>;
</script>
</head>
<body>
<h1>Topología SDN en Tiempo Real</h1>
<nav>
  <a href="index.php">Inicio</a> |
  <a href="index.php#aplicaciones">Aplicaciones</a> |
  <a href="index.php#red">Red</a> |
  <a href="index.php#reglas">Reglas</a>
</nav>
<hr>
