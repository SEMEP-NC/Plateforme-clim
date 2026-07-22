import smtplib
import traceback

from email.mime.text import MIMEText
from email.mime.multipart import MIMEMultipart
from db import get_connection
from datetime import timedelta, timezone

LOCAL_TZ = timezone(timedelta(hours=11))

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
        FROM mail_recipients
        WHERE enabled = 1
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

    rows = []

    active_count = 0
    cleared_count = 0

    for fault in faults:

        if fault["active"]:
            status = '<span style="color:#d9534f;font-weight:bold;">Actif</span>'
            active_count += 1
        else:
            status = '<span style="color:#28a745;font-weight:bold;">Inactif</span>'
            cleared_count += 1

        created = fault["created_at"]

        if created.tzinfo is None:
            created = created.replace(tzinfo=timezone.utc)

        created = created.astimezone(LOCAL_TZ)

        created = created.strftime("%d/%m/%Y %H:%M:%S")

        rows.append(f"""
        <tr>
            <td>{fault['UI']}</td>
            <td>{fault['name']}</td>
            <td>{fault['localisation'] or ''}</td>
            <td>{fault['fault_name']}</td>
            <td>{status}</td>
            <td>{created}</td>
        </tr>
        """)

    if active_count and cleared_count:
        subject = "[Climatisation] Défauts et retours à la normale"
    elif active_count:
        subject = "[Climatisation] Défaut équipement"
    else:
        subject = "[Climatisation] Retour à la normale"

    html = f"""
<html>
<body style="font-family:Arial,sans-serif;">

<h2>Supervision Climatisation</h2>

<p>
<b>{active_count}</b> défaut(s) actif(s) -
<b>{cleared_count}</b> retour(s) à la normale
</p>

<table border="1" cellpadding="6" cellspacing="0" style="border-collapse:collapse;">
    <tr style="background:#f2f2f2;">
        <th>UI</th>
        <th>Nom</th>
        <th>Localisation</th>
        <th>Défaut</th>
        <th>État</th>
        <th>Date (UTC+11)</th>
    </tr>
    {''.join(rows)}
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

    subject = "Test de configuration SMTP"

    html = """
    <html>
    <body style="font-family:Arial,sans-serif">

        <h2>Supervision Climatisation GREE</h2>

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
            
def check_mail_queue():

    conn=None

    try:

        conn=get_connection()
        cur=conn.cursor()

        cur.execute("""
            SELECT *
            FROM mail_queue
            WHERE processed=0
            ORDER BY id
            LIMIT 1
        """)

        job=cur.fetchone()

        if not job:
            return


        if job["type"]=="TEST":

            account=get_mail_account(cur)

            if not account:
                print("[MAIL] SMTP absent")
                return


            recipients=get_recipients(cur)

            if not recipients:
                print("[MAIL] aucun destinataire")
                return


            subject,html=build_test_mail()


            send_mail(
                account,
                recipients,
                subject,
                html
            )


            print(
                "[MAIL] Test envoyé",
                flush=True
            )


        cur.execute("""
            UPDATE mail_queue
            SET processed=1
            WHERE id=%s
        """,(job["id"],))


        conn.commit()


    except Exception as e:

        print("[MAIL QUEUE ERROR]",e)

    finally:

        if conn:
            conn.close()


def build_weekly_mail(rows, start_date, end_date):

    total_hours = 0

    html_rows = []

    for row in rows:

        total_hours += row["hours"]

        html_rows.append(f"""
        <tr>
            <td>{row['UI']}</td>
            <td>{row['name']}</td>
            <td>{row['localisation'] or ''}</td>
            <td>{row['hours']:.1f} h</td>
            <td>{row['faults']}</td>
        </tr>
        """)

    subject = (
        "[Climatisation] Rapport hebdomadaire "
        f"{start_date.strftime('%d/%m/%Y')} - "
        f"{end_date.strftime('%d/%m/%Y')}"
    )

    html = f"""
    <html>

    <body style="font-family:Arial">

    <h2>Rapport hebdomadaire Climatisation</h2>

    <p>

    <b>Période :</b><br>

    {start_date.strftime("%d/%m/%Y")}
    au
    {end_date.strftime("%d/%m/%Y")}

    </p>

    <table border="1"
    cellpadding="6"
    cellspacing="0"
    style="border-collapse:collapse;">

    <tr style="background:#f0f0f0;">

    <th>UI</th>

    <th>Nom</th>

    <th>Localisation</th>

    <th>Temps fonctionnement</th>

    <th>Défauts</th>

    </tr>

    {''.join(html_rows)}

    </table>

    <br>

    <b>Nombre d'unités :</b> {len(rows)}<br>

    <b>Temps total :</b> {total_hours:.1f} h

    </body>

    </html>
    """

        return subject, html

def check_weekly_report():

    conn = None

    try:

        now = datetime.now(LOCAL_TZ)

        #
        # Envoi uniquement lundi entre 07h00 et 07h09
        #
        if now.weekday() != 0:
            return

        if now.hour != 7:
            return

        conn = get_connection()
        cur = conn.cursor()

        week_key = now.strftime("%Y-%W")

        #
        # Déjà envoyé ?
        #
        cur.execute("""
            SELECT week_key
            FROM weekly_reports
            WHERE week_key=%s
        """, (week_key,))

        if cur.fetchone():
            return

        account = get_mail_account(cur)

        if not account:
            return

        recipients = get_recipients(cur)

        if not recipients:
            return

        #
        # semaine précédente
        #
        end_date = now.date() - timedelta(days=1)

        start_date = end_date - timedelta(days=6)

        cur.execute("""
            SELECT

                e.id,

                e.UI,

                e.name,

                e.localisation,

                SUM(h.state) AS running,

                (
                    SELECT COUNT(*)
                    FROM equipment_fault_history f
                    WHERE
                        f.equipment_id=e.id
                        AND DATE(f.created_at)
                        BETWEEN %s AND %s
                        AND f.active=1
                ) faults

            FROM equipments e

            LEFT JOIN equipment_history h

                ON h.equipment_id=e.id

            AND DATE(h.created_at)
                BETWEEN %s AND %s

            GROUP BY
                e.id,
                e.UI,
                e.name,
                e.localisation

            ORDER BY e.UI
        """, (

            start_date,
            end_date,

            start_date,
            end_date

        ))

        data = cur.fetchall()

        rows = []

        #
        # nombre de minutes entre deux acquisitions
        #
        SAMPLE_MINUTES = INTERVAL / 60

        for row in data:

            running = row["running"] or 0

            hours = running * SAMPLE_MINUTES / 60

            rows.append({

                "UI": row["UI"],

                "name": row["name"],

                "localisation": row["localisation"],

                "hours": hours,

                "faults": row["faults"] or 0

            })

        subject, html = build_weekly_mail(
            rows,
            start_date,
            end_date
        )

        send_mail(
            account,
            recipients,
            subject,
            html
        )

        cur.execute("""
            INSERT INTO weekly_reports
            (
                week_key,
                sent_at
            )
            VALUES
            (
                %s,
                NOW()
            )
        """, (
            week_key,
        ))

        conn.commit()

        print(
            "[MAIL] Rapport hebdomadaire envoyé",
            flush=True
        )

    except Exception as e:

        print(
            "[MAIL WEEKLY]",
            e,
            flush=True
        )

        traceback.print_exc()

    finally:

        if conn:
            conn.close()