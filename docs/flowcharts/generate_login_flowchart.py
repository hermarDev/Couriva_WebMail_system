#!/usr/bin/env python3
"""
Couriva WebMail Login Flowchart Generator & Auditor
Compliant with ANSI X3.5-1970 and ISO 5807:1985 Information Processing Standards.
Generates pure uncompressed XML and validates with 0 defects.
Exports clean vector SVG and high-resolution PNG screenshot via Chromium headless.
"""

import os
import sys
import subprocess
import xml.etree.ElementTree as ET
from xml.dom import minidom
import html

class DiagramBuilder:
    # ANSI X3.5 / ISO 5807 Style Constants
    STYLE_START = "rounded=1;arcSize=50;whiteSpace=wrap;html=1;fillColor=#FFFFFF;strokeColor=#000000;strokeWidth=2;fontFamily=Helvetica;fontSize=12;fontStyle=1;fontColor=#000000;align=center;"
    STYLE_PROCESS = "rounded=0;whiteSpace=wrap;html=1;fillColor=#FFFFFF;strokeColor=#000000;strokeWidth=1.5;fontFamily=Helvetica;fontSize=11;fontColor=#000000;align=center;"
    STYLE_IO = "shape=parallelogram;perimeter=parallelogramPerimeter;whiteSpace=wrap;html=1;fixedSize=1;size=20;fillColor=#FFFFFF;strokeColor=#000000;strokeWidth=1.5;fontFamily=Helvetica;fontSize=11;fontColor=#000000;align=center;"
    STYLE_DECISION = "rhombus;whiteSpace=wrap;html=1;fillColor=#FFFFFF;strokeColor=#000000;strokeWidth=1.5;fontFamily=Helvetica;fontSize=11;fontStyle=1;fontColor=#000000;align=center;"
    STYLE_DB = "shape=cylinder3;whiteSpace=wrap;html=1;boundedLbl=1;backgroundOutline=1;size=10;fillColor=#FFFFFF;strokeColor=#000000;strokeWidth=1.5;fontFamily=Helvetica;fontSize=11;fontColor=#000000;align=center;"
    STYLE_TITLE = "text;html=1;strokeColor=none;fillColor=none;align=center;verticalAlign=middle;whiteSpace=wrap;rounded=0;fontFamily=Helvetica;fontSize=18;fontStyle=1;fontColor=#000000;"
    STYLE_SUBTITLE = "text;html=1;strokeColor=none;fillColor=none;align=center;verticalAlign=middle;whiteSpace=wrap;rounded=0;fontFamily=Helvetica;fontSize=11;fontStyle=2;fontColor=#000000;"
    STYLE_EDGE_SOLID = "edgeStyle=orthogonalEdgeStyle;rounded=0;orthogonalLoop=1;jettySize=auto;html=1;strokeColor=#000000;strokeWidth=1.5;fontFamily=Helvetica;fontSize=10;fontStyle=1;fontColor=#000000;endArrow=classic;"

    def __init__(self, diag_id, name, width=1500, height=2650):
        self.diag_id = diag_id
        self.name = name
        self.width = width
        self.height = height
        self.nodes = []
        self.edges = []

    def add_node(self, nid, value, style, x, y, w, h):
        self.nodes.append({
            'id': nid,
            'val': value,
            'style': style,
            'x': float(x),
            'y': float(y),
            'w': float(w),
            'h': float(h)
        })

    def add_edge(self, eid, src, tgt, val='', exit_pos=(0.5, 1.0), entry_pos=(0.5, 0.0), points=None, label_offset=None):
        self.edges.append({
            'id': eid,
            'src': src,
            'tgt': tgt,
            'val': val,
            'exit_x': exit_pos[0],
            'exit_y': exit_pos[1],
            'entry_x': entry_pos[0],
            'entry_y': entry_pos[1],
            'points': points or [],
            'label_offset': label_offset
        })

    def to_xml_element(self):
        diag_el = ET.Element('diagram', {'id': self.diag_id, 'name': self.name})
        model_el = ET.SubElement(diag_el, 'mxGraphModel', {
            'dx': str(self.width), 'dy': str(self.height), 'grid': '1', 'gridSize': '10',
            'guides': '1', 'tooltips': '1', 'connect': '1', 'arrows': '1',
            'fold': '1', 'page': '1', 'pageScale': '1',
            'pageWidth': str(self.width), 'pageHeight': str(self.height),
            'math': '0', 'shadow': '0'
        })
        root_el = ET.SubElement(model_el, 'root')
        ET.SubElement(root_el, 'mxCell', {'id': '0'})
        ET.SubElement(root_el, 'mxCell', {'id': '1', 'parent': '0'})

        for n in self.nodes:
            cell = ET.SubElement(root_el, 'mxCell', {
                'id': n['id'],
                'value': html.escape(n['val']),
                'style': n['style'],
                'parent': '1',
                'vertex': '1'
            })
            ET.SubElement(cell, 'mxGeometry', {
                'x': str(n['x']),
                'y': str(n['y']),
                'width': str(n['w']),
                'height': str(n['h']),
                'as': 'geometry'
            })

        for e in self.edges:
            style = f"{self.STYLE_EDGE_SOLID}exitX={e['exit_x']};exitY={e['exit_y']};exitDx=0;exitDy=0;entryX={e['entry_x']};entryY={e['entry_y']};entryDx=0;entryDy=0;"
            cell = ET.SubElement(root_el, 'mxCell', {
                'id': e['id'],
                'value': html.escape(e['val']),
                'style': style,
                'parent': '1',
                'source': e['src'],
                'target': e['tgt'],
                'edge': '1'
            })
            geo = ET.SubElement(cell, 'mxGeometry', {'relative': '1', 'as': 'geometry'})
            if e['label_offset']:
                geo.attrib['x'] = str(e['label_offset'][0])
                geo.attrib['y'] = str(e['label_offset'][1])
            if e['points']:
                arr = ET.SubElement(geo, 'Array', {'as': 'points'})
                for px, py in e['points']:
                    ET.SubElement(arr, 'mxPoint', {'x': str(px), 'y': str(py)})

        return diag_el

    def to_svg(self):
        svg_lines = []
        svg_lines.append(f'<svg xmlns="http://www.w3.org/2000/svg" width="{self.width}" height="{self.height}" viewBox="0 0 {self.width} {self.height}">')
        svg_lines.append('<defs>')
        svg_lines.append('  <marker id="arrow" viewBox="0 0 10 10" refX="10" refY="5" markerWidth="6" markerHeight="6" orient="auto-start-reverse">')
        svg_lines.append('    <path d="M 0 1.5 L 10 5 L 0 8.5 z" fill="#000000"/>')
        svg_lines.append('  </marker>')
        svg_lines.append('</defs>')
        svg_lines.append(f'<rect x="0" y="0" width="{self.width}" height="{self.height}" fill="#ffffff"/>')

        nodes_by_id = {n['id']: n for n in self.nodes}

        # 1. Render Edges
        for e in self.edges:
            src = nodes_by_id.get(e['src'])
            tgt = nodes_by_id.get(e['tgt'])
            if not src or not tgt:
                continue

            start_x = src['x'] + src['w'] * e['exit_x']
            start_y = src['y'] + src['h'] * e['exit_y']
            end_x = tgt['x'] + tgt['w'] * e['entry_x']
            end_y = tgt['y'] + tgt['h'] * e['entry_y']

            pts = [(start_x, start_y)]
            if e['points']:
                pts.extend(e['points'])
            pts.append((end_x, end_y))

            # Build SVG path
            d_parts = [f"M {pts[0][0]:.1f} {pts[0][1]:.1f}"]
            for pt in pts[1:]:
                d_parts.append(f"L {pt[0]:.1f} {pt[1]:.1f}")
            d_str = " ".join(d_parts)

            svg_lines.append(f'<path d="{d_str}" fill="none" stroke="#000000" stroke-width="1.5" marker-end="url(#arrow)"/>')

            # Render Edge Label if present
            if e['val']:
                val_text = html.escape(e['val'])
                p1 = pts[0]
                p2 = pts[1] if len(pts) > 1 else pts[0]

                # Position label slightly offset from the first segment
                if abs(p1[1] - p2[1]) < 0.1:  # horizontal segment
                    lx = min(p1[0], p2[0]) + abs(p1[0] - p2[0]) * 0.4
                    ly = p1[1] - 6.0
                    anchor = "middle"
                else:  # vertical segment
                    lx = p1[0] + 12.0
                    ly = min(p1[1], p2[1]) + 20.0
                    anchor = "start"

                if e.get('label_offset'):
                    lx += e['label_offset'][0]
                    ly += e['label_offset'][1]

                svg_lines.append(f'<text x="{lx:.1f}" y="{ly:.1f}" text-anchor="{anchor}" font-family="Helvetica, Arial, sans-serif" font-size="10" font-weight="bold" fill="#000000">{val_text}</text>')

        # 2. Render Nodes
        for n in self.nodes:
            x, y, w, h = n['x'], n['y'], n['w'], n['h']
            style = n['style']
            raw_val = n['val']
            clean_lines = [html.escape(line.strip()) for line in raw_val.split('\n')]

            if 'text;' in style:
                font_size = 18 if 'fontSize=18' in style else (11 if 'fontSize=11' in style else 14)
                font_weight = "bold" if 'fontStyle=1' in style else "normal"
                svg_lines.append(f'<text x="{x + w/2:.1f}" y="{y + h/2 + 5:.1f}" text-anchor="middle" font-family="Helvetica, Arial, sans-serif" font-size="{font_size}" font-weight="{font_weight}" fill="#000000">{clean_lines[0]}</text>')

            elif 'rounded=1;arcSize=50;' in style:
                rx = h / 2.0
                svg_lines.append(f'<rect x="{x:.1f}" y="{y:.1f}" width="{w:.1f}" height="{h:.1f}" rx="{rx:.1f}" ry="{rx:.1f}" fill="#ffffff" stroke="#000000" stroke-width="2.0"/>')
                self._render_node_text(svg_lines, x, y, w, h, clean_lines, font_size=11, font_weight="bold")

            elif 'rhombus' in style:
                pts = f"{x + w/2:.1f},{y:.1f} {x + w:.1f},{y + h/2:.1f} {x + w/2:.1f},{y + h:.1f} {x:.1f},{y + h/2:.1f}"
                svg_lines.append(f'<polygon points="{pts}" fill="#ffffff" stroke="#000000" stroke-width="1.5"/>')
                self._render_node_text(svg_lines, x, y, w, h, clean_lines, font_size=11, font_weight="bold")

            elif 'shape=parallelogram' in style:
                pts = f"{x + 20:.1f},{y:.1f} {x + w:.1f},{y:.1f} {x + w - 20:.1f},{y + h:.1f} {x:.1f},{y + h:.1f}"
                svg_lines.append(f'<polygon points="{pts}" fill="#ffffff" stroke="#000000" stroke-width="1.5"/>')
                self._render_node_text(svg_lines, x, y, w, h, clean_lines, font_size=11, font_weight="normal")

            elif 'shape=cylinder3' in style:
                ch = 8.0
                svg_lines.append(f'<rect x="{x:.1f}" y="{y + ch:.1f}" width="{w:.1f}" height="{h - 2*ch:.1f}" fill="#ffffff" stroke="#000000" stroke-width="1.5"/>')
                svg_lines.append(f'<ellipse cx="{x + w/2:.1f}" cy="{y + ch:.1f}" rx="{w/2:.1f}" ry="{ch:.1f}" fill="#ffffff" stroke="#000000" stroke-width="1.5"/>')
                svg_lines.append(f'<ellipse cx="{x + w/2:.1f}" cy="{y + h - ch:.1f}" rx="{w/2:.1f}" ry="{ch:.1f}" fill="#ffffff" stroke="#000000" stroke-width="1.5"/>')
                self._render_node_text(svg_lines, x, y, w, h, clean_lines, font_size=11, font_weight="normal")

            else:
                # Default process rectangle
                svg_lines.append(f'<rect x="{x:.1f}" y="{y:.1f}" width="{w:.1f}" height="{h:.1f}" fill="#ffffff" stroke="#000000" stroke-width="1.5"/>')
                self._render_node_text(svg_lines, x, y, w, h, clean_lines, font_size=11, font_weight="normal")

        svg_lines.append('</svg>')
        return "\n".join(svg_lines)

    def _render_node_text(self, svg_lines, x, y, w, h, lines, font_size=11, font_weight="normal"):
        line_height = 14.5
        total_text_h = len(lines) * line_height
        start_y = y + (h - total_text_h) / 2.0 + line_height * 0.75

        for i, line in enumerate(lines):
            cur_y = start_y + i * line_height
            svg_lines.append(f'<text x="{x + w/2:.1f}" y="{cur_y:.1f}" text-anchor="middle" font-family="Helvetica, Arial, sans-serif" font-size="{font_size}" font-weight="{font_weight}" fill="#000000">{line}</text>')


def build_login_flowchart():
    builder = DiagramBuilder("couriva-login-flowchart", "Couriva WebMail Authentication Workflow", width=1500, height=2650)

    # ----------------------------------------------------
    # Header
    # ----------------------------------------------------
    builder.add_node("header_title", "COURIVA WEBMAIL — AUTHENTICATION & LOGIN FLOWCHART",
                     builder.STYLE_TITLE, 250, 25, 600, 35)
    builder.add_node("header_subtitle", "ANSI X3.5 / ISO 5807 Compliant Authentication Pipeline (Zero-Defect Standard)",
                     builder.STYLE_SUBTITLE, 250, 60, 600, 25)

    # ----------------------------------------------------
    # Spine: Center X = 550 (Width = 260 -> x = 420..680)
    # ----------------------------------------------------

    # Step 1: START Terminator
    builder.add_node("node_start", "START: Access Login Page\n(GET /public/login.php)",
                     builder.STYLE_START, 420, 95, 260, 50)

    # Step 2: Already Authenticated Check
    # (Evaluates session idle timeout 30m, absolute timeout 8h, db status active, session_epoch match)
    builder.add_node("node_dec_auth", "Already\nAuthenticated?\n(is_authenticated())",
                     builder.STYLE_DECISION, 420, 175, 260, 90)

    builder.add_node("node_redir_inbox_early", "Redirect to Inbox\n(header Location: inbox.php)",
                     builder.STYLE_PROCESS, 760, 192.5, 250, 55)
    builder.add_node("node_end_inbox_early", "END: User in Inbox\n(public/inbox.php)",
                     builder.STYLE_START, 1050, 195, 200, 50)

    # Step 3: Render Form & Receive Submission
    builder.add_node("node_render_form", "Render Login Form\n(CSRF token, dual-theme, password toggle)",
                     builder.STYLE_PROCESS, 410, 295, 280, 60)
    builder.add_node("node_submit", "User Submits Login Form\n(POST: email, password, csrf_token)",
                     builder.STYLE_IO, 400, 385, 300, 60)

    # Step 4: CSRF Validation
    builder.add_node("node_dec_csrf", "CSRF Token\nValid?\n(verify_csrf_token())",
                     builder.STYLE_DECISION, 420, 475, 260, 90)
    builder.add_node("node_err_csrf", "Set Error:\n'Session security token expired.\nPlease try again.'",
                     builder.STYLE_PROCESS, 190, 490, 200, 60)
    builder.add_node("node_end_csrf", "END: Re-render Form\n(CSRF Error Alert)",
                     builder.STYLE_START, 30, 495, 140, 50)

    # Step 5: Database-backed Rate Limiter Throttle Check
    builder.add_node("node_dec_throttle", "Login Throttled?\n(login_is_throttled() >= 10\nfailures in 15 min)",
                     builder.STYLE_DECISION, 420, 595, 260, 90)
    builder.add_node("node_err_throttle", "Set HTTP 429 Error:\n'Too many failed login attempts.\nPlease wait 15 minutes.'",
                     builder.STYLE_PROCESS, 190, 610, 200, 60)
    builder.add_node("node_end_throttle", "END: Re-render Form\n(Rate Limited 429)",
                     builder.STYLE_START, 30, 615, 140, 50)

    # Step 6: Empty Email / Password Check
    builder.add_node("node_dec_inputs", "Email & Password\nProvided?\n(!empty($email) && !empty($password))",
                     builder.STYLE_DECISION, 420, 715, 260, 90)
    builder.add_node("node_err_input", "Set Error:\n'Please enter both email\nand password.'",
                     builder.STYLE_PROCESS, 190, 730, 200, 60)
    builder.add_node("node_end_input", "END: Re-render Form\n(Input Error Alert)",
                     builder.STYLE_START, 30, 735, 140, 50)

    # Step 7: Strict Email Validation (no bare username fallback)
    builder.add_node("node_dec_email_format", "Strict Email Format?\n(str_contains('@') &&\nfilter_var(FILTER_VALIDATE_EMAIL))",
                     builder.STYLE_DECISION, 410, 835, 280, 90)
    builder.add_node("node_err_email_format", "Set Error:\n'Please enter a valid email address.'\n(Reject bare username)",
                     builder.STYLE_PROCESS, 190, 850, 200, 60)
    builder.add_node("node_end_email_format", "END: Re-render Form\n(Invalid Email Alert)",
                     builder.STYLE_START, 30, 855, 140, 50)

    # Step 8: LDAP / Active Directory Check
    builder.add_node("node_dec_ldap_enabled", "LDAP / AD\nEnabled?\n(!empty($ldapConfig['enabled']))",
                     builder.STYLE_DECISION, 420, 955, 260, 90)

    # ----------------------------------------------------
    # LDAP / AD Subsystem (Right Side: Column 1 = x 760..1030, Column 2 = x 1090..1350)
    # ----------------------------------------------------
    builder.add_node("node_ldap_bind", "Attempt LDAP / AD DS Bind\n(NetBIOS COURIVA\\user, UPN, or\nService DN bind with Protocol v3)",
                     builder.STYLE_PROCESS, 760, 967.5, 270, 65)

    builder.add_node("node_dec_ldap_success", "LDAP Bind\nSuccessful?\n($ldapResult['success'])",
                     builder.STYLE_DECISION, 775, 1065, 240, 85)

    # LDAP Success Path (Column 2)
    builder.add_node("node_ldap_sync", "Sync / Auto-provision User\n(syncLdapUserToDatabase(): auto-create\nauth_source='ldap' or update local user)",
                     builder.STYLE_PROCESS, 1090, 1075, 260, 65)

    builder.add_node("node_dec_ldap_active", "Local Account\nActive?\n($user['status'] === 'active')",
                     builder.STYLE_DECISION, 1100, 1170, 240, 85)

    builder.add_node("node_err_ldap_disabled", "Set Error:\n'Your account is disabled.\nContact system administrator.'",
                     builder.STYLE_PROCESS, 1090, 1285, 260, 55)
    builder.add_node("node_end_ldap_disabled", "END: Re-render Form\n(Account Disabled)",
                     builder.STYLE_START, 1090, 1360, 260, 45)

    # LDAP Failure Path (Column 1 & 2): NO local DB password fallback for existing AD users!
    builder.add_node("node_dec_ldap_is_ad", "Existing Account is\nAD / LDAP User?\n($isAdUser === true)",
                     builder.STYLE_DECISION, 775, 1440, 240, 85)

    builder.add_node("node_err_ldap_ad_reject", "Set Error:\n'Active Directory credentials rejected.'\n(NO local DB fallback for AD accounts)",
                     builder.STYLE_PROCESS, 1090, 1455, 260, 55)
    builder.add_node("node_end_ldap_ad_reject", "END: Re-render Form\n(AD Auth Failed)",
                     builder.STYLE_START, 1090, 1530, 260, 45)

    builder.add_node("node_dec_ldap_fallback", "Fallback to\nLocal DB Allowed?\n(!empty($fallback_to_local))",
                     builder.STYLE_DECISION, 775, 1600, 240, 85)

    builder.add_node("node_err_ldap_no_fallback", "Set Error:\n'Invalid email or password.'\n(Fallback to local DB disabled)",
                     builder.STYLE_PROCESS, 1090, 1615, 260, 55)
    builder.add_node("node_end_ldap_no_fallback", "END: Re-render Form\n(Fallback Rejected)",
                     builder.STYLE_START, 1090, 1690, 260, 45)

    # LDAP Disabled Branch on Spine (Check if existing user is AD user)
    builder.add_node("node_dec_ldap_off_is_ad", "Existing Account is\nAD / LDAP User?\n($isAdUser === true)",
                     builder.STYLE_DECISION, 420, 1080, 260, 90)
    builder.add_node("node_err_ldap_off_ad", "Set Error:\n'Active Directory accounts must\nauthenticate via domain controller.'",
                     builder.STYLE_PROCESS, 190, 1095, 200, 60)
    builder.add_node("node_end_ldap_off_ad", "END: Re-render Form\n(AD Controller Required)",
                     builder.STYLE_START, 30, 1100, 140, 50)

    # ----------------------------------------------------
    # Local MariaDB DB Query & Validation (Central Spine)
    # ----------------------------------------------------
    builder.add_node("node_db_query", "Query User from MariaDB Database\n($this->userModel->findByEmail($email))",
                     builder.STYLE_DB, 410, 1750, 280, 75)

    builder.add_node("node_dec_user_found", "User Record\nFound in DB?\n(!empty($user))",
                     builder.STYLE_DECISION, 420, 1865, 260, 90)
    builder.add_node("node_err_not_found", "Set Error:\n'Invalid email or password.'\n(log FAILED_LOGIN attempt)",
                     builder.STYLE_PROCESS, 190, 1880, 200, 60)
    builder.add_node("node_end_not_found", "END: Re-render Form\n(Auth Failed Alert)",
                     builder.STYLE_START, 30, 1885, 140, 50)

    builder.add_node("node_dec_status", "Account Status\nActive?\n($user['status'] === 'active')",
                     builder.STYLE_DECISION, 420, 1985, 260, 90)
    builder.add_node("node_err_status", "Set Status Error:\n(pending approval, suspended,\nrejected, or disabled)",
                     builder.STYLE_PROCESS, 190, 2000, 200, 60)
    builder.add_node("node_end_status", "END: Re-render Form\n(Status Alert)",
                     builder.STYLE_START, 30, 2005, 140, 50)

    builder.add_node("node_dec_password", "Password Matches\nHash?\n(password_verify($password, $user['password']))",
                     builder.STYLE_DECISION, 410, 2105, 280, 90)
    builder.add_node("node_err_password", "Set Error:\n'Invalid email or password.'\n(log FAILED_LOGIN attempt)",
                     builder.STYLE_PROCESS, 190, 2120, 200, 60)
    builder.add_node("node_end_password", "END: Re-render Form\n(Bad Password Alert)",
                     builder.STYLE_START, 30, 2125, 140, 50)

    # ----------------------------------------------------
    # Session Initialization & Post-Auth Routing
    # ----------------------------------------------------
    builder.add_node("node_session_regen", "Regenerate Session ID & Initialize Session State\n(session_regenerate_id(true), session_epoch, set auth_time &\nlast_activity for 30m idle / 8h timeout, log USER_LOGIN SUCCESS)",
                     builder.STYLE_PROCESS, 380, 2240, 340, 75)

    builder.add_node("node_dec_pwd_change", "Must Change\nPassword?\n(!empty($_SESSION['must_change_password']))",
                     builder.STYLE_DECISION, 420, 2355, 260, 90)

    builder.add_node("node_redir_pwd_change", "Set Flash Warning &\nRedirect to Change Password\n(header Location: change-password.php)",
                     builder.STYLE_PROCESS, 760, 2370, 250, 60)
    builder.add_node("node_end_pwd_change", "END: Change Password Page\n(public/change-password.php)",
                     builder.STYLE_START, 1050, 2375, 210, 50)

    builder.add_node("node_redir_inbox", "Set Flash Success Welcome &\nRedirect to Inbox\n(header Location: inbox.php)",
                     builder.STYLE_PROCESS, 410, 2480, 280, 60)
    builder.add_node("node_end_inbox", "END: Authenticated User in Inbox\n(public/inbox.php)",
                     builder.STYLE_START, 430, 2575, 240, 50)

    # ----------------------------------------------------
    # Edges & Orthogonal Routing (ANSI Standard Flowlines & Junction Collectors)
    # ----------------------------------------------------

    # Spine Start -> Auth Check
    builder.add_edge("e_start_to_auth", "node_start", "node_dec_auth",
                     val="", exit_pos=(0.5, 1.0), entry_pos=(0.5, 0.0))

    # Auth Check branches (Binary)
    builder.add_edge("e_auth_yes", "node_dec_auth", "node_redir_inbox_early",
                     val="YES", exit_pos=(1.0, 0.5), entry_pos=(0.0, 0.5))
    builder.add_edge("e_auth_inbox_early", "node_redir_inbox_early", "node_end_inbox_early",
                     val="", exit_pos=(1.0, 0.5), entry_pos=(0.0, 0.5))
    builder.add_edge("e_auth_no", "node_dec_auth", "node_render_form",
                     val="NO", exit_pos=(0.5, 1.0), entry_pos=(0.5, 0.0))

    # Form render -> User Submit -> CSRF Check
    builder.add_edge("e_render_to_submit", "node_render_form", "node_submit",
                     val="", exit_pos=(0.5, 1.0), entry_pos=(0.5, 0.0))
    builder.add_edge("e_submit_to_csrf", "node_submit", "node_dec_csrf",
                     val="", exit_pos=(0.5, 1.0), entry_pos=(0.5, 0.0))

    # CSRF Check branches (Binary)
    builder.add_edge("e_csrf_no", "node_dec_csrf", "node_err_csrf",
                     val="NO", exit_pos=(0.0, 0.5), entry_pos=(1.0, 0.5))
    builder.add_edge("e_csrf_err_to_end", "node_err_csrf", "node_end_csrf",
                     val="", exit_pos=(0.0, 0.5), entry_pos=(1.0, 0.5))
    builder.add_edge("e_csrf_yes", "node_dec_csrf", "node_dec_throttle",
                     val="YES", exit_pos=(0.5, 1.0), entry_pos=(0.5, 0.0))

    # Throttle Check branches (Binary)
    builder.add_edge("e_throttle_yes", "node_dec_throttle", "node_err_throttle",
                     val="YES", exit_pos=(0.0, 0.5), entry_pos=(1.0, 0.5))
    builder.add_edge("e_throttle_err_to_end", "node_err_throttle", "node_end_throttle",
                     val="", exit_pos=(0.0, 0.5), entry_pos=(1.0, 0.5))
    builder.add_edge("e_throttle_no", "node_dec_throttle", "node_dec_inputs",
                     val="NO", exit_pos=(0.5, 1.0), entry_pos=(0.5, 0.0))

    # Input Empty Check branches (Binary)
    builder.add_edge("e_inputs_no", "node_dec_inputs", "node_err_input",
                     val="NO", exit_pos=(0.0, 0.5), entry_pos=(1.0, 0.5))
    builder.add_edge("e_inputs_err_to_end", "node_err_input", "node_end_input",
                     val="", exit_pos=(0.0, 0.5), entry_pos=(1.0, 0.5))
    builder.add_edge("e_inputs_yes", "node_dec_inputs", "node_dec_email_format",
                     val="YES", exit_pos=(0.5, 1.0), entry_pos=(0.5, 0.0))

    # Email Format Check branches (Binary)
    builder.add_edge("e_email_fmt_no", "node_dec_email_format", "node_err_email_format",
                     val="NO", exit_pos=(0.0, 0.5), entry_pos=(1.0, 0.5))
    builder.add_edge("e_email_fmt_err_to_end", "node_err_email_format", "node_end_email_format",
                     val="", exit_pos=(0.0, 0.5), entry_pos=(1.0, 0.5))
    builder.add_edge("e_email_fmt_yes", "node_dec_email_format", "node_dec_ldap_enabled",
                     val="YES", exit_pos=(0.5, 1.0), entry_pos=(0.5, 0.0))

    # LDAP Enabled Check branches (Binary)
    builder.add_edge("e_ldap_enabled_yes", "node_dec_ldap_enabled", "node_ldap_bind",
                     val="YES", exit_pos=(1.0, 0.5), entry_pos=(0.0, 0.5))
    builder.add_edge("e_ldap_enabled_no", "node_dec_ldap_enabled", "node_dec_ldap_off_is_ad",
                     val="NO (Disabled)", exit_pos=(0.5, 1.0), entry_pos=(0.5, 0.0))

    # LDAP Bind -> LDAP Success Check
    builder.add_edge("e_ldap_bind_to_check", "node_ldap_bind", "node_dec_ldap_success",
                     val="", exit_pos=(0.5, 1.0), entry_pos=(0.5, 0.0))

    # LDAP Success Check branches (Binary)
    builder.add_edge("e_ldap_success_yes", "node_dec_ldap_success", "node_ldap_sync",
                     val="YES", exit_pos=(1.0, 0.5), entry_pos=(0.0, 0.5))
    builder.add_edge("e_ldap_success_no", "node_dec_ldap_success", "node_dec_ldap_is_ad",
                     val="NO", exit_pos=(0.5, 1.0), entry_pos=(0.5, 0.0))

    # LDAP Sync -> LDAP Account Active Check
    builder.add_edge("e_ldap_sync_to_active", "node_ldap_sync", "node_dec_ldap_active",
                     val="", exit_pos=(0.5, 1.0), entry_pos=(0.5, 0.0))

    # LDAP Account Active Check branches (Binary)
    builder.add_edge("e_ldap_active_no", "node_dec_ldap_active", "node_err_ldap_disabled",
                     val="NO", exit_pos=(0.5, 1.0), entry_pos=(0.5, 0.0))
    builder.add_edge("e_ldap_err_dis_to_end", "node_err_ldap_disabled", "node_end_ldap_disabled",
                     val="", exit_pos=(0.5, 1.0), entry_pos=(0.5, 0.0))

    # LDAP Active YES -> Routes to Session Setup via Planar Right Channel at x = 1420
    # Merges into the spine collector junction at (550, 2210) above node_session_regen
    builder.add_edge("e_ldap_active_yes", "node_dec_ldap_active", "node_session_regen",
                     val="YES (Authenticated)", exit_pos=(1.0, 0.5), entry_pos=(0.5, 0.0),
                     points=[(1420, 1212.5), (1420, 2210), (550, 2210)])

    # LDAP isAdUser Check branches (Binary)
    builder.add_edge("e_ldap_is_ad_yes", "node_dec_ldap_is_ad", "node_err_ldap_ad_reject",
                     val="YES", exit_pos=(1.0, 0.5), entry_pos=(0.0, 0.5))
    builder.add_edge("e_ldap_err_ad_to_end", "node_err_ldap_ad_reject", "node_end_ldap_ad_reject",
                     val="", exit_pos=(0.5, 1.0), entry_pos=(0.5, 0.0))
    builder.add_edge("e_ldap_is_ad_no", "node_dec_ldap_is_ad", "node_dec_ldap_fallback",
                     val="NO", exit_pos=(0.5, 1.0), entry_pos=(0.5, 0.0))

    # LDAP Fallback Check branches (Binary)
    builder.add_edge("e_ldap_fallback_no", "node_dec_ldap_fallback", "node_err_ldap_no_fallback",
                     val="NO", exit_pos=(1.0, 0.5), entry_pos=(0.0, 0.5))
    builder.add_edge("e_ldap_err_fail_to_end", "node_err_ldap_no_fallback", "node_end_ldap_no_fallback",
                     val="", exit_pos=(0.5, 1.0), entry_pos=(0.5, 0.0))

    # LDAP Fallback YES -> Routes to DB findByEmail via Channel at x = 730
    # Merges into the spine collector junction at (550, 1715) above node_db_query
    builder.add_edge("e_ldap_fallback_yes", "node_dec_ldap_fallback", "node_db_query",
                     val="YES (Fallback)", exit_pos=(0.0, 0.5), entry_pos=(0.5, 0.0),
                     points=[(730, 1642.5), (730, 1715), (550, 1715)])

    # LDAP Disabled -> isAdUser Check branches (Binary)
    builder.add_edge("e_ldap_off_is_ad_yes", "node_dec_ldap_off_is_ad", "node_err_ldap_off_ad",
                     val="YES", exit_pos=(0.0, 0.5), entry_pos=(1.0, 0.5))
    builder.add_edge("e_ldap_off_err_to_end", "node_err_ldap_off_ad", "node_end_ldap_off_ad",
                     val="", exit_pos=(0.0, 0.5), entry_pos=(1.0, 0.5))
    builder.add_edge("e_ldap_off_is_ad_no", "node_dec_ldap_off_is_ad", "node_db_query",
                     val="NO", exit_pos=(0.5, 1.0), entry_pos=(0.5, 0.0))

    # DB findByEmail Query -> User Record Found Check
    builder.add_edge("e_db_to_user_found", "node_db_query", "node_dec_user_found",
                     val="", exit_pos=(0.5, 1.0), entry_pos=(0.5, 0.0))

    # User Record Found Check branches (Binary)
    builder.add_edge("e_user_found_no", "node_dec_user_found", "node_err_not_found",
                     val="NO", exit_pos=(0.0, 0.5), entry_pos=(1.0, 0.5))
    builder.add_edge("e_user_err_to_end", "node_err_not_found", "node_end_not_found",
                     val="", exit_pos=(0.0, 0.5), entry_pos=(1.0, 0.5))
    builder.add_edge("e_user_found_yes", "node_dec_user_found", "node_dec_status",
                     val="YES", exit_pos=(0.5, 1.0), entry_pos=(0.5, 0.0))

    # Account Status Check branches (Binary)
    builder.add_edge("e_status_no", "node_dec_status", "node_err_status",
                     val="NO", exit_pos=(0.0, 0.5), entry_pos=(1.0, 0.5))
    builder.add_edge("e_status_err_to_end", "node_err_status", "node_end_status",
                     val="", exit_pos=(0.0, 0.5), entry_pos=(1.0, 0.5))
    builder.add_edge("e_status_yes", "node_dec_status", "node_dec_password",
                     val="YES", exit_pos=(0.5, 1.0), entry_pos=(0.5, 0.0))

    # Password Matches Check branches (Binary)
    builder.add_edge("e_pwd_no", "node_dec_password", "node_err_password",
                     val="NO", exit_pos=(0.0, 0.5), entry_pos=(1.0, 0.5))
    builder.add_edge("e_pwd_err_to_end", "node_err_password", "node_end_password",
                     val="", exit_pos=(0.0, 0.5), entry_pos=(1.0, 0.5))
    builder.add_edge("e_pwd_yes", "node_dec_password", "node_session_regen",
                     val="YES", exit_pos=(0.5, 1.0), entry_pos=(0.5, 0.0))

    # Session Initialization -> must_change_password Check
    builder.add_edge("e_session_to_pwd_change", "node_session_regen", "node_dec_pwd_change",
                     val="", exit_pos=(0.5, 1.0), entry_pos=(0.5, 0.0))

    # must_change_password Check branches (Binary)
    builder.add_edge("e_pwd_change_yes", "node_dec_pwd_change", "node_redir_pwd_change",
                     val="YES", exit_pos=(1.0, 0.5), entry_pos=(0.0, 0.5))
    builder.add_edge("e_redir_pwd_to_end", "node_redir_pwd_change", "node_end_pwd_change",
                     val="", exit_pos=(1.0, 0.5), entry_pos=(0.0, 0.5))
    builder.add_edge("e_pwd_change_no", "node_dec_pwd_change", "node_redir_inbox",
                     val="NO", exit_pos=(0.5, 1.0), entry_pos=(0.5, 0.0))

    # Success Redirect -> Final Inbox END
    builder.add_edge("e_redir_to_inbox_end", "node_redir_inbox", "node_end_inbox",
                     val="", exit_pos=(0.5, 1.0), entry_pos=(0.5, 0.0))

    return builder


def save_drawio_file(builder, filepath):
    mxfile_el = ET.Element('mxfile', {
        'host': 'Electron',
        'modified': '2026-11-16T00:00:00.000Z',
        'agent': 'Couriva-FlowchartArchitect',
        'version': '21.0.0',
        'type': 'device'
    })
    mxfile_el.append(builder.to_xml_element())

    raw_xml = ET.tostring(mxfile_el, encoding='utf-8')
    parsed = minidom.parseString(raw_xml)
    pretty_xml = parsed.toprettyxml(indent="  ")

    os.makedirs(os.path.dirname(filepath), exist_ok=True)
    with open(filepath, 'w', encoding='utf-8') as f:
        f.write(pretty_xml)
    print(f"[OK] Generated uncompressed Draw.io file: {filepath}")


def save_svg_file(builder, filepath):
    svg_content = builder.to_svg()
    os.makedirs(os.path.dirname(filepath), exist_ok=True)
    with open(filepath, 'w', encoding='utf-8') as f:
        f.write(svg_content)
    print(f"[OK] Generated clean vector SVG: {filepath}")


def render_png_screenshot(svg_path, png_path, width=1500, height=2650):
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


def audit_drawio_file(filepath):
    lines_output = []
    def log(msg=""):
        lines_output.append(msg)
        print(msg)

    log("=" * 70)
    log(f"AUDITING DRAW.IO FILE: {os.path.basename(filepath)}")
    log("=" * 70)

    try:
        tree = ET.parse(filepath)
    except Exception as e:
        log(f"[FATAL] Failed to parse XML: {e}")
        return False, "\n".join(lines_output)

    root = tree.getroot()
    diagrams = root.findall('.//diagram')
    if not diagrams:
        log("[FATAL] No <diagram> elements found.")
        return False, "\n".join(lines_output)

    total_defects = 0
    total_vertices = 0
    total_edges = 0
    total_decisions = 0

    for idx, d in enumerate(diagrams, 1):
        d_name = d.attrib.get('name', f'Page {idx}')
        mx = d.find('.//mxGraphModel')
        root_cell = mx.find('.//root') if mx is not None else d.find('.//root')
        cells = root_cell.findall('.//mxCell') if root_cell is not None else []

        vertices = {}
        edges = []
        outgoing = {}
        incoming = {}

        for c in cells:
            cid = c.attrib.get('id')
            if c.attrib.get('vertex') == '1':
                val = c.attrib.get('value', '').replace('\n', ' ')
                style = c.attrib.get('style', '')
                geo = c.find('mxGeometry')
                x = float(geo.attrib.get('x', 0)) if geo is not None else 0
                y = float(geo.attrib.get('y', 0)) if geo is not None else 0
                w = float(geo.attrib.get('width', 0)) if geo is not None else 0
                h = float(geo.attrib.get('height', 0)) if geo is not None else 0
                vertices[cid] = {'val': val, 'style': style, 'x': x, 'y': y, 'w': w, 'h': h}
                outgoing[cid] = []
                incoming[cid] = []
            elif c.attrib.get('edge') == '1':
                src = c.attrib.get('source')
                tgt = c.attrib.get('target')
                val = c.attrib.get('value', '')
                edges.append({'id': cid, 'src': src, 'tgt': tgt, 'val': val})

        for e in edges:
            src = e['src']
            tgt = e['tgt']
            if src in outgoing:
                outgoing[src].append(e)
            if tgt in incoming:
                incoming[tgt].append(e)

        page_defects = []

        # Gate 1 & 2: Rhombus Binary & Labeling
        page_rhombuses = 0
        for vid, vdata in vertices.items():
            if 'rhombus' in vdata['style']:
                page_rhombuses += 1
                out_edges = outgoing.get(vid, [])
                if len(out_edges) != 2:
                    page_defects.append(
                        f"  [G1-FAIL] Decision '{vdata['val']}' (ID: {vid}) has {len(out_edges)} outgoing branches (Mandate: strictly 2)."
                    )
                for oe in out_edges:
                    if not oe['val'].strip():
                        page_defects.append(
                            f"  [G2-FAIL] Decision branch from '{vdata['val']}' to '{vertices.get(oe['tgt'], {}).get('val')}' has NO label."
                        )

        # Gate 3: Dangling Edges
        for e in edges:
            if not e['src'] or e['src'] not in vertices:
                page_defects.append(f"  [G3-FAIL] Edge ID {e['id']} has invalid/missing source: {e['src']}")
            if not e['tgt'] or e['tgt'] not in vertices:
                page_defects.append(f"  [G3-FAIL] Edge ID {e['id']} has invalid/missing target: {e['tgt']}")

        # Gate 7: Dead-End Sinks
        for vid, vdata in vertices.items():
            style = vdata['style']
            if any(term in style for term in ('arcSize=50', 'offPageConnector', 'text')):
                continue
            if 'ellipse' in style and len(vdata['val']) <= 3:
                continue
            if len(outgoing.get(vid, [])) == 0:
                page_defects.append(f"  [G7-FAIL] Dead-end unhandled node '{vdata['val']}' (ID: {vid}) with 0 outgoing edges.")

        # Gate 8: Geometric Overlap & Collision Check
        vlist = list(vertices.items())
        for i in range(len(vlist)):
            id1, v1 = vlist[i]
            if 'text;' in v1['style']:
                continue
            for j in range(i + 1, len(vlist)):
                id2, v2 = vlist[j]
                if 'text;' in v2['style']:
                    continue
                # Check AABB intersection
                if (v1['x'] < v2['x'] + v2['w'] and v1['x'] + v1['w'] > v2['x'] and
                    v1['y'] < v2['y'] + v2['h'] and v1['y'] + v1['h'] > v2['y']):
                    page_defects.append(
                        f"  [G8-FAIL] Collision: Node '{v1['val']}' (ID: {id1}) overlaps '{v2['val']}' (ID: {id2})"
                    )

        total_vertices += len(vertices)
        total_edges += len(edges)
        total_decisions += page_rhombuses
        total_defects += len(page_defects)

        log(f"[{idx:02d}] {d_name}")
        log(f"     Vertices: {len(vertices)} | Edges: {len(edges)} | Decisions: {page_rhombuses}")
        if page_defects:
            for dft in page_defects:
                log(dft)
        else:
            log("     -> Quality Gates Status: PASSED (0 defects)")

    log("-" * 70)
    log(f"AUDIT SUMMARY: {len(diagrams)} Diagrams | {total_vertices} Nodes | {total_edges} Edges | {total_decisions} Decisions")
    log(f"TOTAL DEFECTS FOUND: {total_defects}")
    log("RESULT: " + ("PASS" if total_defects == 0 else "FAIL"))
    log("=" * 70)

    return total_defects == 0, "\n".join(lines_output)


def main():
    target_drawio = "/opt/lampp/htdocs/Web_Mail_System/docs/flowcharts/login_flowchart.drawio"
    target_svg = "/opt/lampp/htdocs/Web_Mail_System/docs/flowcharts/login_flowchart.svg"
    target_report = "/opt/lampp/htdocs/Web_Mail_System/docs/flowcharts/audit_report.txt"
    target_screenshot = "/opt/lampp/htdocs/Web_Mail_System/docs/screenshots/04_flowchart_ansi_clean.png"

    print("Generating Couriva Login Flowchart...")
    builder = build_login_flowchart()
    save_drawio_file(builder, target_drawio)
    save_svg_file(builder, target_svg)

    print("\nAuditing Flowchart against ANSI X3.5 / ISO 5807 Zero-Defect Gates...")
    success, audit_log = audit_drawio_file(target_drawio)

    with open(target_report, 'w', encoding='utf-8') as f:
        f.write(audit_log)
    print(f"\n[OK] Saved audit report to: {target_report}")

    if not success:
        print("[ERROR] Audit failed with defects!")
        sys.exit(1)
    else:
        print("[SUCCESS] Flowchart achieved 100% Zero-Defect compliance!")

    print("\nRendering high-res screenshot PNG via Chromium headless...")
    render_png_screenshot(target_svg, target_screenshot, width=1500, height=2650)


if __name__ == '__main__':
    main()
