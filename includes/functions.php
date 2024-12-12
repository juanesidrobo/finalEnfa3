<?php
// functions.php
require_once __DIR__.'/config.php';

function startRyuWithApplications($applications) {
    global $plinkPath, $keyPath, $ryuUser, $ryuHost;
    // Lógica SSH para pkill y ryu-manager
    global $ryuUser, $ryuHost, $plinkPath, $keyPath;

    // Detener cualquier ryu-manager corriendo
    $stopCmd = "\"$plinkPath\" -i \"$keyPath\" $ryuUser@$ryuHost \"pkill ryu-manager\" 2>&1";
    exec($stopCmd);

    // Aplicaciones base
    $baseApps = "/usr/lib/python3/dist-packages/ryu/app/rest_topology.py /usr/lib/python3/dist-packages/ryu/app/ws_topology.py /usr/lib/python3/dist-packages/ryu/app/ofctl_rest.py";

    // Construir la lista de aplicaciones
    $appsString = "";
    foreach ($applications as $app) {
        $appsString .= " /usr/lib/python3/dist-packages/ryu/app/" . escapeshellarg($app);
    }

    $startCmd = "\"$plinkPath\" -i \"$keyPath\" $ryuUser@$ryuHost \"nohup ryu-manager --observe-links $baseApps $appsString > /var/log/ryu_apps.log 2>&1 &\"";
    exec($startCmd);
}

function startMininetNetwork() {
    global $plinkPath, $keyPath, $mininetUser, $mininetHost, $ryuIp;
    // Lógica SSH para mn -c, luego iniciar mn y pingall
    global $plinkPath, $keyPath, $mininetUser, $mininetHost, $ryuIp;

    // Limpiar redes anteriores
    $clearCmd = "\"$plinkPath\" -i \"$keyPath\" $mininetUser@$mininetHost \"sudo mn -c\"";
    exec($clearCmd);

    // Iniciar mininet y ejecutar pingall
    $mnCmd = "\"$plinkPath\" -i \"$keyPath\" $mininetUser@$mininetHost \"echo -e 'pingall\\nexit' | sudo -E mn --controller=remote,ip=$ryuIp --switch=ovs --mac\"";
    exec($mnCmd);

    $_SESSION['network_started'] = true;
}

function viewLog() {
    global $plinkPath, $keyPath, $ryuUser, $ryuHost;
    // Ejecuta: cat /var/log/ryu_apps.log en la VM Ryu

    // Construir el comando SSH
    $logCmd = "\"$plinkPath\" -i \"$keyPath\" $ryuUser@$ryuHost \"cat /var/log/ryu_apps.log\"";

    $output = [];
    $return_var = 0;
    exec($logCmd, $output, $return_var);

    if ($return_var === 0) {
        // $output es un array de líneas del log
        return implode("\n", $output);
    } else {
        // Si falla, retornar cadena vacía o mensaje de error
        return '';
    }
}

function clearLog() {
    global $plinkPath, $keyPath, $ryuUser, $ryuHost;
    // Ejecuta: echo '' > /var/log/ryu_apps.log en la VM Ryu

    $clearLogCmd = "\"$plinkPath\" -i \"$keyPath\" $ryuUser@$ryuHost \"echo '' > /var/log/ryu_apps.log\"";
    $output = [];
    $return_var = 0;
    exec($clearLogCmd, $output, $return_var);

    // No se necesita retornar nada. Si se quisiera, se podría comprobar $return_var para ver si funcionó.
}