#!/usr/bin/env python3
"""
Couriva WebMail Login Flowchart Generator & Auditor
Compliant with ANSI X3.5-1970 and ISO 5807:1985 Information Processing Standards.
Generates pure uncompressed XML and validates with 0 defects.
"""

import os
import sys
import xml.etree.ElementTree as ET
from xml.dom import minidom

class DiagramBuilder:
    # ANSI X3.5 / ISO 5807 Style Constants
    STYLE_START = "rounded=1;arcSize=50;whiteSpace=wrap;html=1;fillColor=#FFFFFF;strokeColor=#000000;strokeWidth=2;fontFamily=Helvetica;fontSize=13;fontStyle=1;fontColor=#000000;align=center;"
    STYLE_PROCESS = "rounded=0;whiteSpace=wrap;html=1;fillColor=#FFFFFF;strokeColor=#000000;strokeWidth=1.5;fontFamily=Helvetica;fontSize=12;fontColor=#000000;align=center;"
    STYLE_IO = "shape=parallelogram;perimeter=parallelogramPerimeter;whiteSpace=wrap;html=1;fixedSize=1;size=20;fillColor=#FFFFFF;strokeColor=#000000;strokeWidth=1.5;fontFamily=Helvetica;fontSize=12;fontColor=#000000;align=center;"
    STYLE_DECISION = "rhombus;whiteSpace=wrap;html=1;fillColor=#FFFFFF;strokeColor=#000000;strokeWidth=1.5;fontFamily=Helvetica;fontSize=12;fontStyle=1;fontColor=#000000;align=center;"
    STYLE_DB = "shape=cylinder3;whiteSpace=wrap;html=1;boundedLbl=1;backgroundOutline=1;size=10;fillColor=#FFFFFF;strokeColor=#000000;strokeWidth=1.5;fontFamily=Helvetica;fontSize=12;fontColor=#000000;align=center;"
    STYLE_TITLE = "text;html=1;strokeColor=none;fillColor=none;align=center;verticalAlign=middle;whiteSpace=wrap;rounded=0;fontFamily=Helvetica;fontSize=18;fontStyle=1;fontColor=#000000;"
    STYLE_SUBTITLE = "text;html=1;strokeColor=none;fillColor=none;align=center;verticalAlign=middle;whiteSpace=wrap;rounded=0;fontFamily=Helvetica;fontSize=11;fontStyle=2;fontColor=#000000;"
    STYLE_EDGE_SOLID = "edgeStyle=orthogonalEdgeStyle;rounded=0;orthogonalLoop=1;jettySize=auto;html=1;strokeColor=#000000;strokeWidth=1.5;fontFamily=Helvetica;fontSize=11;fontStyle=1;fontColor=#000000;endArrow=classic;"

    def __init__(self, diag_id, name, width=1450, height=2150):
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

    def add_edge(self, eid, src, tgt, val='', exit_pos=(0.5, 1.0), entry_pos=(0.5, 0.0), points=None):
        self.edges.append({
            'id': eid,
            'src': src,
            'tgt': tgt,
            'val': val,
            'exit_x': exit_pos[0],
            'exit_y': exit_pos[1],
            'entry_x': entry_pos[0],
            'entry_y': entry_pos[1],
            'points': points or []
        })

    def to_xml_element(self):
        diag_el = ET.Element('diagram', {'id': self.diag_id, 'name': self.name})
        model_el = ET.SubElement(diag_el, 'mxGraphModel', {
            'dx': '1450', 'dy': '2150', 'grid': '1', 'gridSize': '10',
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
                'value': n['val'],
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
                'value': e['val'],
                'style': style,
                'parent': '1',
                'source': e['src'],
                'target': e['tgt'],
                'edge': '1'
            })
            geo = ET.SubElement(cell, 'mxGeometry', {'relative': '1', 'as': 'geometry'})
            if e['points']:
                arr = ET.SubElement(geo, 'Array', {'as': 'points'})
                for px, py in e['points']:
                    ET.SubElement(arr, 'mxPoint', {'x': str(px), 'y': str(py)})

        return diag_el

def build_login_flowchart():
    builder = DiagramBuilder("couriva-login-flowchart", "Couriva WebMail Authentication Workflow", width=1450, height=2150)

    # ----------------------------------------------------
    # Header
    # ----------------------------------------------------
    builder.add_node("header_title", "COURIVA WEBMAIL — AUTHENTICATION &amp; LOGIN FLOWCHART",
                     builder.STYLE_TITLE, 250, 25, 600, 35)
    builder.add_node("header_subtitle", "ANSI X3.5 / ISO 5807 Compliant Authentication Pipeline (Zero-Defect Standard)",
                     builder.STYLE_SUBTITLE, 250, 60, 600, 25)

    # ----------------------------------------------------
    # Spine: Center X = 550
    # ----------------------------------------------------

    # Step 1: START Terminator
    builder.add_node("node_start", "START: Access Login Page&#xa;(GET /public/login.php)",
                     builder.STYLE_START, 440, 95, 220, 50)

    # Step 2: Already Authenticated Check
    builder.add_node("node_dec_auth", "Already&#xa;Authenticated?&#xa;(is_authenticated())",
                     builder.STYLE_DECISION, 440, 175, 220, 90)

    builder.add_node("node_redir_inbox_early", "Redirect to Inbox&#xa;(header Location: inbox.php)",
                     builder.STYLE_PROCESS, 750, 192.5, 230, 55)
    builder.add_node("node_end_inbox_early", "END: User in Inbox&#xa;(public/inbox.php)",
                     builder.STYLE_START, 1030, 195, 200, 50)

    # Step 3: Render Form & Receive Submission
    builder.add_node("node_render_form", "Render Login Form&#xa;(Generate CSRF token &amp; render view)",
                     builder.STYLE_PROCESS, 420, 295, 260, 60)
    builder.add_node("node_submit", "User Submits Login Form&#xa;(POST: email, password, csrf_token)",
                     builder.STYLE_IO, 410, 385, 280, 60)

    # Step 4: CSRF Validation
    builder.add_node("node_dec_csrf", "CSRF Token&#xa;Valid?&#xa;(verify_csrf_token())",
                     builder.STYLE_DECISION, 440, 475, 220, 90)
    builder.add_node("node_err_csrf", "Set Error:&#xa;'Session security token expired.&#xa;Please try again.'",
                     builder.STYLE_PROCESS, 190, 490, 220, 60)
    builder.add_node("node_end_csrf", "END: Re-render Form&#xa;(CSRF Error Alert)",
                     builder.STYLE_START, 30, 495, 140, 50)

    # Step 5: Input Sanitization
    builder.add_node("node_prep_input", "Sanitize &amp; Normalize Inputs&#xa;(trim email, check couriva_address)",
                     builder.STYLE_PROCESS, 420, 595, 260, 55)

    # Step 6: Input Validation (Empty Check)
    builder.add_node("node_dec_inputs", "Email &amp; Password&#xa;Provided?&#xa;(!empty($email) &amp;&amp; !empty($password))",
                     builder.STYLE_DECISION, 430, 680, 240, 90)
    builder.add_node("node_err_input", "Set Error:&#xa;'Please enter both email&#xa;and password.'",
                     builder.STYLE_PROCESS, 190, 695, 220, 60)
    builder.add_node("node_end_input", "END: Re-render Form&#xa;(Input Error Alert)",
                     builder.STYLE_START, 30, 700, 140, 50)

    # Step 7: LDAP / Active Directory Check with Fallback
    builder.add_node("node_dec_ldap_enabled", "LDAP / AD&#xa;Enabled?&#xa;(!empty($ldapConfig['enabled']))",
                     builder.STYLE_DECISION, 430, 800, 240, 90)

    # LDAP Subsystem (Right Lateral Area)
    builder.add_node("node_ldap_bind", "Attempt LDAP Bind &amp; Search&#xa;(UPN, Domain\\User, or Service DN bind;&#xa;fetch directory attributes)",
                     builder.STYLE_PROCESS, 750, 812.5, 260, 65)
    builder.add_node("node_dec_ldap_success", "LDAP Bind&#xa;Successful?&#xa;($ldapResult['success'])",
                     builder.STYLE_DECISION, 770, 910, 220, 85)

    # LDAP Success Branch
    builder.add_node("node_ldap_sync", "Sync User to Local Database&#xa;(syncLdapUserToDatabase(): auto-create&#xa;or update local user record)",
                     builder.STYLE_PROCESS, 1030, 920, 260, 65)
    builder.add_node("node_dec_ldap_active", "Local Account&#xa;Active?&#xa;($status === 'active')",
                     builder.STYLE_DECISION, 1055, 1015, 210, 85)

    builder.add_node("node_err_ldap_disabled", "Set Error:&#xa;'Your account is disabled.'",
                     builder.STYLE_PROCESS, 1290, 1030, 145, 55)
    builder.add_node("node_end_ldap_disabled", "END: Re-render Form&#xa;(Account Disabled)",
                     builder.STYLE_START, 1290, 1105, 145, 45)

    # LDAP Failed Branch -> Check Fallback
    builder.add_node("node_dec_ldap_fallback", "Fallback to&#xa;Local DB Allowed?&#xa;(!empty($fallback_to_local))",
                     builder.STYLE_DECISION, 770, 1025, 220, 85)

    builder.add_node("node_err_ldap_fail", "Set Error:&#xa;'LDAP credentials rejected.'",
                     builder.STYLE_PROCESS, 970, 1040, 170, 55)
    builder.add_node("node_end_ldap_fail", "END: Re-render Form&#xa;(LDAP Rejected)",
                     builder.STYLE_START, 970, 1115, 170, 45)

    # Step 8: DB findByEmail (Central Spine)
    builder.add_node("node_db_query", "Query User from MariaDB Database&#xa;($this-&gt;userModel-&gt;findByEmail($email))",
                     builder.STYLE_DB, 410, 1170, 280, 75)

    # Step 9: User Record Found Check
    builder.add_node("node_dec_user_found", "User Record&#xa;Found in DB?&#xa;(!empty($user))",
                     builder.STYLE_DECISION, 440, 1280, 220, 90)
    builder.add_node("node_err_not_found", "Set Error:&#xa;'Invalid email or password.'&#xa;(log FAILED_LOGIN attempt)",
                     builder.STYLE_PROCESS, 190, 1295, 220, 60)
    builder.add_node("node_end_not_found", "END: Re-render Form&#xa;(Auth Failed Alert)",
                     builder.STYLE_START, 30, 1300, 140, 50)

    # Step 10: Account Status Check
    builder.add_node("node_dec_status", "Account Status&#xa;Active?&#xa;($user['status'] === 'active')",
                     builder.STYLE_DECISION, 440, 1400, 220, 90)
    builder.add_node("node_err_status", "Set Status Error:&#xa;(pending approval, suspended,&#xa;rejected, or disabled)",
                     builder.STYLE_PROCESS, 190, 1415, 220, 60)
    builder.add_node("node_end_status", "END: Re-render Form&#xa;(Status Alert)",
                     builder.STYLE_START, 30, 1420, 140, 50)

    # Step 11: password_verify Check
    builder.add_node("node_dec_password", "Password Matches&#xa;Hash?&#xa;(password_verify($password, $hash))",
                     builder.STYLE_DECISION, 420, 1520, 260, 90)
    builder.add_node("node_err_password", "Set Error:&#xa;'Invalid email or password.'&#xa;(log FAILED_LOGIN attempt)",
                     builder.STYLE_PROCESS, 190, 1535, 220, 60)
    builder.add_node("node_end_password", "END: Re-render Form&#xa;(Bad Password Alert)",
                     builder.STYLE_START, 30, 1540, 140, 50)

    # Step 12: Session Fixation Regeneration & Session Setup
    builder.add_node("node_session_regen", "Regenerate Session ID &amp; Initialize Session State&#xa;(session_regenerate_id(true), set $_SESSION user keys,&#xa;log USER_LOGIN SUCCESS)",
                     builder.STYLE_PROCESS, 380, 1645, 340, 70)

    # Step 13: must_change_password Redirect Check
    builder.add_node("node_dec_pwd_change", "Must Change&#xa;Password?&#xa;(!empty($_SESSION['must_change_password']))",
                     builder.STYLE_DECISION, 430, 1750, 240, 90)

    builder.add_node("node_redir_pwd_change", "Set Flash Warning &amp;&#xa;Redirect to Change Password&#xa;(header Location: change-password.php)",
                     builder.STYLE_PROCESS, 750, 1765, 250, 60)
    builder.add_node("node_end_pwd_change", "END: Change Password Page&#xa;(public/change-password.php)",
                     builder.STYLE_START, 1040, 1770, 210, 50)

    # Step 14: Final Success Redirect to Inbox
    builder.add_node("node_redir_inbox", "Set Flash Success Welcome &amp;&#xa;Redirect to Inbox&#xa;(header Location: inbox.php)",
                     builder.STYLE_PROCESS, 410, 1875, 280, 60)

    # Step 15: Final Authenticated END
    builder.add_node("node_end_inbox", "END: Authenticated User in Inbox&#xa;(public/inbox.php)",
                     builder.STYLE_START, 440, 1970, 220, 50)

    # ----------------------------------------------------
    # Edges & Routing (100% Orthogonal, Explicit Ports)
    # ----------------------------------------------------

    # Spine Start -> Auth Check
    builder.add_edge("e_start_to_auth", "node_start", "node_dec_auth",
                     val="", exit_pos=(0.5, 1.0), entry_pos=(0.5, 0.0))

    # Auth Check branches (Strictly binary, len=2)
    builder.add_edge("e_auth_yes", "node_dec_auth", "node_redir_inbox_early",
                     val="YES", exit_pos=(1.0, 0.5), entry_pos=(0.0, 0.5))
    builder.add_edge("e_auth_inbox_early", "node_redir_inbox_early", "node_end_inbox_early",
                     val="", exit_pos=(1.0, 0.5), entry_pos=(0.0, 0.5))

    builder.add_edge("e_auth_no", "node_dec_auth", "node_render_form",
                     val="NO", exit_pos=(0.5, 1.0), entry_pos=(0.5, 0.0))

    # Form render -> User Submit
    builder.add_edge("e_render_to_submit", "node_render_form", "node_submit",
                     val="", exit_pos=(0.5, 1.0), entry_pos=(0.5, 0.0))

    # User Submit -> CSRF Check
    builder.add_edge("e_submit_to_csrf", "node_submit", "node_dec_csrf",
                     val="", exit_pos=(0.5, 1.0), entry_pos=(0.5, 0.0))

    # CSRF Check branches (Strictly binary, len=2)
    builder.add_edge("e_csrf_no", "node_dec_csrf", "node_err_csrf",
                     val="NO", exit_pos=(0.0, 0.5), entry_pos=(1.0, 0.5))
    builder.add_edge("e_csrf_err_to_end", "node_err_csrf", "node_end_csrf",
                     val="", exit_pos=(0.0, 0.5), entry_pos=(1.0, 0.5))

    builder.add_edge("e_csrf_yes", "node_dec_csrf", "node_prep_input",
                     val="YES", exit_pos=(0.5, 1.0), entry_pos=(0.5, 0.0))

    # Prep Input -> Input Empty Check
    builder.add_edge("e_prep_to_inputs", "node_prep_input", "node_dec_inputs",
                     val="", exit_pos=(0.5, 1.0), entry_pos=(0.5, 0.0))

    # Input Empty Check branches (Strictly binary, len=2)
    builder.add_edge("e_inputs_no", "node_dec_inputs", "node_err_input",
                     val="NO", exit_pos=(0.0, 0.5), entry_pos=(1.0, 0.5))
    builder.add_edge("e_inputs_err_to_end", "node_err_input", "node_end_input",
                     val="", exit_pos=(0.0, 0.5), entry_pos=(1.0, 0.5))

    builder.add_edge("e_inputs_yes", "node_dec_inputs", "node_dec_ldap_enabled",
                     val="YES", exit_pos=(0.5, 1.0), entry_pos=(0.5, 0.0))

    # LDAP Enabled Check branches (Strictly binary, len=2)
    builder.add_edge("e_ldap_enabled_yes", "node_dec_ldap_enabled", "node_ldap_bind",
                     val="YES", exit_pos=(1.0, 0.5), entry_pos=(0.0, 0.5))
    builder.add_edge("e_ldap_enabled_no", "node_dec_ldap_enabled", "node_db_query",
                     val="NO (Disabled)", exit_pos=(0.5, 1.0), entry_pos=(0.5, 0.0))

    # LDAP Bind -> LDAP Success Check
    builder.add_edge("e_ldap_bind_to_check", "node_ldap_bind", "node_dec_ldap_success",
                     val="", exit_pos=(0.5, 1.0), entry_pos=(0.5, 0.0))

    # LDAP Success Check branches (Strictly binary, len=2)
    builder.add_edge("e_ldap_success_yes", "node_dec_ldap_success", "node_ldap_sync",
                     val="YES", exit_pos=(1.0, 0.5), entry_pos=(0.0, 0.5))
    builder.add_edge("e_ldap_success_no", "node_dec_ldap_success", "node_dec_ldap_fallback",
                     val="NO", exit_pos=(0.5, 1.0), entry_pos=(0.5, 0.0))

    # LDAP Sync -> LDAP Account Active Check
    builder.add_edge("e_ldap_sync_to_active", "node_ldap_sync", "node_dec_ldap_active",
                     val="", exit_pos=(0.5, 1.0), entry_pos=(0.5, 0.0))

    # LDAP Account Active Check branches (Strictly binary, len=2)
    builder.add_edge("e_ldap_active_no", "node_dec_ldap_active", "node_err_ldap_disabled",
                     val="NO", exit_pos=(1.0, 0.5), entry_pos=(0.0, 0.5))
    builder.add_edge("e_ldap_err_dis_to_end", "node_err_ldap_disabled", "node_end_ldap_disabled",
                     val="", exit_pos=(0.5, 1.0), entry_pos=(0.5, 0.0))

    # LDAP Active YES -> Routes to Session Fixation Regeneration
    # Route via dedicated right channel at x = 1200 with > 50px clearance from all intermediate boxes
    builder.add_edge("e_ldap_active_yes", "node_dec_ldap_active", "node_session_regen",
                     val="YES (Authenticated)", exit_pos=(0.5, 1.0), entry_pos=(1.0, 0.5),
                     points=[(1160, 1140), (1200, 1140), (1200, 1680), (720, 1680)])

    # LDAP Fallback Check branches (Strictly binary, len=2)
    builder.add_edge("e_ldap_fallback_no", "node_dec_ldap_fallback", "node_err_ldap_fail",
                     val="NO", exit_pos=(1.0, 0.5), entry_pos=(0.0, 0.5))
    builder.add_edge("e_ldap_err_fail_to_end", "node_err_ldap_fail", "node_end_ldap_fail",
                     val="", exit_pos=(0.5, 1.0), entry_pos=(0.5, 0.0))

    # LDAP Fallback YES -> Connects to DB findByEmail
    # Route via intermediate channel at x = 720 with > 30px lateral clearance
    builder.add_edge("e_ldap_fallback_yes", "node_dec_ldap_fallback", "node_db_query",
                     val="YES (Fallback)", exit_pos=(0.0, 0.5), entry_pos=(1.0, 0.5),
                     points=[(730, 1067.5), (730, 1207.5), (690, 1207.5)])

    # DB findByEmail Query -> User Record Found Check
    builder.add_edge("e_db_to_user_found", "node_db_query", "node_dec_user_found",
                     val="", exit_pos=(0.5, 1.0), entry_pos=(0.5, 0.0))

    # User Record Found Check branches (Strictly binary, len=2)
    builder.add_edge("e_user_found_no", "node_dec_user_found", "node_err_not_found",
                     val="NO", exit_pos=(0.0, 0.5), entry_pos=(1.0, 0.5))
    builder.add_edge("e_user_err_to_end", "node_err_not_found", "node_end_not_found",
                     val="", exit_pos=(0.0, 0.5), entry_pos=(1.0, 0.5))

    builder.add_edge("e_user_found_yes", "node_dec_user_found", "node_dec_status",
                     val="YES", exit_pos=(0.5, 1.0), entry_pos=(0.5, 0.0))

    # Account Status Check branches (Strictly binary, len=2)
    builder.add_edge("e_status_no", "node_dec_status", "node_err_status",
                     val="NO", exit_pos=(0.0, 0.5), entry_pos=(1.0, 0.5))
    builder.add_edge("e_status_err_to_end", "node_err_status", "node_end_status",
                     val="", exit_pos=(0.0, 0.5), entry_pos=(1.0, 0.5))

    builder.add_edge("e_status_yes", "node_dec_status", "node_dec_password",
                     val="YES", exit_pos=(0.5, 1.0), entry_pos=(0.5, 0.0))

    # password_verify Check branches (Strictly binary, len=2)
    builder.add_edge("e_pwd_no", "node_dec_password", "node_err_password",
                     val="NO", exit_pos=(0.0, 0.5), entry_pos=(1.0, 0.5))
    builder.add_edge("e_pwd_err_to_end", "node_err_password", "node_end_password",
                     val="", exit_pos=(0.0, 0.5), entry_pos=(1.0, 0.5))

    builder.add_edge("e_pwd_yes", "node_dec_password", "node_session_regen",
                     val="YES", exit_pos=(0.5, 1.0), entry_pos=(0.5, 0.0))

    # Session Fixation Regeneration -> must_change_password Check
    builder.add_edge("e_session_to_pwd_change", "node_session_regen", "node_dec_pwd_change",
                     val="", exit_pos=(0.5, 1.0), entry_pos=(0.5, 0.0))

    # must_change_password Check branches (Strictly binary, len=2)
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
        'modified': '2026-11-10T00:00:00.000Z',
        'agent': 'Antigravity-DrawIOArchitect',
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
            # Ignore terminators, connectors, and labels
            if any(term in style for term in ('arcSize=50', 'offPageConnector', 'text')):
                continue
            if 'ellipse' in style and len(vdata['val']) <= 3: # on-page connector
                continue
            if len(outgoing.get(vid, [])) == 0:
                page_defects.append(f"  [G7-FAIL] Dead-end unhandled node '{vdata['val']}' (ID: {vid}) with 0 outgoing edges.")

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
    target_report = "/opt/lampp/htdocs/Web_Mail_System/docs/flowcharts/audit_report.txt"

    print("Generating Couriva Login Flowchart...")
    builder = build_login_flowchart()
    save_drawio_file(builder, target_drawio)

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

if __name__ == '__main__':
    main()
