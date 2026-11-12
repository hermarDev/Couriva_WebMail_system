#!/usr/bin/env python3
"""
Couriva WebMail Use Case Diagram Generator
UML 2.5 Standard — Actors and Use Cases
Generates pure uncompressed XML for draw.io.
"""

import os
import sys
import xml.etree.ElementTree as ET
from xml.dom import minidom


# Style constants
STYLE_TITLE = "text;html=1;strokeColor=none;fillColor=none;align=center;verticalAlign=middle;whiteSpace=wrap;rounded=0;fontFamily=Helvetica;fontSize=18;fontStyle=1;fontColor=#000000;"
STYLE_SUBTITLE = "text;html=1;strokeColor=none;fillColor=none;align=center;verticalAlign=middle;whiteSpace=wrap;rounded=0;fontFamily=Helvetica;fontSize=11;fontStyle=2;fontColor=#000000;"
STYLE_ACTOR = "shape=umlActor;verticalLabelPosition=bottom;verticalAlign=top;html=1;outlineConnect=0;fillColor=#FFFFFF;strokeColor=#000000;strokeWidth=1.5;fontFamily=Helvetica;fontSize=12;fontColor=#000000;"
STYLE_USECASE = "ellipse;whiteSpace=wrap;html=1;fillColor=#FFFFFF;strokeColor=#000000;strokeWidth=1.5;fontFamily=Helvetica;fontSize=12;fontColor=#000000;align=center;"
STYLE_BOUNDARY = "rounded=0;whiteSpace=wrap;html=1;fillColor=none;strokeColor=#000000;strokeWidth=2;dashed=1;fontFamily=Helvetica;fontSize=14;fontStyle=1;fontColor=#000000;verticalAlign=top;align=center;"
STYLE_ASSOC = "edgeStyle=orthogonalEdgeStyle;rounded=0;orthogonalLoop=1;jettySize=auto;html=1;strokeColor=#000000;strokeWidth=1.5;fontFamily=Helvetica;fontSize=11;fontColor=#000000;endArrow=none;"


def build_use_case_diagram():
    diagram = ET.Element('diagram', {'id': 'couriva-use-case', 'name': 'Couriva WebMail Use Case Diagram'})
    model = ET.SubElement(diagram, 'mxGraphModel', {
        'dx': '1200', 'dy': '900', 'grid': '1', 'gridSize': '10',
        'guides': '1', 'tooltips': '1', 'connect': '1', 'arrows': '1',
        'fold': '1', 'page': '1', 'pageScale': '1',
        'pageWidth': '1200', 'pageHeight': '900',
        'math': '0', 'shadow': '0'
    })
    root = ET.SubElement(model, 'root')
    ET.SubElement(root, 'mxCell', {'id': '0'})
    ET.SubElement(root, 'mxCell', {'id': '1', 'parent': '0'})

    def add_node(nid, value, style, x, y, w, h):
        cell = ET.SubElement(root, 'mxCell', {
            'id': nid, 'value': value, 'style': style,
            'parent': '1', 'vertex': '1'
        })
        ET.SubElement(cell, 'mxGeometry', {
            'x': str(x), 'y': str(y),
            'width': str(w), 'height': str(h),
            'as': 'geometry'
        })

    def add_edge(eid, src, tgt):
        cell = ET.SubElement(root, 'mxCell', {
            'id': eid, 'value': '', 'style': STYLE_ASSOC,
            'parent': '1', 'source': src, 'target': tgt, 'edge': '1'
        })
        ET.SubElement(cell, 'mxGeometry', {'relative': '1', 'as': 'geometry'})

    # Title & Subtitle
    add_node('title', 'COURIVA WEBMAIL &mdash; USE CASE DIAGRAM', STYLE_TITLE, 300, 20, 600, 35)
    add_node('subtitle', 'UML 2.5 Standard (Actors and Use Cases)', STYLE_SUBTITLE, 300, 55, 600, 25)

    # System Boundary
    add_node('boundary', 'Couriva WebMail System', STYLE_BOUNDARY, 250, 100, 700, 700)

    # Actors — left side
    add_node('actor_user', 'User', STYLE_ACTOR, 80, 350, 40, 70)

    # Actors — right side
    add_node('actor_admin', 'Administrator', STYLE_ACTOR, 1080, 280, 40, 70)
    add_node('actor_directory', 'Directory&#xa;Service', STYLE_ACTOR, 1080, 550, 40, 70)

    # Use Cases (ellipses inside boundary)
    uc_x = 400
    uc_w = 240
    uc_h = 55

    add_node('uc01', 'UC-01 Sign In', STYLE_USECASE, uc_x, 150, uc_w, uc_h)
    add_node('uc02', 'UC-02 Create Mailbox', STYLE_USECASE, uc_x, 240, uc_w, uc_h)
    add_node('uc03', 'UC-03 LDAP/AD Auth', STYLE_USECASE, uc_x + 200, 330, uc_w, uc_h)
    add_node('uc04', 'UC-04 Compose &amp; Send', STYLE_USECASE, uc_x, 420, uc_w, uc_h)
    add_node('uc05', 'UC-05 Manage Folders&#xa;&amp; Labels', STYLE_USECASE, uc_x, 510, uc_w, uc_h)
    add_node('uc06', 'UC-06 Account &amp;&#xa;Theme Settings', STYLE_USECASE, uc_x, 600, uc_w, uc_h)
    add_node('uc07', 'UC-07 Administer&#xa;Users &amp; Logs', STYLE_USECASE, uc_x + 200, 700, uc_w, uc_h)

    # Associations: User -> use cases
    add_edge('e_u_uc01', 'actor_user', 'uc01')
    add_edge('e_u_uc02', 'actor_user', 'uc02')
    add_edge('e_u_uc04', 'actor_user', 'uc04')
    add_edge('e_u_uc05', 'actor_user', 'uc05')
    add_edge('e_u_uc06', 'actor_user', 'uc06')

    # Associations: Administrator -> use cases
    add_edge('e_a_uc01', 'actor_admin', 'uc01')
    add_edge('e_a_uc07', 'actor_admin', 'uc07')

    # Associations: Directory Service -> LDAP/AD Auth
    add_edge('e_d_uc03', 'actor_directory', 'uc03')

    return diagram


def main():
    target = "/opt/lampp/htdocs/Web_Mail_System/docs/flowcharts/use_case_diagram.drawio"

    print("Generating Couriva Use Case Diagram...")
    diagram = build_use_case_diagram()

    mxfile = ET.Element('mxfile', {
        'host': 'Electron',
        'modified': '2026-11-12T00:00:00.000Z',
        'agent': 'Antigravity-DrawIOArchitect',
        'version': '21.0.0',
        'type': 'device'
    })
    mxfile.append(diagram)

    raw_xml = ET.tostring(mxfile, encoding='utf-8')
    parsed = minidom.parseString(raw_xml)
    pretty_xml = parsed.toprettyxml(indent="  ")

    os.makedirs(os.path.dirname(target), exist_ok=True)
    with open(target, 'w', encoding='utf-8') as f:
        f.write(pretty_xml)
    print(f"[OK] Generated: {target}")


if __name__ == '__main__':
    main()
