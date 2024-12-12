// apps.js
function loadApplications() {
    fetch('http://'+ryuIp+':'+ryuPort+'/stats/controllers', {mode:'cors'})
    .then(res => {
        if(!res.ok) throw new Error("No se pudo cargar la lista de aplicaciones");
        return res.json();
    })
    .then(data=>{
        var select = document.getElementById('application');
        if(!select) return;
        select.innerHTML='';
        data.forEach(function(app){
            var opt = document.createElement('option');
            opt.value = app;
            opt.text = app;
            if(currentApps.indexOf(app)!==-1) opt.selected=true;
            select.appendChild(opt);
        });
    })
    .catch(err=>console.error("Error cargando aplicaciones:",err));
}

function loadTopology() {
    Promise.all([
        fetch('http://'+ryuIp+':'+ryuPort+'/v1.0/topology/switches').then(r=>r.json()),
        fetch('http://'+ryuIp+':'+ryuPort+'/v1.0/topology/hosts').then(r=>r.json()),
        fetch('http://'+ryuIp+':'+ryuPort+'/v1.0/topology/links').then(r=>r.json())
    ]).then(function([switches, hosts, links]){
        switchesData = switches;
        var graph=buildGraph(switches, hosts, links);

        // Fijar posiciones del controlador y apps luego de obtener graph
        let controllerNode = graph.nodes.find(n => n.id === 'Controller');
        if (controllerNode) {
            controllerNode.fx = 400;
            controllerNode.fy = 50;
        }

        let appsNode = graph.nodes.find(n => n.id === 'Apps');
        if (appsNode) {
            appsNode.fx = 400;
            appsNode.fy = 10;
        }

        console.log('Graph generado antes de dibujar:', graph);
        drawGraph(graph);
        updateTable(switches, hosts);
        populateDPIDsForRules(switches);
    }).catch(err=>console.error("Error cargando la topología:",err));
}

function buildGraph(switches, hosts, links) {
    var nodes = [];
    var linksArr=[];
    var nodeSet=new Set();

    switches.forEach(function(sw){
        var id='S'+parseInt(sw.dpid,16);
        nodes.push({id:id,type:'switch',dpid:sw.dpid});
        nodeSet.add(id);
    });

    hosts.forEach(function(h){
        var hostId=h.mac;
        var switchId='S'+parseInt(h.port.dpid,16);
        nodes.push({id:hostId,type:'host',mac:h.mac});
        nodeSet.add(hostId);
        if(nodeSet.has(switchId)) {
            linksArr.push({source:hostId,target:switchId});
        }
    });

    links.forEach(function(l){
        var src='S'+parseInt(l.src.dpid,16);
        var dst='S'+parseInt(l.dst.dpid,16);
        if(nodeSet.has(src)&&nodeSet.has(dst)){
            linksArr.push({source:src,target:dst});
        }
    });

    // Agregar nodo Apps
    nodes.push({
        id: 'Apps',
        type: 'apps',
        appsList: currentApps
    });
    console.log("Apps cargadas:", currentApps);

    // Agregar nodo Controller
    nodes.push({
        id: 'Controller',
        type: 'controller',
        name: 'ryu-manager v4.30',
        ip: ryuIp
    });
    console.log("Controlador en IP:", ryuIp);

    // Conectar Apps -> Controller y Controller -> S1 (si existe)
    let s1Node = nodes.find(n => n.id === 'S1');
    if (s1Node) {
        linksArr.push({ source: 'Apps', target: 'Controller' });
        linksArr.push({ source: 'Controller', target: 'S1' });
    }

    return { nodes:nodes, links:linksArr };
}

function drawGraph(graph) {
    var container=d3.select('#topologyContainer');
    container.selectAll('*').remove();
    console.log("Nodos a dibujar:", graph.nodes);
    console.log("Enlaces a renderizar:", graph.links);
    if(graph.nodes.length===0) {
        console.warn("Sin nodos para dibujar.");
        return;
    }

    var svg=container.append('svg')
        .attr('width', 1000).attr('height', 800);

    var simulation=d3.forceSimulation(graph.nodes)
        .force('link',d3.forceLink(graph.links).id(d=>d.id).distance(100))
        .force('charge',d3.forceManyBody().strength(-300))
        .force('center',d3.forceCenter(400,300));

    var link=svg.append("g")
        .attr("stroke","#999").attr("stroke-width",2)
        .selectAll("line").data(graph.links).enter().append("line");

    // Asegúrate de no usar una key function distinta. Simplemente data(graph.nodes)
    var node=svg.append("g")
        .attr("stroke","#fff").attr("stroke-width",1.5)
        .selectAll("circle").data(graph.nodes) // sin key function
        .enter().append("circle")
        .attr("r", d=> d.type === 'controller' || d.type === 'apps' ? 25 : 15)
        .attr("fill", d=>{
            if(d.type==='controller') return 'orange';
            if(d.type==='apps') return 'green';
            return d.type==='switch'?'#012856':'#2e4b94';
        })
        .call(d3.drag().on("start",dragstarted).on("drag",dragged).on("end",dragended));

    node.on('mouseover', function(event, d) {
        console.log("Datos del nodo en mouseover:", d); // Debe mostrar un objeto con type
        let htmlContent = construirHTML(d);
        d3.select('#tooltip')
            .style('visibility', 'visible')
            .html(htmlContent);
    })
    .on('mousemove', function(event) {
        d3.select('#tooltip')
            .style('top', (event.pageY+10)+'px')
            .style('left', (event.pageX+10)+'px');
    })
    .on('mouseout', function() {
        d3.select('#tooltip')
            .style('visibility', 'hidden');
    });

    var label=svg.append("g")
        .selectAll("text").data(graph.nodes).enter().append("text")
        .attr("fill","black")
        .attr("font-size", d => d.type === 'controller' || d.type === 'apps' ? "14px" : "10px")
        .attr("text-anchor","middle")
        .attr("dy",".3em")
        .style("pointer-events","none")
        .text(d=>d.id);

    simulation.on("tick",function(){
        link.attr("x1",d=>d.source.x).attr("y1",d=>d.source.y).attr("x2",d=>d.target.x).attr("y2",d=>d.target.y);
        node.attr("cx",d=>d.x).attr("cy",d=>d.y);
        label.attr("x",d=>d.x).attr("y",d=>d.y);
    });

    function dragstarted(event,d){
        if(!event.active) simulation.alphaTarget(0.3).restart();
        d.fx=d.x; d.fy=d.y;
    }
    function dragged(event,d){
        d.fx=event.x; d.fy=event.y;
    }
    function dragended(event,d){
        if(!event.active) simulation.alphaTarget(0);
        d.fx=null;d.fy=null;
    }
}

function construirHTML(d) {
    console.log("Datos en construirHTML:", d);
    if (d.type === "controller") {
        return `<strong>Controlador:</strong> ${d.name}<br>IP: ${d.ip}`;
    } else if (d.type === "apps") {
        return `<strong>Aplicaciones Cargadas:</strong><br>${d.appsList.join("<br>")}`;
    } else if (d.type === "switch") {
        return `<strong>Switch DPID:</strong> ${d.dpid}`;
    } else if (d.type === "host") {
        return `<strong>Host MAC:</strong> ${d.mac}`;
    }
    return "Datos no disponibles";
}
function refreshTopology() {
    loadTopology();
    setupWebSocket();
    loadApplications();
}

function setupWebSocket() {
    var wsUrl="ws://"+ryuIp+":"+ryuPort+"/v1.0/topology/ws";
    var ws=new WebSocket(wsUrl);
    ws.onmessage=function(event){
        var data=JSON.parse(event.data);
        if(data.method==="event_switch_enter"||data.method==="event_switch_leave"||data.method==="event_link_add"||data.method==="event_link_delete"||data.method==="event_host_add"){
            loadTopology();
        }
    };
}

function updateTable(switches, hosts) {
    var tbody=document.querySelector('#topologyDetails tbody');
    tbody.innerHTML='';

    switches.forEach(function(sw){
        var row=document.createElement('tr');
        row.innerHTML='<td>Switch</td><td>'+sw.dpid+'</td><td>-</td><td>-</td><td>Puertos: '+JSON.stringify(sw.ports)+'</td>';
        tbody.appendChild(row);
    });
    hosts.forEach(function(h){
        var ip=h.ip?h.ip:'Desconocida';
        var row=document.createElement('tr');
        row.innerHTML='<td>Host</td><td>-</td><td>'+h.mac+'</td><td>'+ip+'</td><td>Conectado a DPID: '+h.port.dpid+' en puerto: '+h.port.port_no+'</td>';
        tbody.appendChild(row);
    });
}
function populateDPIDsForRules(switches) {
    // Llenar el select de dpid para enviar y consultar reglas
    var dpidSelect=document.getElementById('dpid');
    var dpidFetch=document.getElementById('dpid_fetch');
    if(!dpidSelect||!dpidFetch) return;

    dpidSelect.innerHTML='';
    dpidFetch.innerHTML='';

    switches.forEach(function(sw){
        var dpidDec=parseInt(sw.dpid,16);
        var opt=document.createElement('option');
        opt.value=dpidDec;
        opt.text='Switch DPID:'+dpidDec;
        dpidSelect.appendChild(opt);

        var opt2=document.createElement('option');
        opt2.value=dpidDec;
        opt2.text='Switch DPID:'+dpidDec;
        dpidFetch.appendChild(opt2);
    });

    // Actualizar puertos al cambiar el dpid
    dpidSelect.addEventListener('change',function(){
        updatePortsForRule(this.value);
    });

    if(switches.length>0) {
        dpidSelect.value=parseInt(switches[0].dpid,16);
        updatePortsForRule(dpidSelect.value);
    }
}
function updatePortsForRule(dpidValue) {
    var inputSelect=document.getElementById('input_port');
    var outputSelect=document.getElementById('output_port');
    if(!inputSelect||!outputSelect)return;

    inputSelect.innerHTML='';
    outputSelect.innerHTML='';

    var sw=switchesData.find(s=>parseInt(s.dpid,16)==dpidValue);
    if(!sw)return;

    sw.ports.forEach(function(p){
        var portNo=parseInt(p.port_no,16);
        var optIn=document.createElement('option');
        optIn.value=portNo;
        optIn.text='Port '+portNo;
        inputSelect.appendChild(optIn);

        var optOut=document.createElement('option');
        optOut.value=portNo;
        optOut.text='Port '+portNo;
        outputSelect.appendChild(optOut);
    });
}
