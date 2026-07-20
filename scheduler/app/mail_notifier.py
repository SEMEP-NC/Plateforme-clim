import smtplib
import traceback

from email.mime.text import MIMEText
from email.mime.multipart import MIMEMultipart
from db import get_connection

def get_mail_account(cur):
    """
    Lecture configuration SMTP
    """
    cur.execute("""
        SELECT
            id,
            smtp_host,
            smtp_port,
            smtp_user,
            smtp_password,
            smtp_secure,
            sender_name,
            sender_email
        FROM mail_accounts
        WHERE id = 1
          AND enabled = 1
        LIMIT 1
    """)
    return cur.fetchone()

def get_recipients(cur):
    """
    Récupération utilisateurs qui veulent recevoir les défauts
    Nécessite une colonne :
    users.notify_faults TINYINT(1)
    """
    cur.execute("""
        SELECT email
        FROM users
        WHERE notify_faults = 1
          AND email IS NOT NULL
          AND email <> ''
    """)
    rows = cur.fetchall()
    return [
        row["email"]
        for row in rows
    ]

def get_pending_faults(cur):
    """
    Défauts non encore notifiés
    """
    cur.execute("""
        SELECT
            h.id,
            h.equipment_id,
            h.fault_code,
            h.fault_name,
            h.active,
            h.created_at,
            e.name,
            e.localisation,
            e.UI
        FROM equipment_fault_history h
        INNER JOIN equipments e
            ON e.id = h.equipment_id
        WHERE h.mail_sent = 0
        ORDER BY h.created_at ASC
    """)
    return cur.fetchall()

def build_mail(faults):
    """
    Création du contenu mail HTML
    """
    active_faults = []
    cleared_faults = []
    for fault in faults:
        item = f"""
        <tr>
            <td>{fault['UI']}</td>
            <td>{fault['name']}</td>
            <td>{fault['localisation'] or ''}</td>
            <td>{fault['fault_code']}</td>
            <td>{fault['fault_name']}</td>
            <td>{fault['created_at']}</td>
        </tr>
        """
        if fault["active"]:
            active_faults.append(item)
        else:
            cleared_faults.append(item)
    if active_faults and cleared_faults:
        subject = "[HVAC] Défauts et retours à la normale"
    elif active_faults:
        subject = "[HVAC] Défaut équipement"
    else:
        subject = "[HVAC] Retour à la normale"
    html = f"""
<html>
    <body>
        <h2>Supervision HVAC</h2>
        <h3>Evènements</h3>
        <table border="1"
            cellpadding="6"
            cellspacing="0">
            <tr>
                <th>UI</th>
                <th>Nom</th>
                <th>Localisation</th>
                <th>Code</th>
                <th>Défaut</th>
                <th>Date</th>
            </tr>
            {''.join(active_faults)}
            {''.join(cleared_faults)}
        </table>
    </body>
</html>
"""
    return subject, html

def send_mail(account, recipients, subject, html):
    """
    Envoi SMTP
    """
    msg = MIMEMultipart("alternative")
    sender = account["sender_email"]
    if account["sender_name"]:
        msg["From"] = (
            f"{account['sender_name']} <{sender}>"
        )
    else:
        msg["From"] = sender
    msg["To"] = ", ".join(recipients)
    msg["Subject"] = subject
    msg.attach(
        MIMEText(
            html,
            "html",
            "utf-8"
        )
    )
    smtp = smtplib.SMTP(
        account["smtp_host"],
        int(account["smtp_port"]),
        timeout=10
    )
    if account["smtp_secure"] == "tls":
        smtp.starttls()
    if account["smtp_user"]:
        smtp.login(
            account["smtp_user"],
            account["smtp_password"]
        )
    smtp.sendmail(
        sender,
        recipients,
        msg.as_string()
    )
    smtp.quit()

def mark_as_sent(cur, fault_ids):
    for fault_id in fault_ids:
        cur.execute("""
            UPDATE equipment_fault_history
            SET
                mail_sent = 1,
                mail_sent_at = NOW()
            WHERE id=%s
        """, (
            fault_id,
        ))

def check_and_send():
    """
    Fonction appelée par scheduler.py
    """
    conn = None
    try:
        conn = get_connection()
        cur = conn.cursor()
        account = get_mail_account(cur)
        if not account:
            print(
                "[MAIL] Aucun compte SMTP actif",
                flush=True
            )
            return
        recipients = get_recipients(cur)
        if not recipients:
            print(
                "[MAIL] Aucun destinataire",
                flush=True
            )
            return
        faults = get_pending_faults(cur)
        if not faults:
            return
        subject, html = build_mail(faults)
        send_mail(
            account,
            recipients,
            subject,
            html
        )
        ids = [
            f["id"]
            for f in faults
        ]
        mark_as_sent(
            cur,
            ids
        )
        conn.commit()
        print(
            f"[MAIL] Notification envoyée ({len(ids)} événements)",
            flush=True
        )
    except Exception as e:
        print(
            "[MAIL ERROR]",
            e,
            flush=True
        )
        traceback.print_exc()
    finally:
        if conn:
            conn.close()

            
def build_test_mail():

    subject = "[HVAC] Test de configuration SMTP"

    html = """
    <html>
    <body style="font-family:Arial,sans-serif">

        <h2>Supervision HVAC</h2>

        <p>Ce message confirme que la configuration SMTP est correcte.</p>

        <table border="1" cellpadding="6" cellspacing="0">
            <tr>
                <td><b>Statut</b></td>
                <td style="color:green;">OK</td>
            </tr>
        </table>

        <br>

        <p>
            Si vous recevez ce message,
            les notifications de défaut fonctionneront correctement.
        </p>

    </body>
    </html>
    """

    return subject, html


def send_test_mail():

    conn = None

    try:

        conn = get_connection()
        cur = conn.cursor()

        account = get_mail_account(cur)

        if not account:
            return False, "Aucun compte SMTP actif"

        recipients = get_recipients(cur)

        if not recipients:
            return False, "Aucun destinataire configuré"

        subject, html = build_test_mail()

        send_mail(
            account,
            recipients,
            subject,
            html
        )

        return True, f"Mail envoyé à {', '.join(recipients)}"

    except Exception as e:

        traceback.print_exc()

        return False, str(e)

    finally:

        if conn:
            conn.close()
            