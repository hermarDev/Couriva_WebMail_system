#!/usr/bin/env python3
"""
Couriva WebMail Use Case Diagram Generator & Auditor
UML 2.5 Standard — Actors and Use Cases
Generates pure uncompressed XML for draw.io, vector SVG, and high-res PNG screenshot.
Conforms to zero-defects, note-free purity standards.
"""

import os
import sys
import subprocess
import xml.etree.ElementTree as ET
from xml.dom import minidom
import html

# Style constants for Draw.io XML
STYLE_TITLE = "text;html=1;strokeColor=none;fillColor=none;align=center;verticalAlign=middle;whiteSpace=wrap;rounded=0;fontFamily=Helvetica;fontSize=18;fontStyle=1;fontColor=#000000;"
STYLE_SUBTITLE = "text;html=1;strokeColor=none;fillColor=none;align=center;verticalAlign=middle;whiteSpace=wrap;rounded=0;fontFamily=Helvetica;fontSize=11;fontStyle=2;fontColor=#000000;"
STYLE_ACTOR = "shape=umlActor;verticalLabelPosition=bottom;verticalAlign=top;html=1;outlineConnect=0;fillColor=#FFFFFF;strokeColor=#000000;strokeWidth=1.5;fontFamily=Helvetica;fontSize=11;fontStyle=1;fontColor=#000000;align=center;"
STYLE_USECASE = "ellipse;whiteSpace=wrap;html=1;fillColor=#FFFFFF;strokeColor=#000000;strokeWidth=1.5;fontFamily=Helvetica;fontSize=11;fontStyle=1;fontColor=#000000;align=center;"
STYLE_BOUNDARY = "rounded=0;whiteSpace=wrap;html=1;fillColor=none;strokeColor=#000000;strokeWidth=2;dashed=1;fontFamily=Helvetica;fontSize=14;fontStyle=1;fontColor=#000000;verticalAlign=top;align=left;spacingLeft=15;spacingTop=10;"
STYLE_ASSOC = "edgeStyle=orthogonalEdgeStyle;rounded=0;orthogonalLoop=1;jettySize=auto;html=1;strokeColor=#000000;strokeWidth=1.5;fontFamily=Helvetica;fontSize=10;fontColor=#000000;endArrow=none;"
STYLE_INCLUDE = "edgeStyle=orthogonalEdgeStyle;rounded=0;orthogonalLoop=1;jettySize=auto;html=1;strokeColor=#000000;strokeWidth=1.5;dashed=1;fontFamily=Helvetica;fontSize=10;fontStyle=2;fontColor=#000000;endArrow=open;endSize=8;"


def build_use_case_diagram():
    diagram = ET.Element('diagram', {'id': 'couriva-use-case', 'name': 'Couriva WebMail Use Case Diagram'})
    model = ET.SubElement(diagram, 'mxGraphModel', {
        'dx': '1200', 'dy': '800', 'grid': '1', 'gridSize': '10',
        'guides': '1', 'tooltips': '1', 'connect': '1', 'arrows': '1',
        'fold': '1', 'page': '1', 'pageScale': '1',
        'pageWidth': '1200', 'pageHeight': '800',
        'math': '0', 'shadow': '0'
    })
    root = ET.SubElement(model, 'root')
    ET.SubElement(root, 'mxCell', {'id': '0'})
    ET.SubElement(root, 'mxCell', {'id': '1', 'parent': '0'})

    nodes = []
    edges = []

    def add_node(nid, value, style, x, y, w, h):
        cell = ET.SubElement(root, 'mxCell', {
            'id': nid, 'value': html.escape(value), 'style': style,
            'parent': '1', 'vertex': '1'
        })
        ET.SubElement(cell, 'mxGeometry', {
            'x': str(x), 'y': str(y),
            'width': str(w), 'height': str(h),
            'as': 'geometry'
        })
        nodes.append({'id': nid, 'val': value, 'style': style, 'x': x, 'y': y, 'w': w, 'h': h})

    def add_edge(eid, src, tgt, val='', is_include=False, exit_pos=None, entry_pos=None, points=None):
        style = STYLE_INCLUDE if is_include else STYLE_ASSOC
        if exit_pos and entry_pos:
            style += f"exitX={exit_pos[0]};exitY={exit_pos[1]};entryX={entry_pos[0]};entryY={entry_pos[1]};"

        cell = ET.SubElement(root, 'mxCell', {
            'id': eid, 'value': html.escape(val), 'style': style,
            'parent': '1', 'source': src, 'target': tgt, 'edge': '1'
        })
        geo = ET.SubElement(cell, 'mxGeometry', {'relative': '1', 'as': 'geometry'})
        if points:
            arr = ET.SubElement(geo, 'Array', {'as': 'points'})
            for px, py in points:
                ET.SubElement(arr, 'mxPoint', {'x': str(px), 'y': str(py)})

        edges.append({
            'id': eid, 'src': src, 'tgt': tgt, 'val': val,
            'is_include': is_include, 'exit_pos': exit_pos, 'entry_pos': entry_pos,
            'points': points or []
        })

    # Header Title & Subtitle (Clean UTF-8 em-dash)
    add_node('title', 'COURIVA WEBMAIL — USE CASE DIAGRAM', STYLE_TITLE, 300, 18, 600, 32)
    add_node('subtitle', 'UML 2.5 Standard (Actors and System Use Cases)', STYLE_SUBTITLE, 300, 48, 600, 22)

    # System Boundary (Tightened for balanced vertical geometry: y = 80..760)
    add_node('boundary', 'Couriva WebMail System', STYLE_BOUNDARY, 240, 80, 720, 680)

    # Actors — Left Side: Standard Mailbox User
    add_node('actor_user', 'User\n(Internal Employee)', STYLE_ACTOR, 80, 370, 40, 70)

    # Actors — Right Side:
    # Directory Service at top right (y = 210) aligned directly with UC-06 LDAP Auth
    add_node('actor_directory', 'Directory Service\n(Active Directory / LDAP)', STYLE_ACTOR, 1070, 215, 40, 70)

    # Administrator at bottom right (y = 520) aligned with UC-08 Admin Functions
    add_node('actor_admin', 'Administrator\n(System Admin)', STYLE_ACTOR, 1070, 520, 40, 70)

    # Use Cases (Ellipses inside boundary)
    # Column 1: Primary User & Core Features (center X = 430, width = 230, height = 55)
    c1_x = 315
    c1_w = 230
    c1_h = 55

    add_node('uc01', 'UC-01: Register Account\n(Active @couriva.local Mailbox)', STYLE_USECASE, c1_x, 115, c1_w, c1_h)
    add_node('uc02', 'UC-02: Sign In\n(Strict Email @couriva.local)', STYLE_USECASE, c1_x, 230, c1_w, c1_h)
    add_node('uc03', 'UC-03: Compose & Send\n(Rich Text & Attachments)', STYLE_USECASE, c1_x, 360, c1_w, c1_h)
    add_node('uc04', 'UC-04: Read, Search &\nOrganize Mailbox', STYLE_USECASE, c1_x, 490, c1_w, c1_h)
    add_node('uc05', 'UC-05: Account & Theme Settings\n(Light, Dark & System Modes)', STYLE_USECASE, c1_x, 620, c1_w, c1_h)

    # Column 2: Included Subsystems & Admin Functions (center X = 770, width = 240, height = 55)
    c2_x = 650
    c2_w = 240
    c2_h = 55

    add_node('uc06', 'UC-06: LDAP / AD DS Auth\n(UPN, NetBIOS & Service DN)', STYLE_USECASE, c2_x, 230, c2_w, c2_h)
    add_node('uc07', 'UC-07: MIME Content &\nAttachment Validation', STYLE_USECASE, c2_x, 360, c2_w, c2_h)
    add_node('uc08', 'UC-08: Administer Users,\nApprovals & Security Logs', STYLE_USECASE, c2_x, 525, c2_w, 60)

    # Associations: User -> Use Cases (Planar fan-out, zero crossings)
    add_edge('e_u_uc01', 'actor_user', 'uc01')
    add_edge('e_u_uc02', 'actor_user', 'uc02')
    add_edge('e_u_uc03', 'actor_user', 'uc03')
    add_edge('e_u_uc04', 'actor_user', 'uc04')
    add_edge('e_u_uc05', 'actor_user', 'uc05')

    # Association: Directory Service -> LDAP / AD DS Auth (Direct horizontal line, zero crossings)
    add_edge('e_d_uc06', 'actor_directory', 'uc06', exit_pos=(0.0, 0.5), entry_pos=(1.0, 0.5))

    # Associations: Administrator -> Administer Users & Account Settings (Planar, zero crossings)
    add_edge('e_a_uc08', 'actor_admin', 'uc08', exit_pos=(0.0, 0.5), entry_pos=(1.0, 0.5))
    add_edge('e_a_uc05', 'actor_admin', 'uc05', exit_pos=(0.0, 0.7), entry_pos=(1.0, 0.5),
             points=[(1020, 570), (1020, 647.5)])

    # <<include>> Relationships (Dashed directed arrows with <<include>> label)
    add_edge('e_inc_signin_ldap', 'uc02', 'uc06', val='<<include>>', is_include=True,
             exit_pos=(1.0, 0.5), entry_pos=(0.0, 0.5))
    add_edge('e_inc_compose_mime', 'uc03', 'uc07', val='<<include>>', is_include=True,
             exit_pos=(1.0, 0.5), entry_pos=(0.0, 0.5))

    return diagram, nodes, edges


def generate_use_case_svg(nodes, edges, width=1200, height=800):
    svg_lines = []
    svg_lines.append(f'<svg xmlns="http://www.w3.org/2000/svg" width="{width}" height="{height}" viewBox="0 0 {width} {height}">')
    svg_lines.append('<defs>')
    svg_lines.append('  <marker id="arrow" viewBox="0 0 10 10" refX="10" refY="5" markerWidth="6" markerHeight="6" orient="auto-start-reverse">')
    svg_lines.append('    <path d="M 0 1.5 L 10 5 L 0 8.5 z" fill="#000000"/>')
    svg_lines.append('  </marker>')
    svg_lines.append('  <marker id="open-arrow" viewBox="0 0 10 10" refX="10" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse">')
    svg_lines.append('    <path d="M 0 1.5 L 10 5 L 0 8.5" fill="none" stroke="#000000" stroke-width="1.5"/>')
    svg_lines.append('  </marker>')
    svg_lines.append('</defs>')
    svg_lines.append(f'<rect x="0" y="0" width="{width}" height="{height}" fill="#ffffff"/>')

    nodes_by_id = {n['id']: n for n in nodes}

    # 1. Render Boundary First (behind edges)
    for n in nodes:
        if n['id'] == 'boundary':
            x, y, w, h = n['x'], n['y'], n['w'], n['h']
            svg_lines.append(f'<rect x="{x:.1f}" y="{y:.1f}" width="{w:.1f}" height="{h:.1f}" fill="#fcfcfd" stroke="#000000" stroke-width="2" stroke-dasharray="6,4" rx="4"/>')
            svg_lines.append(f'<text x="{x + 20:.1f}" y="{y + 26:.1f}" font-family="Helvetica, Arial, sans-serif" font-size="13" font-weight="bold" fill="#000000">{html.escape(n["val"])}</text>')

    # 2. Render Edges (Associations and Include lines)
    for e in edges:
        src = nodes_by_id.get(e['src'])
        tgt = nodes_by_id.get(e['tgt'])
        if not src or not tgt:
            continue

        if e.get('exit_pos') and e.get('entry_pos'):
            start_x = src['x'] + src['w'] * e['exit_pos'][0]
            start_y = src['y'] + src['h'] * e['exit_pos'][1]
            end_x = tgt['x'] + tgt['w'] * e['entry_pos'][0]
            end_y = tgt['y'] + tgt['h'] * e['entry_pos'][1]
        else:
            # Connect centers for associations
            start_x = src['x'] + src['w'] / 2.0
            start_y = src['y'] + src['h'] / 2.0
            end_x = tgt['x'] + tgt['w'] / 2.0
            end_y = tgt['y'] + tgt['h'] / 2.0

        pts = [(start_x, start_y)]
        if e.get('points'):
            pts.extend(e['points'])
        pts.append((end_x, end_y))

        d_parts = [f"M {pts[0][0]:.1f} {pts[0][1]:.1f}"]
        for pt in pts[1:]:
            d_parts.append(f"L {pt[0]:.1f} {pt[1]:.1f}")
        d_str = " ".join(d_parts)

        if e['is_include']:
            svg_lines.append(f'<path d="{d_str}" fill="none" stroke="#000000" stroke-width="1.5" stroke-dasharray="5,5" marker-end="url(#open-arrow)"/>')
            mid_x = (start_x + end_x) / 2.0
            mid_y = (start_y + end_y) / 2.0 - 7.0
            svg_lines.append(f'<text x="{mid_x:.1f}" y="{mid_y:.1f}" text-anchor="middle" font-family="Helvetica, Arial, sans-serif" font-size="10" font-style="italic" fill="#000000">&lt;&lt;include&gt;&gt;</text>')
        else:
            svg_lines.append(f'<path d="{d_str}" fill="none" stroke="#000000" stroke-width="1.5"/>')

    # 3. Render Nodes (Actors and Use Cases)
    for n in nodes:
        if n['id'] == 'boundary':
            continue

        x, y, w, h = n['x'], n['y'], n['w'], n['h']
        style = n['style']
        raw_val = n['val']
        clean_lines = [html.escape(line.strip()) for line in raw_val.split('\n')]

        if 'text;' in style:
            font_size = 18 if 'fontSize=18' in style else (11 if 'fontSize=11' in style else 14)
            font_weight = "bold" if 'fontStyle=1' in style else "normal"
            svg_lines.append(f'<text x="{x + w/2:.1f}" y="{y + h/2 + 5:.1f}" text-anchor="middle" font-family="Helvetica, Arial, sans-serif" font-size="{font_size}" font-weight="{font_weight}" fill="#000000">{clean_lines[0]}</text>')

        elif 'shape=umlActor' in style:
            cx = x + w / 2.0
            head_r = 10.0
            head_cy = y + 15.0
            neck_y = head_cy + head_r
            torso_y = neck_y + 22.0
            arms_y = neck_y + 8.0
            feet_y = torso_y + 20.0

            # Head
            svg_lines.append(f'<circle cx="{cx:.1f}" cy="{head_cy:.1f}" r="{head_r:.1f}" fill="#ffffff" stroke="#000000" stroke-width="1.8"/>')
            # Spine
            svg_lines.append(f'<line x1="{cx:.1f}" y1="{neck_y:.1f}" x2="{cx:.1f}" y2="{torso_y:.1f}" stroke="#000000" stroke-width="1.8"/>')
            # Arms
            svg_lines.append(f'<line x1="{cx - 18:.1f}" y1="{arms_y:.1f}" x2="{cx + 18:.1f}" y2="{arms_y:.1f}" stroke="#000000" stroke-width="1.8"/>')
            # Legs
            svg_lines.append(f'<line x1="{cx:.1f}" y1="{torso_y:.1f}" x2="{cx - 16:.1f}" y2="{feet_y:.1f}" stroke="#000000" stroke-width="1.8"/>')
            svg_lines.append(f'<line x1="{cx:.1f}" y1="{torso_y:.1f}" x2="{cx + 16:.1f}" y2="{feet_y:.1f}" stroke="#000000" stroke-width="1.8"/>')

            # Actor Label Underneath
            lbl_start_y = feet_y + 14.0
            for i, line in enumerate(clean_lines):
                font_weight = "bold" if i == 0 else "normal"
                svg_lines.append(f'<text x="{cx:.1f}" y="{lbl_start_y + i * 14:.1f}" text-anchor="middle" font-family="Helvetica, Arial, sans-serif" font-size="11" font-weight="{font_weight}" fill="#000000">{line}</text>')

        elif 'ellipse' in style:
            rx = w / 2.0
            ry = h / 2.0
            cx = x + rx
            cy = y + ry
            svg_lines.append(f'<ellipse cx="{cx:.1f}" cy="{cy:.1f}" rx="{rx:.1f}" ry="{ry:.1f}" fill="#ffffff" stroke="#000000" stroke-width="1.6"/>')

            line_height = 13.5
            total_text_h = len(clean_lines) * line_height
            start_y = cy - total_text_h / 2.0 + line_height * 0.75
            for i, line in enumerate(clean_lines):
                cur_y = start_y + i * line_height
                svg_lines.append(f'<text x="{cx:.1f}" y="{cur_y:.1f}" text-anchor="middle" font-family="Helvetica, Arial, sans-serif" font-size="11" font-weight="bold" fill="#000000">{line}</text>')

    svg_lines.append('</svg>')
    return "\n".join(svg_lines)


def render_png_screenshot(svg_path, png_path, width=1200, height=800):
    os.makedirs(os.path.dirname(png_path), exist_ok=True)
    cmd = [
        "chromium-browser",
        "--headless",
        "--disable-gpu",
        "--force-device-scale-factor=2",
        f"--screenshot={png_path}",
        f"--window-size={width},{height}",
        svg_path
    ]
    subprocess.run(cmd, check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    print(f"[OK] Rendered high-res PNG screenshot: {png_path}")


def main():
    target_drawio = "/opt/lampp/htdocs/Web_Mail_System/docs/flowcharts/use_case_diagram.drawio"
    target_svg = "/opt/lampp/htdocs/Web_Mail_System/docs/flowcharts/use_case_diagram.svg"
    target_screenshot = "/opt/lampp/htdocs/Web_Mail_System/docs/screenshots/05_use_case_diagram_clean.png"

    print("Generating Couriva Use Case Diagram...")
    diagram, nodes, edges = build_use_case_diagram()

    mxfile = ET.Element('mxfile', {
        'host': 'Electron',
        'modified': '2026-11-16T00:00:00.000Z',
        'agent': 'Couriva-DiagramArchitect',
        'version': '21.0.0',
        'type': 'device'
    })
    mxfile.append(diagram)

    raw_xml = ET.tostring(mxfile, encoding='utf-8')
    parsed = minidom.parseString(raw_xml)
    pretty_xml = parsed.toprettyxml(indent="  ")

    os.makedirs(os.path.dirname(target_drawio), exist_ok=True)
    with open(target_drawio, 'w', encoding='utf-8') as f:
        f.write(pretty_xml)
    print(f"[OK] Generated uncompressed Draw.io file: {target_drawio}")

    svg_content = generate_use_case_svg(nodes, edges, width=1200, height=800)
    with open(target_svg, 'w', encoding='utf-8') as f:
        f.write(svg_content)
    print(f"[OK] Generated clean vector SVG: {target_svg}")

    print("\nRendering high-res screenshot PNG via Chromium headless...")
    render_png_screenshot(target_svg, target_screenshot, width=1200, height=800)


if __name__ == '__main__':
    main()
