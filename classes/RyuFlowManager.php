<?php
// RyuFlowManager.php
class RyuFlowManager {
    private $controllerIp;
    private $controllerPort;
    private $rulesFile;

    public function __construct($ip, $port, $rulesFile) {
        $this->controllerIp = $ip;
        $this->controllerPort = $port;
        $this->rulesFile = $rulesFile;
    }

    public function sendFlowRule($dpid, $priority, $inputPort, $outputPort, $ruleType) {
        // Implementación de envío de regla
        $url = "http://{$this->controllerIp}:{$this->controllerPort}/stats/flowentry/add";
        
        $ruleConfig = [
            'dpid' => (int)$dpid,
            'table_id' => 0,
            'priority' => (int)$priority,
            'match' => [
                'in_port' => (int)$inputPort,
                'eth_type' => ($ruleType == 'permitir') ? 2048 : 2054
            ],
            'actions' => [
                [
                    'type' => 'OUTPUT',
                    'port' => (int)$outputPort
                ]
            ]
        ];

        $options = [
            'http' => [
                'method' => 'POST',
                'header' => 'Content-Type: application/json',
                'content' => json_encode($ruleConfig)
            ]
        ];

        $context = stream_context_create($options);
        $result = @file_get_contents($url, false, $context);

        if ($result !== false) {
            $this->saveRule($dpid, $priority, $inputPort, $outputPort, $ruleType);
            return true;
        }
        return false;
    }

    private function saveRule($dpid, $priority, $inputPort, $outputPort, $ruleType) {
        // Guardar en $this->rulesFile
        $rules = file_exists($this->rulesFile) 
            ? json_decode(file_get_contents($this->rulesFile), true) 
            : [];

        $rules[] = [
            'dpid' => $dpid,
            'prioridad' => $priority,
            'puerto_entrada' => $inputPort,
            'puerto_salida' => $outputPort,
            'tipo_regla' => $ruleType
        ];

        file_put_contents($this->rulesFile, json_encode($rules));
    }

    public function getEstablishedRules() {
        // Leer de $this->rulesFile
        return file_exists($this->rulesFile) 
            ? json_decode(file_get_contents($this->rulesFile), true) 
            : [];
    }

    public function fetchRulesFromController($dpid) {
        // Consultar a Ryu las reglas actuales
        $url = "http://{$this->controllerIp}:{$this->controllerPort}/stats/flow/{$dpid}";
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => 'Content-Type: application/json'
            ]
        ]);

        $result = @file_get_contents($url, false, $context);
        if ($result) {
            $data = json_decode($result, true);
            $rules = [];
            if (isset($data[$dpid])) {
                foreach ($data[$dpid] as $flow) {
                    $rules[] = [
                        'dpid' => $dpid,
                        'prioridad' => $flow['priority'],
                        'puerto_entrada' => $flow['match']['in_port'] ?? '-',
                        'puerto_salida' => isset($flow['actions'][0]) ? explode(':', $flow['actions'][0])[1] : '-',
                        'tipo_regla' => (isset($flow['match']['eth_type']) && $flow['match']['eth_type'] == 2048) ? 'IP' : 'ARP'
                    ];
                }
            }
            return $rules;
        }
        return [];
    
    }
}
