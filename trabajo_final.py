from ryu.base import app_manager
from ryu.controller import ofp_event
from ryu.controller.handler import CONFIG_DISPATCHER, MAIN_DISPATCHER
from ryu.controller.handler import set_ev_cls
from ryu.ofproto import ofproto_v1_3
from ryu.lib.packet import packet
from ryu.lib.packet import ethernet
from ryu.lib.packet import ether_types
from ryu.lib.packet import ipv4, arp, icmp, tcp, udp
import networkx as nx
from calculo_shortestpath import create_nfsnet_graph, calculate_shortest_paths

class SimpleSwitch13(app_manager.RyuApp):
    OFP_VERSIONS = [ofproto_v1_3.OFP_VERSION]

    def __init__(self, *args, **kwargs):
        super(SimpleSwitch13, self).__init__(*args, **kwargs)

        self.network = create_nfsnet_graph()
        self.paths = calculate_shortest_paths(self.network)
        #DICCIONARIOS
        self.mac_to_port = {}
        self.hw_addr = { "10.0.0.1": "00:00:00:00:00:01",
                         "10.0.0.2": "00:00:00:00:00:02",
                         "10.0.0.3": "00:00:00:00:00:03",
                         "10.0.0.4": "00:00:00:00:00:04",
                         "10.0.0.5": "00:00:00:00:00:05",
                         "10.0.0.6": "00:00:00:00:00:06",
                         "10.0.0.7": "00:00:00:00:00:07",
                         "10.0.0.8": "00:00:00:00:00:08",
                         "10.0.0.9": "00:00:00:00:00:09",
                         "10.0.0.10": "00:00:00:00:00:0a",
                         "10.0.0.11": "00:00:00:00:00:0b",
                         "10.0.0.12": "00:00:00:00:00:0c",
                         "10.0.0.13": "00:00:00:00:00:0d",
                         "10.0.0.14": "00:00:00:00:00:0e"
        }
        self.dpid_to_port = {
            1: {2: 2 , 3: 3, 8: 4},
            2: {1: 2, 4: 3, 3: 4},
            3: {2: 3, 1: 2, 6: 4},
            4: {2: 2, 11: 4, 5: 3},
            5: {4: 2, 6: 3, 7: 4},
            6: {3: 2, 5: 3, 10: 4, 14: 5},
            7: {5: 2, 8: 3},
            8: {1: 2, 7: 3, 9: 4},
            9: {8: 2, 10: 4, 12: 3, 13: 5},
            10: {6: 2, 9: 3},
            11: {4: 2, 13: 3, 12: 4},
            12: {11: 3, 9: 2, 14: 4},
            13: {11: 3, 9: 2, 14: 4},
            14: {6: 2, 12: 3, 13: 4}}
       
    @set_ev_cls(ofp_event.EventOFPSwitchFeatures, CONFIG_DISPATCHER)
    def switch_features_handler(self, ev):
        datapath = ev.msg.datapath
        dpid = datapath.id
        ip=f'10.0.0.{dpid}'
        mac=self.hw_addr[ip]
        self.add_table_miss_flow(datapath)
        self.install_host_flows(datapath, ip, mac, port=1)
        self.instalar_rutas(datapath,dpid)
        self.logger.info("Rutas instaladas para el switch %s", dpid)
        self.send_flow_stats_request(datapath)
       
    def instalar_rutas(self, datapath, dpid):
        # Revisa cada posible ruta en el diccionario para ver si 'dpid' está en el medio
        for src_dpid, dest_paths in self.paths.items():
            for dest_dpid, path in dest_paths.items():
                # Asegúrate de que 'dpid' está en la ruta y no es ni el primero ni el último
                if dpid in path:
                    current_index = path.index(dpid)
                    if 0 < current_index < len(path) - 1:  # No es el primero ni el último
                        first_dpid = path[0]
                        last_dpid = path[-1]
                        next_hop_dpid = path[current_index + 1]

                        dst_ip = f'10.0.0.{last_dpid}'
                        src_ip = f'10.0.0.{first_dpid}'
                        out_port = self.calculate_outports(datapath, path, current_index)

                    # Instala flujo para IP y ARP si es necesario
                        self.instalar_flow(datapath, dst_ip, src_ip, out_port)

                    else:
                        if dest_dpid != dpid:
                            dst_ip=f'10.0.0.{dest_dpid}'
                            or_ip =f'10.0.0.{dpid}'
                            out_port = self.calculate_outport(datapath, path)
                            self.instalar_flow(datapath, dst_ip, or_ip, out_port)


    def calculate_outports(self, datapath, path, current_index):
    # Asume que self.dpid_to_port tiene el mapeo correcto de los puertos entre switches
        current_dpid = path[current_index]
        next_hop_dpid = path[current_index + 1]
        return self.dpid_to_port[current_dpid][next_hop_dpid]
   
    def calculate_outport(self, datapath, path):
        dpid_act = datapath.id
        if len(path) >1:
            next_hop_dpid = path[1]
        else:
            return None
        out_port = self.dpid_to_port[dpid_act][next_hop_dpid]
        return out_port
       
   
    def instalar_flow(self, datapath, dst_ip, or_ip, out_port):
        ofproto = datapath.ofproto
        parser = datapath.ofproto_parser
        actions = [parser.OFPActionOutput(out_port)]
        match = parser.OFPMatch(eth_type=ether_types.ETH_TYPE_IP, ipv4_src=or_ip, ipv4_dst=dst_ip)
        priority = 100
        inst = [parser.OFPInstructionActions(ofproto.OFPIT_APPLY_ACTIONS, actions)]
        mod = parser.OFPFlowMod(datapath=datapath, priority=priority, match=match, instructions=inst)
        datapath.send_msg(mod)
   
   
       
    def add_table_miss_flow(self, datapath):
        ofproto = datapath.ofproto
        parser = datapath.ofproto_parser
        match = parser.OFPMatch()
        actions = [parser.OFPActionOutput(ofproto.OFPP_CONTROLLER, ofproto.OFPCML_NO_BUFFER)]
        priority = 0
        self.add_flow(datapath, priority, match, actions)
   
    def add_flow(self, datapath, priority, match, actions, buffer_id=None):
        ofproto = datapath.ofproto
        parser = datapath.ofproto_parser

        inst = [parser.OFPInstructionActions(ofproto.OFPIT_APPLY_ACTIONS,
                                             actions)]
        if buffer_id:
            mod = parser.OFPFlowMod(datapath=datapath, buffer_id=buffer_id,
                                    priority=priority, match=match,
                                    instructions=inst)
        else:
            mod = parser.OFPFlowMod(datapath=datapath, priority=priority,
                                    match=match, instructions=inst)
        datapath.send_msg(mod)
   
   
    def install_host_flows(self, datapath, ip_host, mac_host, port=1):
        ofproto = datapath.ofproto
        parser = datapath.ofproto_parser
        actions = [parser.OFPActionOutput(port)]  # Asegurando que el tráfico al host sale por el puerto 1
        match = parser.OFPMatch(eth_type=ether_types.ETH_TYPE_IP, ipv4_dst=ip_host, eth_dst=mac_host)
        self.add_flow(datapath, 100, match, actions)


    def send_flow_stats_request(self, datapath):
        ofproto = datapath.ofproto
        parser = datapath.ofproto_parser
       
        req = parser.OFPFlowStatsRequest(datapath)
        datapath.send_msg(req)
    @set_ev_cls(ofp_event.EventOFPFlowStatsReply, MAIN_DISPATCHER)
    def flow_stats_reply_handler(self, ev):
        flows = []
        for stat in ev.msg.body:
            flows.append(f'Flow ID: {stat.cookie}, Priority: {stat.priority}, '
                         f'Match: {stat.match}, Actions: {stat.instructions}')
        self.logger.info('----------------------Flow Stats from DPID %s: %s', ev.msg.datapath.id, flows)
       
    def process_arp_request(self, datapath, in_port, eth_pkt, arp_pkt):
        # Supongamos que esta es la IP y MAC que conocemos y queremos responder
       
        requester_ip = arp_pkt.src_ip
        requested_ip = arp_pkt.dst_ip

        if requested_ip in self.hw_addr:
            src_mac = self.hw_addr[requested_ip]  # MAC que queremos que el solicitante use
            dst_mac = eth_pkt.src  # MAC del host que envió la solicitud ARP
            self.send_arp_reply(datapath, src_mac, requested_ip, dst_mac, requester_ip, in_port)
           
    def send_arp_reply(self, datapath, src_mac, src_ip, dst_mac, dst_ip, out_port):
        ofproto = datapath.ofproto
        parser = datapath.ofproto_parser

        # Crear el paquete Ethernet para ARP reply
        e = ethernet.ethernet(dst=dst_mac, src=src_mac, ethertype=ether_types.ETH_TYPE_ARP)
       
        # Crear el paquete ARP
        a = arp.arp(opcode=arp.ARP_REPLY, src_mac=src_mac, src_ip=src_ip,
                    dst_mac=dst_mac, dst_ip=dst_ip)
       
        # Encapsular los protocolos ARP dentro del paquete Ethernet
        p = packet.Packet()
        p.add_protocol(e)
        p.add_protocol(a)
        p.serialize()

        # Envío del paquete ARP reply al puerto de salida especificado
        actions = [parser.OFPActionOutput(out_port)]
        out_packet = parser.OFPPacketOut(datapath=datapath, buffer_id=ofproto.OFP_NO_BUFFER,
                                         in_port=ofproto.OFPP_CONTROLLER, actions=actions, data=p.data)
        datapath.send_msg(out_packet)
   
   

    @set_ev_cls(ofp_event.EventOFPPacketIn, MAIN_DISPATCHER)
    def _packet_in_handler(self, ev):
        # If you hit this you might want to increase
        # the "miss_send_length" of your switch
        if ev.msg.msg_len < ev.msg.total_len:
            self.logger.debug("packet truncated: only %s of %s bytes",
                              ev.msg.msg_len, ev.msg.total_len)
         
        msg = ev.msg
        datapath = msg.datapath
        #time = ev.timestamp
        ofproto = datapath.ofproto
        parser = datapath.ofproto_parser
        in_port = msg.match['in_port']

        pkt = packet.Packet(msg.data)
        eth = pkt.get_protocols(ethernet.ethernet)[0]
        #self.lecturatime(time)
       
        if eth.ethertype == ether_types.ETH_TYPE_LLDP:
            # ignore lldp packet
            return
           
        eth_type = eth.ethertype
        ip_pkt = pkt.get_protocol(ipv4.ipv4)
        arp_pkt = pkt.get_protocol(arp.arp)
       
        dst = eth.dst
        src = eth.src
       
        self.logger.info("%s y %s", dst, src)
           # Verificar si el paquete es ARP
        if eth.ethertype == ether_types.ETH_TYPE_ARP:
            if arp_pkt.opcode == arp.ARP_REQUEST:
                # Procesar la solicitud ARP
                self.process_arp_request(datapath, in_port, eth, arp_pkt)