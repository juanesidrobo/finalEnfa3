<?php
session_start();

// Ajustar a tus rutas y llaves
$plinkPath = "C:\\Users\\juane\\Documents\\plink.exe";
$keyPath = "C:\\Users\\juane\\Documents\\enfa.ppk";
$ryuIp = '192.168.18.202';
$ryuPort = 8080;
$ryuUser = 'ryu';
$ryuHost = '192.168.18.202';
$mininetUser = 'mininet';
$mininetHost = '192.168.18.199';

function startRyuWithApplications($applications) {
    global $ryuUser, $ryuHost, $plinkPath, $keyPath;

    // Detener cualquier ryu-manager corriendo
    $stopCmd = "\"$plinkPath\" -i \"$keyPath\" $ryuUser@$ryuHost \"pkill ryu-manager\" 2>&1";
    $output = [];
    $return_var = 0;
    exec($stopCmd, $output, $return_var);
    //var_dump($output, $return_var); // Quitar el var_dump ya que no es necesario en producción

    // Construir la línea de ryu-manager con las aplicaciones seleccionadas
    // Aplicaciones base siempre:
    $baseApps = "/usr/lib/python3/dist-packages/ryu/app/rest_topology.py /usr/lib/python3/dist-packages/ryu/app/ws_topology.py /usr/lib/python3/dist-packages/ryu/app/ofctl_rest.py /usr/lib/python3/dist-packages/ryu/app/simple_monitor_13.py";
    // Agregar las aplicaciones seleccionadas
    // Se asume que $applications es un array de nombres .py
    $appsString = "";
    foreach ($applications as $app) {
        $appsString .= " /usr/lib/python3/dist-packages/ryu/app/" . escapeshellarg($app);
    }

    // Iniciar ryu-manager con las aplicaciones
    $startCmd = "\"$plinkPath\" -i \"$keyPath\" $ryuUser@$ryuHost \"nohup ryu-manager --observe-links $baseApps $appsString > /dev/null 2>&1 &\"";
    $output = [];
    $return_var = 0;
    exec($startCmd, $output, $return_var);
    //var_dump($output, $return_var);
}

function startMininetNetwork() {
    global $plinkPath, $keyPath, $mininetUser, $mininetHost, $ryuIp;

    // Limpiar redes anteriores
    $clearCmd = "\"$plinkPath\" -i \"$keyPath\" $mininetUser@$mininetHost \"sudo mn -c\"";
    exec($clearCmd);

    // Iniciar mininet y ejecutar pingall (esto generará tráfico para que Ryu aprenda hosts)
    $mnCmd = "\"$plinkPath\" -i \"$keyPath\" $mininetUser@$mininetHost \"echo -e 'pingall\\nexit' | sudo -E mn --controller=remote,ip=$ryuIp --switch=ovs --mac\"";
    exec($mnCmd);

    // Después de hacer esto, Ryu debería tener la info de la topología tras el pingall.
    $_SESSION['network_started'] = false;
}

// Procesar formularios
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['application_select'])) {
        // 'application' es un array (selección múltiple)
        $selectedApplications = $_POST['application'] ?? [];
        if (is_array($selectedApplications) && count($selectedApplications) > 0) {
            $_SESSION['current_applications'] = $selectedApplications;
            startRyuWithApplications($selectedApplications);
            // Aquí podrías reiniciar mininet si fuera necesario
            // startMininet();
        }
    }
}
// Procesar formularios
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['start_network'])) {
        startMininetNetwork();
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
}

$networkStarted = $_SESSION['network_started'] ?? false;
$currentApplications = $_SESSION['current_applications'] ?? ['simple_switch_13.py']; // Valor por defecto

?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Topología SDN en Tiempo Real</title>
<style>
    body {
        font-family: Arial, sans-serif;
    }
    #topologyContainer {
        width: 800px;
        height: 600px;
        border: 1px solid #ccc;
        margin-bottom: 20px;
        position: relative;
    }
    #topologyContainer svg {
        width: 100%;
        height: 100%;
    }
    .form-section {
        margin-bottom: 20px;
    }
    table {
        border-collapse: collapse;
    }
    table, td, th {
        border: 1px solid #ccc;
    }
    td, th {
        padding: 5px;
    }
</style>
<script src="https://d3js.org/d3.v5.min.js"></script>
<script>
var ryuIp = "<?php echo $ryuIp; ?>";
var ryuPort = <?php echo $ryuPort; ?>;
var networkStarted = <?php echo $networkStarted ? 'true' : 'false'; ?>;
var currentApps = <?php echo json_encode($currentApplications); ?>;


function loadApplications() {
            // Antes decía controllers, ahora decimos applications
            fetch('http://' + ryuIp + ':' + ryuPort + '/stats/controllers', {mode:'cors'})
                .then(res => {
                    if (!res.ok) {
                        throw new Error("No se pudo cargar la lista de aplicaciones");
                    }
                    return res.json();
                })
                .then(data => {
                    var select = document.getElementById('application');
                    select.innerHTML = '';
                    data.forEach(function(app) {
                        var opt = document.createElement('option');
                        opt.value = app;
                        opt.text = app;
                        // Si app está en currentApps, marcarlo seleccionado
                        if (currentApps.indexOf(app) !== -1) {
                            opt.selected = true;
                        }
                        select.appendChild(opt);
                    });
                })
                .catch(err => console.error("Error cargando aplicaciones:", err));
}

// Función para cargar la topología
function loadTopology() {
    Promise.all([
        fetch('http://' + ryuIp + ':' + ryuPort + '/v1.0/topology/switches', {mode:'cors'}).then(r => r.json()),
        fetch('http://' + ryuIp + ':' + ryuPort + '/v1.0/topology/hosts', {mode:'cors'}).then(r => r.json()),
        fetch('http://' + ryuIp + ':' + ryuPort + '/v1.0/topology/links', {mode:'cors'}).then(r => r.json()),
    ]).then(function([switches, hosts, links]) {
        if (switches.length === 0 && hosts.length === 0 && links.length === 0) {
            console.error("La topología está vacía. Asegúrate de haber generado tráfico (pingall).");
        }
        var graph = buildGraph(switches, hosts, links);
        drawGraph(graph);
        updateTable(switches, hosts);
    }).catch(function(err) {
        console.error("Error cargando la topología:", err);
    });
}

function buildGraph(switches, hosts, links) {
    var nodes = [];
    var linksArr = [];

    switches.forEach(function(sw) {
        nodes.push({id:'S'+parseInt(sw.dpid,16), type:'switch', dpid:sw.dpid});
    });
    hosts.forEach(function(h) {
        nodes.push({id:h.mac, type:'host', mac:h.mac});
        linksArr.push({source:h.mac, target:'S'+parseInt(h.port.dpid,16)});
    });
    links.forEach(function(l) {
        var src = 'S'+parseInt(l.src.dpid,16);
        var dst = 'S'+parseInt(l.dst.dpid,16);
        linksArr.push({source:src, target:dst});
    });

    return {nodes:nodes, links:linksArr};
}

function drawGraph(graph) {
    var container = d3.select('#topologyContainer');
    container.selectAll('*').remove();

    if (graph.nodes.length === 0) {
        console.warn("Sin nodos para dibujar. ¿Se ha generado tráfico?");
        return;
    }

    var svg = container.append('svg');
    var simulation = d3.forceSimulation(graph.nodes)
        .force('link', d3.forceLink(graph.links).id(d => d.id).distance(100))
        .force('charge', d3.forceManyBody().strength(-300))
        .force('center', d3.forceCenter(400, 300));

    var link = svg.append("g")
        .attr("stroke", "#999")
        .attr("stroke-width", 2)
        .selectAll("line")
        .data(graph.links)
        .enter().append("line");

    var node = svg.append("g")
        .attr("stroke", "#fff")
        .attr("stroke-width", 1.5)
        .selectAll("circle")
        .data(graph.nodes)
        .enter().append("circle")
        .attr("r", 15)
        .attr("fill", function(d){ return d.type==='switch'?'#012856':'#2e4b94'; })
        .call(d3.drag()
            .on("start", dragstarted)
            .on("drag", dragged)
            .on("end", dragended));

    var label = svg.append("g")
        .selectAll("text")
        .data(graph.nodes)
        .enter().append("text")
        .attr("fill", "white")
        .attr("font-size","10px")
        .attr("text-anchor", "middle")
        .attr("dy", ".3em")
        .text(function(d){ return d.id; });

    simulation.on("tick", function() {
        link
            .attr("x1", d=>d.source.x)
            .attr("y1", d=>d.source.y)
            .attr("x2", d=>d.target.x)
            .attr("y2", d=>d.target.y);

        node
            .attr("cx", d=>d.x)
            .attr("cy", d=>d.y);

        label
            .attr("x", d=>d.x)
            .attr("y", d=>d.y);
    });

    function dragstarted(event, d) {
        if (!event.active) simulation.alphaTarget(0.3).restart();
        d.fx = d.x; d.fy = d.y;
    }
    function dragged(event, d) {
        d.fx = event.x; d.fy = event.y;
    }
    function dragended(event, d) {
        if (!event.active) simulation.alphaTarget(0);
        d.fx = null; d.fy = null;
    }
}
function setupWebSocket() {
            var wsUrl = "ws://" + ryuIp + ":" + ryuPort + "/v1.0/topology/ws";
            var ws = new WebSocket(wsUrl);

            ws.onmessage = function(event) {
                var data = JSON.parse(event.data);
                if (data.method === "event_switch_enter" || data.method==="event_switch_leave" ||
                    data.method==="event_link_add" || data.method==="event_link_delete" ||
                    data.method==="event_host_add") {
                    // Refrescar topología cada vez que haya un evento
                    loadTopology();
                }
            };
        }


function updateTable(switches, hosts) {
    var tbody = document.querySelector('#topologyDetails tbody');
    tbody.innerHTML = '';

    switches.forEach(function(sw) {
        var row = document.createElement('tr');
        row.innerHTML = '<td>Switch</td><td>' + sw.dpid + '</td><td>-</td><td>-</td><td>Puertos: ' + JSON.stringify(sw.ports) + '</td>';
        tbody.appendChild(row);
    });

    hosts.forEach(function(h) {
        var ip = h.ip ? h.ip : 'Desconocida';
        var row = document.createElement('tr');
        row.innerHTML = '<td>Host</td><td>-</td><td>' + h.mac + '</td><td>' + ip + '</td><td>Conectado a DPID: ' + h.port.dpid + ' en puerto: ' + h.port.port_no + '</td>';
        tbody.appendChild(row);
    });
}

function refreshTopology() {
    if (!networkStarted) {
        alert("La red no se ha iniciado. Iníciala con el botón o hazlo manualmente en la VM de Mininet, luego pingall, y finalmente refresca.");
        return;
    }
    loadTopology();
    setupWebSocket();
    loadApplications();
}

document.addEventListener('DOMContentLoaded', function() {
    loadApplications();
    loadTopology();
    setupWebSocket();
    // Si ya iniciamos la red desde el botón o manualmente, podemos refrescar
    // Solo refrescamos cuando el usuario lo indique
});
</script>
</head>
<body>
<h1>Topología SDN en Tiempo Real</h1>

<div class="form-section">
<p>Aplicaciones actuales (según última selección): <strong><?php echo htmlspecialchars(implode(', ', $currentApplications)); ?></strong></p>
        <form method="POST">
            <label for="application">Seleccione Aplicaciones Ryu (puede seleccionar varias con Ctrl+Click):</label><br>
            <select name="application[]" id="application" multiple style="width:300px; height:100px;">
                <!-- Se llenará dinámicamente con loadApplications -->
            </select><br><br>
            <button type="submit" name="application_select">Establecer Aplicaciones (Lanza Ryu con estas apps)</button>
        </form>
        <p>Una vez seleccionadas las aplicaciones, la aplicación las lanzará en la VM de Ryu usando ryu-manager. Si necesitas cambiar la topología en Mininet, hazlo manualmente:</p>
        <pre>sudo -E mn --controller=remote,ip=<?php echo $ryuIp; ?>,port=6633 --topo tree,depth=2</pre>
    <h2>Red Mininet</h2>
    <?php if (!$networkStarted): ?>
        <p>No se ha iniciado la red. Puede:</p>
        <ul>
          <li>Presionar el botón para iniciarla vía SSH (limpiar, iniciar y pingall)</li>
          <li>O hacerlo manualmente en la VM de Mininet y luego venir aquí a refrescar la topología.</li>
        </ul>
        <form method="POST">
            <button type="submit" name="start_network">Iniciar Topología</button>
        </form>
    <?php else: ?>
        <p>La red se ha iniciado y se ejecutó pingall. Ahora puede refrescar la topología para visualizarla.</p>
        <button onclick="refreshTopology()">Refrescar Topología</button>
    <?php endif; ?>
</div>

<?php if ($networkStarted): ?>
<div class="form-section">
    <h2>Topología</h2>
    <div id="topologyContainer"></div>
</div>

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
<?php endif; ?>

<div class="form-section">
    <h2>Instrucciones</h2>
    <p>
        Puede iniciar la red desde aquí (botón Iniciar Topología) o hacerlo manualmente en la VM de Mininet:
        <pre>
sudo mn -c
sudo -E mn --controller=remote,ip=<?php echo $ryuIp; ?> --switch=ovs --mac
h1 ping h2
        </pre>
        Luego presione "Refrescar Topología" en esta página para visualizar la red y sus hosts.
    </p>
</div>
</body>
</html>
