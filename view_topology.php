<?php
// Dirección IP y puerto del controlador Ryu
$ryu_ip = '192.168.80.27';
$ryu_port = 8080;

// Función para obtener datos desde las APIs de Ryu
function get_topology_data($endpoint) {
    global $ryu_ip, $ryu_port;
    $url = "http://{$ryu_ip}:{$ryu_port}{$endpoint}";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10); // Tiempo máximo de espera
    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        error_log("Error cURL: " . curl_error($ch)); // Registrar error en logs de Apache
        echo "Error al conectar con Ryu: " . curl_error($ch);
        curl_close($ch);
        return null;
    }

    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($http_code !== 200) {
        error_log("HTTP Code: {$http_code} for URL {$url}"); // Registrar código HTTP
    }

    curl_close($ch);

    if ($http_code !== 200) {
        echo "Error HTTP: Código {$http_code} al acceder a {$url}";
        return null;
    }

    return json_decode($response, true);
}

// Obtener switches y enlaces desde las APIs REST de Ryu
$switches = get_topology_data('/v1.0/topology/switches');
$links = get_topology_data('/v1.0/topology/links');
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Topología de la Red</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
        }
        h1, h2 {
            color: #333;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        th, td {
            border: 1px solid #ddd;
            text-align: center;
            padding: 8px;
        }
        th {
            background-color: #f4f4f4;
        }
    </style>
</head>
<body>
    <h1>Topología de la Red</h1>

    <!-- Mostrar Switches -->
    <h2>Switches</h2>
    <?php if ($switches): ?>
        <table>
            <thead>
                <tr>
                    <th>DPID</th>
                    <th>Puertos</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($switches as $switch): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($switch['dpid']); ?></td>
                        <td>
                            <?php foreach ($switch['ports'] as $port): ?>
                                <p>
                                    Nombre: <?php echo htmlspecialchars($port['name']); ?>,
                                    HW Addr: <?php echo htmlspecialchars($port['hw_addr']); ?>,
                                    Port No: <?php echo htmlspecialchars($port['port_no']); ?>
                                </p>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p>No se pudieron obtener los datos de los switches.</p>
    <?php endif; ?>

    <!-- Mostrar Enlaces -->
    <h2>Enlaces</h2>
    <?php if ($links): ?>
        <table>
            <thead>
                <tr>
                    <th>Switch Origen</th>
                    <th>Puerto Origen</th>
                    <th>HW Addr Origen</th>
                    <th>Switch Destino</th>
                    <th>Puerto Destino</th>
                    <th>HW Addr Destino</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($links as $link): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($link['src']['dpid']); ?></td>
                        <td><?php echo htmlspecialchars($link['src']['port_no']); ?></td>
                        <td><?php echo htmlspecialchars($link['src']['hw_addr']); ?></td>
                        <td><?php echo htmlspecialchars($link['dst']['dpid']); ?></td>
                        <td><?php echo htmlspecialchars($link['dst']['port_no']); ?></td>
                        <td><?php echo htmlspecialchars($link['dst']['hw_addr']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p>No se pudieron obtener los datos de los enlaces.</p>
    <?php endif; ?>


    <!-- Mostrar Hosts -->
    <h2>Hosts</h2>
    <?php
    // Obtener datos de los hosts
    $hosts = get_topology_data('/v1.0/topology/hosts');
    ?>
    <?php if ($hosts): ?>
        <table>
            <thead>
                <tr>
                    <th>MAC</th>
                    <th>IPv4</th>
                    <th>Switch Conectado</th>
                    <th>Puerto Conectado</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($hosts as $host): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($host['mac']); ?></td>
                        <td>
                            <?php echo implode(', ', array_map('htmlspecialchars', $host['ipv4'])); ?>
                        </td>
                        <td><?php echo htmlspecialchars($host['port']['dpid']); ?></td>
                        <td><?php echo htmlspecialchars($host['port']['name']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p>No se pudieron obtener los datos de los hosts.</p>
    <?php endif; ?>
</body>
</html>
