<?php
// index.php
require_once __DIR__.'/../includes/header.php';

// Procesar formularios aquí si no lo hiciste en functions.php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Dependiendo de lo enviado, llamar a las funciones startRyuWithApplications, startMininetNetwork, viewLog, clearLog, etc.
    if (isset($_POST['application_select'])) {
        $selectedApplications = $_POST['application'] ?? [];
        if (count($selectedApplications)>0) {
            $_SESSION['current_applications'] = $selectedApplications;
            startRyuWithApplications($selectedApplications);
        }
    }
    if (isset($_POST['start_network'])) {
        startMininetNetwork();
    }
    if (isset($_POST['view_log'])) {
        $_SESSION['ryu_log'] = viewLog();
    }
    if (isset($_POST['clear_log'])) {
        clearLog();
        unset($_SESSION['ryu_log']);
    }
    if (isset($_POST['send_rule'])) {
        $dpid = $_POST['dpid'] ?? null;
        $priority = $_POST['priority'] ?? null;
        $inputPort = $_POST['input_port'] ?? null;
        $outputPort = $_POST['output_port'] ?? null;
        $ruleType = $_POST['rule_type'] ?? null;
        if($dpid && $priority && $inputPort && $outputPort && $ruleType) {
            $flowManager->sendFlowRule($dpid, $priority, $inputPort, $outputPort, $ruleType);
        }
    }
    if (isset($_POST['fetch_rules'])) {
        $dpid_fetch = $_POST['dpid_fetch'] ?? null;
        if($dpid_fetch) {
            $rules = $flowManager->fetchRulesFromController($dpid_fetch);
        }
    }
} else {
    $rules = $flowManager->getEstablishedRules();
}

$rules = $rules ?? [];
$ryuLog = $_SESSION['ryu_log'] ?? '';
$lines = explode("\n", $ryuLog);
$processedLines = [];
foreach ($lines as $line) {
    if (preg_match('/loading app .*\/app\/([^ ]+)$/', $line, $matches)) {
        $appName = $matches[1];
        $processedLines[] = "Aplicación cargada: " . $appName;
    }
}

$networkStarted = $_SESSION['network_started'] ?? false;
$currentApplications = $_SESSION['current_applications'] ?? ['simple_switch_13.py'];

?>
<script>
var ryuIp = "<?php echo $ryuIp; ?>";
var ryuPort = <?php echo $ryuPort; ?>;
var currentApps = <?php echo json_encode($currentApplications); ?>;
var switchesData = [];
</script>
<script src="../includes/apps.js"></script>
<!-- HTML para mostrar formularios, topología, reglas, etc. -->
<button onclick="refreshTopology()">Refrescar Topología</button>
<div id="topologyContainer"></div>
<div id="tooltip" style="position: absolute; visibility: hidden; background: #f9f9f9; border: 1px solid #ccc; padding: 10px; border-radius: 4px; font-size: 12px; box-shadow: 0px 0px 5px rgba(0,0,0,0.2);"></div>

<h2 id="aplicaciones">Aplicaciones Ryu</h2>
<form method="POST">
    <label>Seleccione Aplicaciones Ryu:</label><br>
    <select name="application[]" id="application" multiple style="width:300px; height:100px;"></select><br><br>
    <button type="submit" name="application_select">Establecer Aplicaciones</button>
</form>

<h2 id="red">Iniciar Red Mininet</h2>
<form method="POST">
    <button type="submit" name="start_network">Iniciar Topología</button>
</form>

<h2>Aplicaciones Ejecutadas en Ryu</h2>
<form method="POST">
    <button type="submit" name="view_log">Ver Aplicaciones Cargadas</button>
</form>
<?php if (!empty($processedLines)): ?>
<h3>Log de Ryu:</h3>
<pre><?php 
foreach($processedLines as $pl) {
    echo htmlspecialchars($pl)."\n";
}
?></pre>
<?php endif; ?>
<form method="POST">
    <button type="submit" name="clear_log">Limpiar Log</button>
</form>

<h2>Detalles de la Topología</h2>
<table id="topologyDetails">
    <thead>
        <tr>
            <th>Tipo</th>
            <th>ID/DPID</th>
            <th>MAC</th>
            <th>IP</th>
            <th>Conexión</th>
        </tr>
    </thead>
    <tbody></tbody>
</table>

<h2 id="reglas">Establecer Reglas</h2>
<form method="POST">
    <label for="dpid">DPID (Switch):</label>
    <select id="dpid" name="dpid"></select><br><br>

    <label for="priority">PRIORIDAD:</label>
    <input type="number" id="priority" name="priority" required><br><br>

    <label for="input_port">PUERTO ENTRADA:</label>
    <select id="input_port" name="input_port"></select><br><br>

    <label for="output_port">PUERTO SALIDA:</label>
    <select id="output_port" name="output_port"></select><br><br>

    <label for="rule_type">TIPO DE REGLA:</label>
    <select id="rule_type" name="rule_type">
        <option value="permitir">IP</option>
        <option value="denegar">ARP</option>
    </select><br><br>

    <button type="submit" name="send_rule">ENVIAR REGLA</button>
</form>

<h2>Consultar Reglas</h2>
<form method="POST">
    <label for="dpid_fetch">DPID (Switch) a consultar:</label>
    <select id="dpid_fetch" name="dpid_fetch"></select><br><br>
    <button type="submit" name="fetch_rules">CONSULTAR REGLAS</button>
</form>

<h2>Reglas Establecidas</h2>
<table border="1">
    <thead>
        <tr>
            <th>DPID</th>
            <th>PRIORIDAD</th>
            <th>PUERTO ENTRADA</th>
            <th>PUERTO SALIDA</th>
            <th>TIPO REGLA</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($rules as $rule): ?>
        <tr>
            <td><?php echo htmlspecialchars($rule['dpid'] ?? '-'); ?></td>
            <td><?php echo htmlspecialchars($rule['prioridad']); ?></td>
            <td><?php echo htmlspecialchars($rule['puerto_entrada']); ?></td>
            <td><?php echo htmlspecialchars($rule['puerto_salida']); ?></td>
            <td><?php echo htmlspecialchars($rule['tipo_regla']); ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<div class="form-section">
    <h2>Instrucciones</h2>
    <p>Iniciar red, hacer pingall, refrescar topología, elegir switch/puertos y establecer reglas. Consultar reglas para verificar.</p>
</div>
</body> </html> 